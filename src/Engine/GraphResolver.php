<?php

/**
 * This file is part of Milpa Resolver — the architecture resolver for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/resolver
 */

declare(strict_types=1);

namespace Milpa\Resolver\Engine;

use Composer\Semver\Semver;
use Milpa\Resolver\Capability\RequirementLevel;
use Milpa\Resolver\Contracts\ArchitectureResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ErrorCatalog;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityProvision;

/**
 * The engine — a pure function from a materialized {@see ResolutionInput} to a {@see ResolutionReport}.
 *
 * It runs the algorithm of spec §11 pasos 2-5: resolve required contracts against the implementations
 * declared by installed packages (semver-checked, legacy-aware), resolve required and suggested
 * capabilities against the available providers (constraint-checked, honouring `oneOf` and detecting
 * `exclusive` conflicts), resolve each active surface's requirements, and classify the whole graph as
 * `valid`, `bootable_with_warnings`, `legacy_compatible`, or `blocked`. Error codes travel as plain
 * strings on the report entries; milpa/resolver's learnable-error catalog is layered on top of them.
 *
 * Purity is a hard invariant: the engine reads only its input — no filesystem, no network, no clock,
 * no randomness — so the same input always yields a byte-identical report. Every report list is sorted
 * by a total key order for that determinism.
 */
final class GraphResolver implements ArchitectureResolver
{
    private const CODE_CONTRACT_MISSING = 'MILPA_CONTRACT_MISSING';
    private const CODE_CONTRACT_VERSION_UNSUPPORTED = 'MILPA_CONTRACT_VERSION_UNSUPPORTED';
    private const CODE_CAPABILITY_MISSING = 'MILPA_CAPABILITY_MISSING';
    private const CODE_CAPABILITY_CONFLICT = 'MILPA_CAPABILITY_CONFLICT';
    private const CODE_SURFACE_REQUIREMENT_UNMET = 'MILPA_SURFACE_REQUIREMENT_UNMET';
    private const CODE_SURFACE_NOT_ENABLED = 'MILPA_SURFACE_NOT_ENABLED';
    private const CODE_LEGACY_CONTRACT_ACTIVE = 'MILPA_LEGACY_CONTRACT_ACTIVE';
    private const CODE_DEPRECATED_CONTRACT_USED = 'MILPA_DEPRECATED_CONTRACT_USED';
    private const CODE_SUGGESTED_CAPABILITY_MISSING = 'MILPA_SUGGESTED_CAPABILITY_MISSING';

    /**
     * Resolve the architecture described by the input into a report.
     */
    public function resolve(ResolutionInput $input): ResolutionReport
    {
        $host = $input->hostProfile;

        $providers = $this->collectProviders($input);
        $requireOwners = $this->requirementOwnerIndex($input->versionManifests);

        /** @var list<array<string, mixed>> $resolved */
        $resolved = [];
        /** @var list<array<string, mixed>> $missing */
        $missing = [];
        /** @var list<array<string, mixed>> $conflicts */
        $conflicts = [];
        /** @var list<array<string, mixed>> $warnings */
        $warnings = [];
        /** @var list<array<string, mixed>> $legacy */
        $legacy = [];
        /** @var list<array<string, mixed>> $migrationHints */
        $migrationHints = [];
        /** @var list<array<string, mixed>> $learnLinks */
        $learnLinks = [];

        // Paso 2 — contracts. Contract manifests matched to a resolved required contract contribute
        // their own capability requirements, suggestions, providers, surface requirements and links.
        $contractProviders = [];
        /** @var list<array{id: string, constraint: string, oneOf: list<string>, requiredBy: string}> $contractRequirements */
        $contractRequirements = [];
        /** @var list<array{id: string, requiredBy: string}> $contractSuggestions */
        $contractSuggestions = [];
        /** @var list<array{surface: string, requiredBy: string}> $contractSurfaceNeeds */
        $contractSurfaceNeeds = [];

        foreach ($this->collectContractRequirements($input) as $req) {
            $implementations = $this->contractCandidates($input->versionManifests, $req['id']);
            $satisfying = array_values(array_filter(
                $implementations,
                static fn (array $impl): bool => Semver::satisfies($impl['version'], $req['constraint']),
            ));

            if ($satisfying === []) {
                $missing[] = [
                    'kind' => 'contract',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'level' => RequirementLevel::Required->value,
                    'requiredBy' => $req['requiredBy'],
                    'surface' => null,
                    'code' => $implementations === []
                        ? self::CODE_CONTRACT_MISSING
                        : self::CODE_CONTRACT_VERSION_UNSUPPORTED,
                    'reason' => $implementations === []
                        ? sprintf('No installed package implements the contract "%s".', $req['id'])
                        : sprintf(
                            'The contract "%s" is implemented, but no implementation satisfies the constraint "%s".',
                            $req['id'],
                            $req['constraint'],
                        ),
                ];

                continue;
            }

            $nonLegacy = array_values(array_filter($satisfying, static fn (array $impl): bool => !$impl['legacy']));
            $chosen = $nonLegacy !== [] ? $nonLegacy[0] : $satisfying[0];
            $viaLegacy = $chosen['legacy'];

            $resolved[] = [
                'kind' => 'contract',
                'id' => $req['id'],
                'constraint' => $req['constraint'],
                'level' => RequirementLevel::Required->value,
                'requiredBy' => $req['requiredBy'],
                'providedBy' => $chosen['package'],
                'via' => $viaLegacy ? 'legacy' : 'direct',
            ];

            if ($viaLegacy) {
                $permitted = $this->legacyPermitted($host, $req['id']);
                $legacy[] = [
                    'kind' => 'contract',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'code' => self::CODE_LEGACY_CONTRACT_ACTIVE,
                    'providedBy' => $chosen['package'],
                    'permitted' => $permitted,
                    'reason' => sprintf(
                        'Contract "%s" is satisfied by the legacy-shaped manifest "%s".',
                        $req['id'],
                        $chosen['package'],
                    ),
                ];
                $migrationHints[] = [
                    'id' => $req['id'],
                    'from' => $chosen['version'],
                    'to' => $this->canonicalContractVersion($input->contractManifests, $req['id']),
                    'migrationUrl' => $this->contractMigrationUrl($input->contractManifests, $req['id']),
                    'message' => sprintf(
                        'Contract "%s" runs through a legacy adapter; migrate it to the canonical contract shape.',
                        $req['id'],
                    ),
                ];
            }

            // Fold the resolved contract's manifest (if declared) into the downstream passes.
            foreach ($input->contractManifests as $contract) {
                if ($contract->id !== $req['id']) {
                    continue;
                }
                $label = sprintf('contract:%s@%s', $contract->id, $contract->version);
                foreach ($contract->providesCapabilities as $capabilityId) {
                    $contractProviders[] = [
                        'id' => $capabilityId,
                        'version' => $contract->version,
                        'exclusive' => false,
                        'label' => $label,
                        'legacy' => false,
                    ];
                }
                foreach ($contract->requiresCapabilities as $capabilityId) {
                    $contractRequirements[] = ['id' => $capabilityId, 'constraint' => '*', 'oneOf' => [], 'requiredBy' => $label];
                }
                foreach ($contract->suggestsCapabilities as $capabilityId) {
                    $contractSuggestions[] = ['id' => $capabilityId, 'requiredBy' => $label];
                }
                foreach ($contract->surfaceRequirements as $surface) {
                    $contractSurfaceNeeds[] = ['surface' => $surface, 'requiredBy' => $label];
                }
                if ($contract->academyUrl !== null || $contract->migrationUrl !== null) {
                    $learnLinks[] = [
                        'id' => $contract->id,
                        'academy' => $contract->academyUrl,
                        'migration' => $contract->migrationUrl,
                    ];
                }
            }
        }

        // Contract-provided capabilities become available providers for the capability pass.
        $providers = [...$providers, ...$this->dedupeProviders($contractProviders)];

        // Paso 3 — capabilities.
        $requirements = $this->collectCapabilityRequirements($input, $requireOwners, $contractRequirements);
        foreach ($requirements as $req) {
            $candidates = array_values(array_filter(
                $providers,
                static fn (array $p): bool => $p['id'] === $req['id'] || in_array($p['id'], $req['oneOf'], true),
            ));
            $satisfying = array_values(array_filter(
                $candidates,
                static fn (array $p): bool => Semver::satisfies($p['version'], $req['constraint']),
            ));

            if ($satisfying === []) {
                $missing[] = [
                    'kind' => 'capability',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'level' => RequirementLevel::Required->value,
                    'requiredBy' => $req['requiredBy'],
                    'surface' => null,
                    'code' => $candidates === []
                        ? self::CODE_CAPABILITY_MISSING
                        : self::CODE_CONTRACT_VERSION_UNSUPPORTED,
                    'reason' => $candidates === []
                        ? sprintf('No active provider offers the capability "%s".', $req['id'])
                        : sprintf(
                            'Providers for "%s" exist, but none satisfies the constraint "%s".',
                            $req['id'],
                            $req['constraint'],
                        ),
                ];

                continue;
            }

            $chosen = $this->pickProvider($satisfying);
            $via = $chosen['legacy'] ? 'legacy' : ($chosen['id'] === $req['id'] ? 'direct' : 'oneOf');

            $resolved[] = [
                'kind' => 'capability',
                'id' => $req['id'],
                'constraint' => $req['constraint'],
                'level' => RequirementLevel::Required->value,
                'requiredBy' => $req['requiredBy'],
                'providedBy' => $chosen['label'],
                'via' => $via,
            ];

            if ($chosen['legacy']) {
                $legacy[] = [
                    'kind' => 'capability',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'code' => self::CODE_LEGACY_CONTRACT_ACTIVE,
                    'providedBy' => $chosen['label'],
                    'permitted' => $this->legacyPermitted($host, $req['id']),
                    'reason' => sprintf('Capability "%s" is provided by a legacy-shaped manifest.', $req['id']),
                ];
            }
        }

        // Suggested capabilities — a miss is a non-blocking warning.
        $suggestions = $this->collectSuggestions($input, $contractSuggestions);
        foreach ($suggestions as $sug) {
            $match = array_values(array_filter($providers, static fn (array $p): bool => $p['id'] === $sug['id']));
            if ($match !== []) {
                $chosen = $this->pickProvider($match);
                $resolved[] = [
                    'kind' => 'capability',
                    'id' => $sug['id'],
                    'constraint' => '*',
                    'level' => RequirementLevel::Suggested->value,
                    'requiredBy' => $sug['requiredBy'],
                    'providedBy' => $chosen['label'],
                    'via' => $chosen['legacy'] ? 'legacy' : 'direct',
                ];

                continue;
            }

            $warnings[] = [
                'kind' => 'suggested-capability',
                'id' => $sug['id'],
                'surface' => null,
                'code' => self::CODE_SUGGESTED_CAPABILITY_MISSING,
                'requiredBy' => $sug['requiredBy'],
                'accepted' => $this->riskAccepted($host, self::CODE_SUGGESTED_CAPABILITY_MISSING),
                'message' => sprintf('Suggested capability "%s" has no provider; its fallback path applies.', $sug['id']),
            ];
        }

        // DEUDA de T2 resolved — deprecations WIRED: a manifest-declared deprecation is a non-blocking
        // warning that teaches the migration off it (spec §7.1 deprecations, §13 code).
        foreach ($this->collectDeprecations($input) as $deprecation) {
            $warnings[] = [
                'kind' => 'deprecation',
                'id' => $deprecation['id'],
                'surface' => null,
                'code' => self::CODE_DEPRECATED_CONTRACT_USED,
                'requiredBy' => $deprecation['requiredBy'],
                'accepted' => $this->riskAccepted($host, self::CODE_DEPRECATED_CONTRACT_USED),
                'message' => sprintf(
                    'Package "%s" declares "%s" as deprecated; migrate off it before it is removed.',
                    $deprecation['requiredBy'],
                    $deprecation['id'],
                ),
            ];
        }

        // Exclusive conflicts — two distinct providers claiming the same exclusive capability id.
        foreach ($this->detectConflicts($providers) as $conflict) {
            $conflicts[] = $conflict;
        }

        // Paso 4 — surfaces.
        [$surfaceMissing, $surfaceWarnings, $surfaceResolved] = $this->resolveSurfaces(
            $input,
            $providers,
            $contractSurfaceNeeds,
        );
        $missing = [...$missing, ...$surfaceMissing];
        $warnings = [...$warnings, ...$surfaceWarnings];
        $resolved = [...$resolved, ...$surfaceResolved];

        // Deterministic ordering.
        $this->sortEntries($resolved, ['kind', 'id', 'requiredBy', 'providedBy']);
        $this->sortEntries($missing, ['kind', 'id', 'constraint', 'requiredBy']);
        $this->sortEntries($conflicts, ['id']);
        $this->sortEntries($warnings, ['kind', 'id', 'code', 'requiredBy']);
        $this->sortEntries($legacy, ['id', 'providedBy']);
        $this->sortEntries($migrationHints, ['id', 'from']);
        $this->sortEntries($learnLinks, ['id']);

        // Paso 5 — status.
        $status = $this->determineStatus($missing, $conflicts, $legacy, $warnings);

        // Attach a learnable error to every blocking entry and every catalog-coded warning (spec §12,
        // §20) — the seam is here, in the engine, because only the engine knows each entry's semantics
        // and context; the report stays a passive, deterministically-serializable holder.
        $errors = $this->attachErrors($missing, $conflicts, $warnings, $host);

        return new ResolutionReport(
            status: $status,
            resolved: $resolved,
            missing: $missing,
            conflicts: $conflicts,
            warnings: $warnings,
            legacy: $legacy,
            migrationHints: $migrationHints,
            learnLinks: $learnLinks,
            // DEUDA de T2 resolved — HostProfile.metadata WIRED: it passes through verbatim.
            metadata: [
                'hostProfile' => sprintf('%s@%s', $host->name, $host->version),
                'hostMetadata' => $host->metadata,
            ],
            errors: $errors,
        );
    }

    /**
     * Build the learnable errors for the report's agent shape: one per blocking missing/conflict entry
     * and one per catalog-coded warning. Warnings whose code has no catalog entry (e.g. an ad-hoc
     * surface warning) get none — attaching a lesson-less error would be a "dead error" (anti-pattern 4).
     *
     * @param list<array<string, mixed>> $missing
     * @param list<array<string, mixed>> $conflicts
     * @param list<array<string, mixed>> $warnings
     *
     * @return list<LearnableArchitectureError>
     */
    private function attachErrors(array $missing, array $conflicts, array $warnings, HostProfile $host): array
    {
        $hostLabel = sprintf('%s@%s', $host->name, $host->version);
        $errors = [];
        foreach ([...$missing, ...$conflicts, ...$warnings] as $entry) {
            $code = is_string($entry['code'] ?? null) ? $entry['code'] : '';
            if (!ErrorCatalog::has($code)) {
                continue;
            }
            $errors[] = ErrorCatalog::for($code, $this->errorContext($entry, $hostLabel));
        }

        return $errors;
    }

    /**
     * The context handed to the catalog for a report entry: its identifying fields, in a fixed order,
     * plus the host profile label — enough for the catalog to template the message and derive actions.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function errorContext(array $entry, string $hostLabel): array
    {
        $context = [];
        foreach (['id', 'constraint', 'surface', 'requiredBy', 'providedBy'] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null) {
                $context[$key] = $entry[$key];
            }
        }
        $context['hostProfile'] = $hostLabel;

        return $context;
    }

    /**
     * Collect the deprecations every version manifest declares, attributed to its package label.
     *
     * @return list<array{id: string, requiredBy: string}>
     */
    private function collectDeprecations(ResolutionInput $input): array
    {
        $out = [];
        $seen = [];
        foreach ($input->versionManifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($manifest->deprecations as $entry) {
                $id = $this->entryId($entry);
                if ($id === null) {
                    continue;
                }
                $key = $id . "\0" . $package;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['id' => $id, 'requiredBy' => $package];
            }
        }

        return $out;
    }

    /**
     * Build the provider set: the typed provisions plus every provider synthesised from a version
     * manifest's `capabilities.provides`, each tagged with whether its manifest is legacy-shaped.
     *
     * @return list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}>
     */
    private function collectProviders(ResolutionInput $input): array
    {
        $providers = [];

        foreach ($input->capabilityProvisions as $provision) {
            $providers[] = [
                'id' => $provision->id,
                'version' => $provision->contractVersion,
                'exclusive' => $provision->exclusive,
                'label' => $provision->service ?? sprintf('%s@%s', $provision->id, $provision->contractVersion),
                'legacy' => false,
            ];
        }

        foreach ($input->versionManifests as $manifest) {
            $legacy = $this->isLegacy($manifest);
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->capabilities, 'provides') as $entry) {
                if (!is_string($entry) && !is_array($entry)) {
                    continue;
                }
                $provision = CapabilityProvision::parse($entry);
                $providers[] = [
                    'id' => $provision->id,
                    'version' => $provision->contractVersion,
                    'exclusive' => $provision->exclusive,
                    'label' => $provision->service ?? $package,
                    'legacy' => $legacy,
                ];
            }
        }

        return $this->dedupeProviders($providers);
    }

    /**
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}> $providers
     *
     * @return list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}>
     */
    private function dedupeProviders(array $providers): array
    {
        $seen = [];
        $out = [];
        foreach ($providers as $provider) {
            $key = $provider['id'] . "\0" . $provider['version'] . "\0" . $provider['label'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $provider;
        }

        return $out;
    }

    /**
     * Map each capability id a manifest declares in `capabilities.requires` to that manifest's label,
     * so a typed requirement can be attributed to the package that declares it.
     *
     * @param list<VersionManifest> $manifests
     *
     * @return array<string, string>
     */
    private function requirementOwnerIndex(array $manifests): array
    {
        $index = [];
        foreach ($manifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->capabilities, 'requires') as $entry) {
                $id = $this->entryId($entry);
                if ($id !== null && !isset($index[$id])) {
                    $index[$id] = $package;
                }
            }
        }

        return $index;
    }

    /**
     * Required contracts: the host profile's, plus every version manifest's `contracts.requires`.
     *
     * @return list<array{id: string, constraint: string, requiredBy: string}>
     */
    private function collectContractRequirements(ResolutionInput $input): array
    {
        $host = $input->hostProfile;
        $out = [];
        $seen = [];

        foreach ($host->requiredContracts as $entry) {
            [$id, $constraint] = $this->splitConstraint($entry);
            $out[] = $this->dedupeContractRequirement($seen, $id, $constraint, $this->hostLabel($host));
        }

        foreach ($input->versionManifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->contracts, 'requires') as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                [$id, $constraint] = $this->splitConstraint($entry);
                $out[] = $this->dedupeContractRequirement($seen, $id, $constraint, $package);
            }
        }

        return array_values(array_filter($out, static fn (?array $req): bool => $req !== null));
    }

    /**
     * @param array<string, bool> $seen
     *
     * @return array{id: string, constraint: string, requiredBy: string}|null
     */
    private function dedupeContractRequirement(array &$seen, string $id, string $constraint, string $requiredBy): ?array
    {
        $key = $id . "\0" . $constraint . "\0" . $requiredBy;
        if (isset($seen[$key])) {
            return null;
        }
        $seen[$key] = true;

        return ['id' => $id, 'constraint' => $constraint, 'requiredBy' => $requiredBy];
    }

    /**
     * Every contract implementation the installed packages declare, parsed from `contracts.implements`.
     *
     * @param list<VersionManifest> $manifests
     *
     * @return list<array{id: string, version: string, legacy: bool, package: string}>
     */
    private function contractCandidates(array $manifests, string $contractId): array
    {
        $out = [];
        foreach ($manifests as $manifest) {
            $legacy = $this->isLegacy($manifest);
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->contracts, 'implements') as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                [$id, $version] = $this->splitImplementation($entry);
                if ($id === $contractId) {
                    $out[] = ['id' => $id, 'version' => $version, 'legacy' => $legacy, 'package' => $package];
                }
            }
        }

        return $out;
    }

    /**
     * Required capabilities: the host profile's, the typed requirements (attributed to their declaring
     * package where known), and the capabilities the resolved contracts require.
     *
     * @param array<string, string>                                                                $owners
     * @param list<array{id: string, constraint: string, oneOf: list<string>, requiredBy: string}> $contractRequirements
     *
     * @return list<array{id: string, constraint: string, oneOf: list<string>, requiredBy: string}>
     */
    private function collectCapabilityRequirements(ResolutionInput $input, array $owners, array $contractRequirements): array
    {
        $host = $input->hostProfile;
        $out = [];
        $seen = [];

        $add = function (string $id, string $constraint, array $oneOf, string $requiredBy) use (&$out, &$seen): void {
            /** @var list<string> $oneOf */
            $key = $id . "\0" . $constraint . "\0" . $requiredBy;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['id' => $id, 'constraint' => $constraint, 'oneOf' => $oneOf, 'requiredBy' => $requiredBy];
        };

        foreach ($host->requiredCapabilities as $entry) {
            [$id, $constraint] = $this->splitConstraint($entry);
            $add($id, $constraint, [], $this->hostLabel($host));
        }

        foreach ($input->capabilityRequirements as $requirement) {
            $add(
                $requirement->id,
                $requirement->constraint,
                $requirement->oneOf,
                $owners[$requirement->id] ?? 'input',
            );
        }

        foreach ($contractRequirements as $requirement) {
            $add($requirement['id'], $requirement['constraint'], $requirement['oneOf'], $requirement['requiredBy']);
        }

        return $out;
    }

    /**
     * Suggested capabilities: every manifest's `capabilities.suggests` plus the resolved contracts'.
     *
     * @param list<array{id: string, requiredBy: string}> $contractSuggestions
     *
     * @return list<array{id: string, requiredBy: string}>
     */
    private function collectSuggestions(ResolutionInput $input, array $contractSuggestions): array
    {
        $out = [];
        $seen = [];

        $add = function (string $id, string $requiredBy) use (&$out, &$seen): void {
            $key = $id . "\0" . $requiredBy;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['id' => $id, 'requiredBy' => $requiredBy];
        };

        foreach ($input->versionManifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->capabilities, 'suggests') as $entry) {
                $id = $this->entryId($entry);
                if ($id !== null) {
                    $add($id, $package);
                }
            }
        }

        foreach ($contractSuggestions as $suggestion) {
            $add($suggestion['id'], $suggestion['requiredBy']);
        }

        return $out;
    }

    /**
     * Detect exclusive conflicts: an id claimed by two or more distinct providers where at least one
     * marks the capability exclusive.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}> $providers
     *
     * @return list<array<string, mixed>>
     */
    private function detectConflicts(array $providers): array
    {
        /** @var array<string, array{exclusive: bool, labels: list<string>}> $byId */
        $byId = [];
        foreach ($providers as $provider) {
            $id = $provider['id'];
            if (!isset($byId[$id])) {
                $byId[$id] = ['exclusive' => false, 'labels' => []];
            }
            $byId[$id]['exclusive'] = $byId[$id]['exclusive'] || $provider['exclusive'];
            if (!in_array($provider['label'], $byId[$id]['labels'], true)) {
                $byId[$id]['labels'][] = $provider['label'];
            }
        }

        $out = [];
        foreach ($byId as $id => $group) {
            if (!$group['exclusive'] || count($group['labels']) < 2) {
                continue;
            }
            $labels = $group['labels'];
            sort($labels);
            $out[] = [
                'kind' => 'capability',
                'id' => $id,
                'code' => self::CODE_CAPABILITY_CONFLICT,
                'providedBy' => $labels,
                'reason' => sprintf('Multiple providers claim the exclusive capability "%s".', $id),
            ];
        }

        return $out;
    }

    /**
     * Resolve each active surface's capability requirements (from the surface definitions carried in
     * `environment.surfaces`) and carry its declared warnings; contract-declared surface needs that the
     * host has not enabled become non-blocking warnings.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}> $providers
     * @param list<array{surface: string, requiredBy: string}>                                       $contractSurfaceNeeds
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function resolveSurfaces(ResolutionInput $input, array $providers, array $contractSurfaceNeeds): array
    {
        $host = $input->hostProfile;
        $active = $this->activeSurfaces($input);
        $definitions = $this->surfaceDefinitions($input);
        $supports = $this->surfaceSupportIndex($input->versionManifests);

        $missing = [];
        $warnings = [];
        $resolved = [];

        foreach ($active as $surface) {
            $definition = $definitions[$surface] ?? ['requires' => [], 'warnings' => []];
            $closed = true;

            foreach ($definition['requires'] as $capabilityId) {
                $provided = array_filter(
                    $providers,
                    static fn (array $p): bool => $p['id'] === $capabilityId,
                );
                if ($provided === []) {
                    $closed = false;
                    $missing[] = [
                        'kind' => 'surface-requirement',
                        'id' => $capabilityId,
                        'constraint' => '*',
                        'level' => RequirementLevel::Required->value,
                        'requiredBy' => sprintf('surface:%s', $surface),
                        'surface' => $surface,
                        'code' => self::CODE_SURFACE_REQUIREMENT_UNMET,
                        'reason' => sprintf(
                            'Active surface "%s" requires the capability "%s", which no provider offers.',
                            $surface,
                            $capabilityId,
                        ),
                    ];
                }
            }

            foreach ($definition['warnings'] as $warning) {
                $code = is_string($warning['code'] ?? null) ? $warning['code'] : 'HTTP_SURFACE_WARNING';
                $message = is_string($warning['message'] ?? null) ? $warning['message'] : '';
                $warnings[] = [
                    'kind' => 'surface',
                    'id' => $surface,
                    'surface' => $surface,
                    'code' => $code,
                    'requiredBy' => sprintf('surface:%s', $surface),
                    'accepted' => $this->riskAccepted($host, $code),
                    'message' => $message,
                ];
            }

            if ($closed) {
                $resolved[] = [
                    'kind' => 'surface',
                    'id' => $surface,
                    'constraint' => '*',
                    'level' => RequirementLevel::Required->value,
                    'requiredBy' => $this->hostLabel($host),
                    'providedBy' => $supports[$surface] ?? 'host',
                    'via' => 'direct',
                ];
            }
        }

        foreach ($contractSurfaceNeeds as $need) {
            if (in_array($need['surface'], $active, true)) {
                continue;
            }
            $warnings[] = [
                'kind' => 'surface',
                'id' => $need['surface'],
                'surface' => $need['surface'],
                'code' => self::CODE_SURFACE_NOT_ENABLED,
                'requiredBy' => $need['requiredBy'],
                'accepted' => $this->riskAccepted($host, self::CODE_SURFACE_NOT_ENABLED),
                'message' => sprintf(
                    'Contract %s expects surface "%s", which the host has not enabled.',
                    $need['requiredBy'],
                    $need['surface'],
                ),
            ];
        }

        return [$missing, $warnings, $resolved];
    }

    /**
     * The surfaces to check: the runtime-active set unioned with the host's enabled surfaces.
     *
     * @return list<string>
     */
    private function activeSurfaces(ResolutionInput $input): array
    {
        $active = [];
        foreach ([...$input->activeSurfaces, ...$input->hostProfile->enabledSurfaces] as $surface) {
            if (!in_array($surface, $active, true)) {
                $active[] = $surface;
            }
        }
        sort($active);

        return $active;
    }

    /**
     * Surface definitions carried in `environment.surfaces` (spec §18): each names the capabilities the
     * surface requires and the warnings it declares.
     *
     * @return array<string, array{requires: list<string>, warnings: list<array<string, mixed>>}>
     */
    private function surfaceDefinitions(ResolutionInput $input): array
    {
        $raw = $input->environment['surfaces'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $surface = $definition['surface'] ?? null;
            if (!is_string($surface) || $surface === '') {
                continue;
            }

            $requires = [];
            foreach ($this->rawList($definition, 'requires') as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $requires[] = $entry;
                }
            }

            $warnings = [];
            foreach ($this->rawList($definition, 'warnings') as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, mixed> $entry */
                    $warnings[] = $entry;
                }
            }

            $out[$surface] = ['requires' => $requires, 'warnings' => $warnings];
        }

        return $out;
    }

    /**
     * Map each surface to the first version manifest that declares support for it (`surfaces.supports`).
     *
     * @param list<VersionManifest> $manifests
     *
     * @return array<string, string>
     */
    private function surfaceSupportIndex(array $manifests): array
    {
        $index = [];
        foreach ($manifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->surfaces, 'supports') as $entry) {
                if (is_string($entry) && $entry !== '' && !isset($index[$entry])) {
                    $index[$entry] = $package;
                }
            }
        }

        return $index;
    }

    /**
     * Classify the graph (spec §11 paso 5): a missing required item or a conflict blocks; otherwise a
     * legacy dependency in use degrades to legacy-compatible; otherwise an unaccepted warning degrades
     * to bootable-with-warnings; otherwise the graph is valid.
     *
     * @param list<array<string, mixed>> $missing
     * @param list<array<string, mixed>> $conflicts
     * @param list<array<string, mixed>> $legacy
     * @param list<array<string, mixed>> $warnings
     */
    private function determineStatus(array $missing, array $conflicts, array $legacy, array $warnings): ResolutionStatus
    {
        if ($missing !== [] || $conflicts !== []) {
            return ResolutionStatus::Blocked;
        }

        if ($legacy !== []) {
            return ResolutionStatus::LegacyCompatible;
        }

        foreach ($warnings as $warning) {
            if (($warning['accepted'] ?? false) !== true) {
                return ResolutionStatus::BootableWithWarnings;
            }
        }

        return ResolutionStatus::Valid;
    }

    /**
     * Pick one provider from a set deterministically: prefer non-legacy, then higher priority (none
     * here — providers carry no priority in the resolved shape), then the lexicographically first label.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool}> $candidates
     *
     * @return array{id: string, version: string, exclusive: bool, label: string, legacy: bool}
     */
    private function pickProvider(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            return [$a['legacy'] ? 1 : 0, $a['label']] <=> [$b['legacy'] ? 1 : 0, $b['label']];
        });

        return $candidates[0];
    }

    private function isLegacy(VersionManifest $manifest): bool
    {
        return ($manifest->metadata['shape'] ?? null) === 'legacy-contracts';
    }

    private function legacyPermitted(HostProfile $host, string $contractId): bool
    {
        return in_array('*', $host->allowedLegacyContracts, true)
            || in_array($contractId, $host->allowedLegacyContracts, true);
    }

    private function riskAccepted(HostProfile $host, string $code): bool
    {
        return in_array($code, $host->acceptedRisks, true);
    }

    private function hostLabel(HostProfile $host): string
    {
        return sprintf('hostProfile:%s@%s', $host->name, $host->version);
    }

    /**
     * @param list<ContractManifest> $contractManifests
     */
    private function canonicalContractVersion(array $contractManifests, string $contractId): ?string
    {
        foreach ($contractManifests as $contract) {
            if ($contract->id === $contractId) {
                return $contract->version;
            }
        }

        return null;
    }

    /**
     * @param list<ContractManifest> $contractManifests
     */
    private function contractMigrationUrl(array $contractManifests, string $contractId): ?string
    {
        foreach ($contractManifests as $contract) {
            if ($contract->id === $contractId) {
                return $contract->migrationUrl;
            }
        }

        return null;
    }

    /**
     * Split an `id@constraint` string; a bare id defaults the constraint to `*`.
     *
     * @return array{0: string, 1: string}
     */
    private function splitConstraint(string $entry): array
    {
        $at = strpos($entry, '@');
        if ($at === false) {
            return [trim($entry), '*'];
        }

        $id = trim(substr($entry, 0, $at));
        $constraint = trim(substr($entry, $at + 1));

        return [$id, $constraint === '' ? '*' : $constraint];
    }

    /**
     * Split an `id@version` implementation string; a bare id defaults the version to `0.0.0`.
     *
     * @return array{0: string, 1: string}
     */
    private function splitImplementation(string $entry): array
    {
        $at = strpos($entry, '@');
        if ($at === false) {
            return [trim($entry), '0.0.0'];
        }

        $id = trim(substr($entry, 0, $at));
        $version = trim(substr($entry, $at + 1));

        return [$id, $version === '' ? '0.0.0' : $version];
    }

    /**
     * The id of a capability manifest entry: the string itself for a bare FQCN, or the `id` key for a
     * structured record.
     */
    private function entryId(mixed $entry): ?string
    {
        if (is_string($entry)) {
            $entry = trim($entry);

            return $entry === '' ? null : $entry;
        }
        if (is_array($entry) && isset($entry['id']) && is_scalar($entry['id'])) {
            $id = trim((string) $entry['id']);

            return $id === '' ? null : $id;
        }

        return null;
    }

    /**
     * Read a nested list under $key from a manifest sub-map, tolerating a missing or non-array value.
     *
     * @param array<string, mixed> $map
     *
     * @return list<mixed>
     */
    private function rawList(array $map, string $key): array
    {
        $value = $map[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Sort a list of report entries in place by the given keys, using a total, deterministic order.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $keys
     */
    private function sortEntries(array &$entries, array $keys): void
    {
        usort($entries, function (array $a, array $b) use ($keys): int {
            foreach ($keys as $key) {
                $cmp = strcmp($this->scalarKey($a[$key] ?? null), $this->scalarKey($b[$key] ?? null));
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });
    }

    private function scalarKey(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
