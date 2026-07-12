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
 * capabilities against the available providers (constraint-checked, priority-ordered per spec §3.1,
 * honouring `oneOf` and detecting `exclusive` conflicts), resolve each active surface's requirements,
 * and classify the whole graph as
 * `valid`, `bootable_with_warnings`, `legacy_compatible`, or `blocked`. Error codes travel as plain
 * strings on the report entries; milpa/resolver's learnable-error catalog is layered on top of them.
 *
 * The report also ORDERS the boot: `loadOrder[]` is the topological sort of the version manifests
 * (Kahn's algorithm, absorbed from the legacy `Milpa\Plugin\ContractResolver`) over the exact-string
 * capability/contract ids they provide and require. A dependency cycle has no possible boot order —
 * nobody can go first — so it blocks as a learnable `conflicts[]` entry (`MILPA_DEPENDENCY_CYCLE`)
 * instead of throwing.
 *
 * Purity is a hard invariant: the engine reads only its input — no filesystem, no network, no clock,
 * no randomness — so the same input always yields a byte-identical report. Every report list is sorted
 * by a total key order for that determinism — except `loadOrder[]`, whose order is the payload itself
 * (still deterministic: a pure function of the input order).
 */
final class GraphResolver implements ArchitectureResolver
{
    private const CODE_CONTRACT_MISSING = 'MILPA_CONTRACT_MISSING';
    private const CODE_CONTRACT_VERSION_UNSUPPORTED = 'MILPA_CONTRACT_VERSION_UNSUPPORTED';
    private const CODE_CAPABILITY_MISSING = 'MILPA_CAPABILITY_MISSING';
    private const CODE_CAPABILITY_VERSION_UNSUPPORTED = 'MILPA_CAPABILITY_VERSION_UNSUPPORTED';
    private const CODE_CAPABILITY_CONFLICT = 'MILPA_CAPABILITY_CONFLICT';
    private const CODE_SURFACE_REQUIREMENT_UNMET = 'MILPA_SURFACE_REQUIREMENT_UNMET';
    private const CODE_SURFACE_NOT_ENABLED = 'MILPA_SURFACE_NOT_ENABLED';
    private const CODE_LEGACY_CONTRACT_ACTIVE = 'MILPA_LEGACY_CONTRACT_ACTIVE';
    private const CODE_LEGACY_NOT_ALLOWED = 'MILPA_LEGACY_NOT_ALLOWED';
    private const CODE_DEPRECATED_CONTRACT_USED = 'MILPA_DEPRECATED_CONTRACT_USED';
    private const CODE_SUGGESTED_CAPABILITY_MISSING = 'MILPA_SUGGESTED_CAPABILITY_MISSING';
    private const CODE_RISK_EXPIRY_UNEVALUATED = 'MILPA_RISK_EXPIRY_UNEVALUATED';
    private const CODE_DEPENDENCY_CYCLE = 'MILPA_DEPENDENCY_CYCLE';

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
                if (!$permitted) {
                    $missing[] = $this->legacyNotAllowed('contract', $req['id'], $chosen['package'], $host);
                }
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
                        'priority' => 0,
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

        // Paso 3 — capabilities. A requirement whose oneOf alternatives are ALL exhausted keeps the
        // frozen missing[] entry shape; the alternatives it tried — and, for a version miss, WHICH
        // candidates exist only out of range — ride into the learnable error's context via this side
        // index (keyed exactly like the requirement dedupe key), never onto the report entry itself.
        /** @var array<string, array{alternatives: list<string>, provided: list<string>}> $oneOfMissed */
        $oneOfMissed = [];
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
                if ($req['oneOf'] !== []) {
                    $providedIds = array_values(array_unique(array_column($candidates, 'id')));
                    sort($providedIds);
                    $oneOfMissed[$req['id'] . "\0" . $req['constraint'] . "\0" . $req['requiredBy']] = [
                        'alternatives' => $req['oneOf'],
                        'provided' => $providedIds,
                    ];
                }
                $missing[] = [
                    'kind' => 'capability',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'level' => RequirementLevel::Required->value,
                    'requiredBy' => $req['requiredBy'],
                    'surface' => null,
                    // A capability provided only OUT of the consumer's range is a capability-side
                    // version miss — its own code, no longer recycling the contract version code.
                    'code' => $candidates === []
                        ? self::CODE_CAPABILITY_MISSING
                        : self::CODE_CAPABILITY_VERSION_UNSUPPORTED,
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
                $permitted = $this->legacyPermitted($host, $req['id']);
                $legacy[] = [
                    'kind' => 'capability',
                    'id' => $req['id'],
                    'constraint' => $req['constraint'],
                    'code' => self::CODE_LEGACY_CONTRACT_ACTIVE,
                    'providedBy' => $chosen['label'],
                    'permitted' => $permitted,
                    'reason' => sprintf('Capability "%s" is provided by a legacy-shaped manifest.', $req['id']),
                ];
                if (!$permitted) {
                    $missing[] = $this->legacyNotAllowed('capability', $req['id'], $chosen['label'], $host);
                }
                // A legacy capability teaches its own migration, exactly as a legacy contract does: one
                // hint per entry, targeting the canonical `capabilities.*` shape. Emitted whenever the
                // path is legacy-shaped (permitted or not), mirroring the contract branch — an
                // un-permitted path is still worth telling the reader how to get off legacy.
                $migrationHints[] = [
                    'id' => $req['id'],
                    'from' => $chosen['version'],
                    'to' => 'capabilities.*',
                    'migrationUrl' => null,
                    'message' => sprintf(
                        'Capability "%s" is provided by a legacy-shaped manifest; migrate it to the canonical capabilities.* shape.',
                        $req['id'],
                    ),
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

            // The degradation is visible: a suggestion record that declares `fallback` carries it on
            // the entry and names the path in the message; a legacy bare-FQCN suggestion has none.
            $warnings[] = [
                'kind' => 'suggested-capability',
                'id' => $sug['id'],
                'surface' => null,
                'code' => self::CODE_SUGGESTED_CAPABILITY_MISSING,
                'requiredBy' => $sug['requiredBy'],
                'fallback' => $sug['fallback'],
                'message' => $sug['fallback'] === null
                    ? sprintf('Suggested capability "%s" has no provider; its fallback path applies.', $sug['id'])
                    : sprintf(
                        'Suggested capability "%s" has no provider; its fallback path applies: degrades to "%s".',
                        $sug['id'],
                        $sug['fallback'],
                    ),
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
                'fallback' => null,
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

        // Fold the host's accepted risks into the warnings (with reason + expiry, evaluated against the
        // caller's clock). Accepting a risk keeps its warning visible but stops it degrading the status
        // — unless the acceptance has expired, or its expiry could not be checked for want of a clock.
        $warnings = $this->applyAcceptedRisks($warnings, $host, $input->evaluatedAt);

        // The boot order — the Kahn pass absorbed from the legacy Milpa\Plugin\ContractResolver. A
        // dependency cycle is not an exception here: it joins conflicts[] as a blocking, learnable
        // entry (the status truth table below is untouched — conflicts !== [] already blocks).
        [$loadOrder, $cycleConflicts] = $this->computeLoadOrder($input->versionManifests);
        $conflicts = [...$conflicts, ...$cycleConflicts];

        // Deterministic ordering. loadOrder[] is deliberately EXEMPT: its order IS the payload — the
        // boot sequence the graph dictates — and it is already deterministic as a pure function of
        // the input order. Every other list is sorted by a total key order.
        $this->sortEntries($resolved, ['kind', 'id', 'requiredBy', 'providedBy']);
        $this->sortEntries($missing, ['kind', 'id', 'constraint', 'requiredBy']);
        $this->sortEntries($conflicts, ['id']);
        $this->sortEntries($warnings, ['kind', 'id', 'code', 'requiredBy']);
        $this->sortEntries($legacy, ['id', 'providedBy']);
        $this->sortEntries($migrationHints, ['id', 'from']);
        $this->sortEntries($learnLinks, ['id']);

        // Paso 5 — status.
        $status = $this->determineStatus($missing, $conflicts, $legacy, $warnings);

        // Attach a learnable error to every blocking entry, every catalog-coded warning, and every
        // PERMITTED legacy path (spec §12, §20) — the seam is here, in the engine, because only the
        // engine knows each entry's semantics and context; the report stays a passive,
        // deterministically-serializable holder.
        $errors = $this->attachErrors($missing, $conflicts, $warnings, $legacy, $host, $oneOfMissed);

        return new ResolutionReport(
            status: $status,
            resolved: $resolved,
            loadOrder: $loadOrder,
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
     * Build the learnable errors for the report's agent shape: one per blocking missing/conflict entry,
     * one per catalog-coded warning, and one per PERMITTED legacy path. Warnings whose code has no
     * catalog entry (e.g. an ad-hoc surface warning) get none — attaching a lesson-less error would be a
     * "dead error" (anti-pattern 4).
     *
     * The legacy attachment is scoped to PERMITTED entries. A permitted legacy path degrades to
     * legacy_compatible and is genuinely active, so its `MILPA_LEGACY_CONTRACT_ACTIVE` lesson ("allowed,
     * but never silent") belongs in errors[]. An un-permitted legacy path is BLOCKED, not active: it
     * already teaches through its `missing[]` twin (`MILPA_LEGACY_NOT_ALLOWED`), so re-attaching an
     * "active" lesson would be both a duplicate for the same underlying entry and a contradiction.
     *
     * @param list<array<string, mixed>>                                               $missing
     * @param list<array<string, mixed>>                                               $conflicts
     * @param list<array<string, mixed>>                                               $warnings
     * @param list<array<string, mixed>>                                               $legacy
     * @param array<string, array{alternatives: list<string>, provided: list<string>}> $oneOfMissed
     *
     * @return list<LearnableArchitectureError>
     */
    private function attachErrors(array $missing, array $conflicts, array $warnings, array $legacy, HostProfile $host, array $oneOfMissed): array
    {
        $hostLabel = sprintf('%s@%s', $host->name, $host->version);
        $permittedLegacy = array_values(array_filter(
            $legacy,
            static fn (array $entry): bool => ($entry['permitted'] ?? false) === true,
        ));

        $errors = [];
        foreach ([...$missing, ...$conflicts, ...$warnings, ...$permittedLegacy] as $entry) {
            $code = is_string($entry['code'] ?? null) ? $entry['code'] : '';
            if (!ErrorCatalog::has($code)) {
                continue;
            }
            $errors[] = ErrorCatalog::for($code, $this->errorContext($entry, $hostLabel, $oneOfMissed));
        }

        return $errors;
    }

    /**
     * The context handed to the catalog for a report entry: its identifying fields in a FIXED key
     * order — `id`, `constraint`, `oneOf`, `surface`, `requiredBy`, `providedBy`, `fallback`,
     * `hostProfile` — enough for the catalog to template the message and derive actions. A key with
     * nothing to say is omitted, never null: `oneOf` joins only for a missed CAPABILITY requirement
     * that declared alternatives (looked up by the requirement's identity — id/constraint/requiredBy
     * — so the frozen missing[] entry shape never carries it), and `fallback` only rides on a
     * suggested-capability warning whose record declared one. The order is deliberate and stable:
     * the requirement identity first (id, constraint, and the oneOf alternatives that widen it),
     * then where (surface), then the two parties (requiredBy, providedBy), then the degradation
     * path, and the host label last. `providedBy` carries the entry's own value when it has one
     * (a conflict's candidate list, a legacy path's provider); for a version-missed oneOf
     * requirement — where the entry carries none — it names the candidate ids that exist only OUT
     * of the consumer's range, so the error can say WHICH candidate fell out instead of implying
     * the primary id "is provided".
     *
     * @param array<string, mixed>                                                     $entry
     * @param array<string, array{alternatives: list<string>, provided: list<string>}> $oneOfMissed
     *
     * @return array<string, mixed>
     */
    private function errorContext(array $entry, string $hostLabel, array $oneOfMissed): array
    {
        $context = [];
        foreach (['id', 'constraint'] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null) {
                $context[$key] = $entry[$key];
            }
        }

        $miss = null;
        if (($entry['kind'] ?? null) === 'capability'
            && is_string($entry['id'] ?? null)
            && is_string($entry['constraint'] ?? null)
            && is_string($entry['requiredBy'] ?? null)
        ) {
            $miss = $oneOfMissed[$entry['id'] . "\0" . $entry['constraint'] . "\0" . $entry['requiredBy']] ?? null;
        }
        if ($miss !== null) {
            $context['oneOf'] = $miss['alternatives'];
        }

        foreach (['surface', 'requiredBy'] as $key) {
            if (array_key_exists($key, $entry) && $entry[$key] !== null) {
                $context[$key] = $entry[$key];
            }
        }

        if (array_key_exists('providedBy', $entry) && $entry['providedBy'] !== null) {
            $context['providedBy'] = $entry['providedBy'];
        } elseif ($miss !== null && $miss['provided'] !== []) {
            $context['providedBy'] = $miss['provided'];
        }

        if (array_key_exists('fallback', $entry) && $entry['fallback'] !== null) {
            $context['fallback'] = $entry['fallback'];
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
     * manifest's `capabilities.provides`, each tagged with whether its manifest is legacy-shaped and
     * carrying its declared `priority` (absent = 0) — the field {@see pickProvider()} orders by.
     *
     * @return list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}>
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
                'priority' => $provision->priority,
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
                    'priority' => $provision->priority,
                ];
            }
        }

        return $this->dedupeProviders($providers);
    }

    /**
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}> $providers
     *
     * @return list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}>
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
     * Each carries the `fallback` its suggestion record declares (the graceful-degradation path of
     * {@see \Milpa\ValueObjects\Capability\CapabilitySuggestion}) — `null` for a legacy bare-FQCN
     * suggestion and for contract-declared suggestions, which are plain ids.
     *
     * @param list<array{id: string, requiredBy: string}> $contractSuggestions
     *
     * @return list<array{id: string, requiredBy: string, fallback: string|null}>
     */
    private function collectSuggestions(ResolutionInput $input, array $contractSuggestions): array
    {
        $out = [];
        $seen = [];

        $add = function (string $id, string $requiredBy, ?string $fallback) use (&$out, &$seen): void {
            $key = $id . "\0" . $requiredBy;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = ['id' => $id, 'requiredBy' => $requiredBy, 'fallback' => $fallback];
        };

        foreach ($input->versionManifests as $manifest) {
            $package = sprintf('%s@%s', $manifest->package, $manifest->version);
            foreach ($this->rawList($manifest->capabilities, 'suggests') as $entry) {
                $id = $this->entryId($entry);
                if ($id !== null) {
                    $add($id, $package, $this->entryFallback($entry));
                }
            }
        }

        foreach ($contractSuggestions as $suggestion) {
            $add($suggestion['id'], $suggestion['requiredBy'], null);
        }

        return $out;
    }

    /**
     * The declared fallback of a `suggests` entry: the `fallback` key of a structured record,
     * TRIMMED, with a blank value meaning none. Deliberately stricter than
     * {@see \Milpa\ValueObjects\Capability\CapabilitySuggestion::fromArray()}, which keeps the
     * string verbatim: the engine trims so a whitespace-only fallback can never surface as a blank
     * degradation path on a warning or in a message (the trim is pinned in the suite). A legacy
     * bare-FQCN string declares no fallback.
     */
    private function entryFallback(mixed $entry): ?string
    {
        if (!is_array($entry) || !isset($entry['fallback']) || !is_scalar($entry['fallback'])) {
            return null;
        }
        $fallback = trim((string) $entry['fallback']);

        return $fallback === '' ? null : $fallback;
    }

    /**
     * Detect exclusive conflicts: an id claimed by two or more distinct providers where at least one
     * marks the capability exclusive. Priority never rescues an exclusive conflict — exclusivity is a
     * claim about the id, not a tie for `pickProvider()` to break.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}> $providers
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
     * Compute the boot order of the version manifests — Kahn's algorithm absorbed from the legacy
     * `Milpa\Plugin\ContractResolver::getLoadOrder()`, its semantics replicated exactly over the
     * exact-string capability/contract ids the engine already matches:
     *
     *  - the id→provider map follows {@see pickProvider()}'s winner where `priority` is in play: the
     *    highest-priority provider of an id is the edge source, so a dependent boots after the
     *    provider that actually satisfies it (the selection/ordering consistency invariant). On a
     *    priority TIE — including the no-priority case, where every provider sits at 0 — the LAST
     *    provider in input order silently wins as the edge source, byte-identical to the legacy
     *    behaviour (a duplicated non-exclusive provider, documented);
     *  - an edge provider→dependent exists only when a provider for the required id exists (a
     *    requirement nobody provides never bends the order — the miss already lives in missing[]);
     *  - a self-dependency is skipped;
     *  - the queue is seeded iterating the manifests in their GIVEN order and consumed FIFO, so
     *    packages with no edges between them boot in the exact order the host configured;
     *  - the leftover nodes of an unfinished sort are a dependency cycle: they are excluded from the
     *    order and reported as ONE blocking conflicts[] entry (`kind: dependency-cycle`) whose `id`
     *    joins the member names (lexicographic) with ' <-> ' and whose `providedBy` carries each
     *    member's name@version identity — the same frozen conflict key-set, no new keys.
     *
     * @param list<VersionManifest> $manifests
     *
     * @return array{0: list<array{name: string, version: string}>, 1: list<array<string, mixed>>}
     */
    private function computeLoadOrder(array $manifests): array
    {
        /** @var array<string, VersionManifest> $byName */
        $byName = [];
        foreach ($manifests as $manifest) {
            $byName[$manifest->package] = $manifest;
        }

        // id → providing package name. The highest declared priority takes the edge (the same winner
        // pickProvider() selects); on a tie — the no-priority case included, everything at 0 —
        // iterating in input order means the LAST provider wins, exactly as the legacy resolver did.
        /** @var array<string, string> $providerById */
        $providerById = [];
        /** @var array<string, int> $providerPriority */
        $providerPriority = [];
        foreach ($manifests as $manifest) {
            foreach ($this->providedIds($manifest) as ['id' => $id, 'priority' => $priority]) {
                if (isset($providerById[$id]) && $priority < $providerPriority[$id]) {
                    continue;
                }
                $providerById[$id] = $manifest->package;
                $providerPriority[$id] = $priority;
            }
        }

        /** @var array<string, list<string>> $graph */
        $graph = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];
        foreach ($byName as $name => $manifest) {
            $graph[$name] = [];
            $inDegree[$name] = 0;
        }

        foreach ($manifests as $manifest) {
            $name = $manifest->package;
            foreach ($this->requiredIds($manifest) as $id) {
                if (!isset($providerById[$id])) {
                    continue;
                }
                $dependsOn = $providerById[$id];
                if ($dependsOn === $name) {
                    continue;
                }
                $graph[$dependsOn][] = $name;
                ++$inDegree[$name];
            }
        }

        // Kahn: seed the queue in input order, consume FIFO.
        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $loadOrder = [];
        $sortedNames = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sortedNames[$current] = true;
            $loadOrder[] = ['name' => $current, 'version' => $byName[$current]->version];
            foreach ($graph[$current] as $dependent) {
                --$inDegree[$dependent];
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($loadOrder) === count($byName)) {
            return [$loadOrder, []];
        }

        // Leftover nodes never reached in-degree zero: a dependency cycle (plus anything riding on
        // it). No boot order exists for them — nobody can go first.
        $members = array_values(array_diff(array_keys($byName), array_keys($sortedNames)));
        sort($members);
        $labels = array_map(static fn (string $name): string => sprintf('%s@%s', $name, $byName[$name]->version), $members);
        sort($labels);

        return [$loadOrder, [[
            'kind' => 'dependency-cycle',
            'id' => implode(' <-> ', $members),
            'code' => self::CODE_DEPENDENCY_CYCLE,
            'providedBy' => $labels,
            'reason' => sprintf(
                'The packages %s require each other in a cycle; no boot order exists — nobody can go first.',
                implode(', ', $members),
            ),
        ]]];
    }

    /**
     * The exact-string ids a manifest provides for the ordering pass — every `capabilities.provides`
     * capability id plus every `contracts.implements` contract id, the same ids, parsed the same
     * way, the resolution passes above match on — each paired with its declared `priority` (absent =
     * 0; a contract implementation carries none) so the edge map can follow the same winner
     * {@see pickProvider()} selects.
     *
     * @return list<array{id: string, priority: int}>
     */
    private function providedIds(VersionManifest $manifest): array
    {
        $ids = [];
        foreach ($this->rawList($manifest->capabilities, 'provides') as $entry) {
            if (!is_string($entry) && !is_array($entry)) {
                continue;
            }
            $provision = CapabilityProvision::parse($entry);
            $ids[] = ['id' => $provision->id, 'priority' => $provision->priority];
        }
        foreach ($this->rawList($manifest->contracts, 'implements') as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $ids[] = ['id' => $this->splitImplementation($entry)[0], 'priority' => 0];
        }

        return $ids;
    }

    /**
     * The exact-string ids a manifest requires for the ordering pass: every `capabilities.requires`
     * capability id plus every `contracts.requires` contract id (constraint stripped — version
     * satisfaction is the resolution passes' job; ordering only needs who depends on whom).
     *
     * @return list<string>
     */
    private function requiredIds(VersionManifest $manifest): array
    {
        $ids = [];
        foreach ($this->rawList($manifest->capabilities, 'requires') as $entry) {
            $id = $this->entryId($entry);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        foreach ($this->rawList($manifest->contracts, 'requires') as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $ids[] = $this->splitConstraint($entry)[0];
        }

        return $ids;
    }

    /**
     * Resolve each active surface's capability requirements (from the surface definitions carried in
     * `environment.surfaces`) and carry its declared warnings; contract-declared surface needs that the
     * host has not enabled become non-blocking warnings.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}> $providers
     * @param list<array{surface: string, requiredBy: string}>                                                      $contractSurfaceNeeds
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
                    'fallback' => null,
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
                'fallback' => null,
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
     * Pick one provider from a set deterministically — spec §3.1's "priority resolves deterministic
     * ordering for multiple providers": the highest `priority` wins (absent = 0); a tie falls back to
     * the previous rule — non-legacy first, then the lexicographically first label. So a set with no
     * priorities in play resolves exactly as it did before priorities existed.
     *
     * @param list<array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}> $candidates
     *
     * @return array{id: string, version: string, exclusive: bool, label: string, legacy: bool, priority: int}
     */
    private function pickProvider(array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            return [-$a['priority'], $a['legacy'] ? 1 : 0, $a['label']]
                <=> [-$b['priority'], $b['legacy'] ? 1 : 0, $b['label']];
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

    /**
     * Build the blocking missing[] entry for a legacy path the host's `allowedLegacyContracts` does not
     * permit — the enforcement of §5's "*permitted* legacy adapter" clause. The same denial stays visible
     * in `legacy[]` as `permitted: false`; this entry is how it enters the status. It carries the frozen
     * missing[] key-set: a legacy allowance is a policy boundary, not a version mismatch, so `constraint`
     * and `surface` are null and the `reason` carries which contract/capability and why. The truth table
     * is untouched — an un-permitted legacy path is a missing requirement, so `missing !== [] → blocked`.
     *
     * @return array<string, mixed>
     */
    private function legacyNotAllowed(string $kind, string $id, string $providedBy, HostProfile $host): array
    {
        return [
            'kind' => 'legacy-contract',
            'id' => $id,
            'constraint' => null,
            'level' => RequirementLevel::Required->value,
            'requiredBy' => $this->hostLabel($host),
            'surface' => null,
            'code' => self::CODE_LEGACY_NOT_ALLOWED,
            'reason' => sprintf(
                'The %s "%s" resolves only through the legacy-shaped manifest "%s", which the host profile\'s allowedLegacyContracts does not permit.',
                $kind,
                $id,
                $providedBy,
            ),
        ];
    }

    /**
     * Fold the host's accepted risks into the warnings. Every warning gains three fields — `accepted`,
     * `acceptedReason`, `acceptanceExpired` — evaluated against the caller's clock (spec: acceptance is
     * never anonymous, and a lapsed acceptance re-degrades). An accepted risk whose expiry could not be
     * checked (no `evaluatedAt`) keeps applying, but the oversight is surfaced as its own visible,
     * unaccepted MILPA_RISK_EXPIRY_UNEVALUATED warning — the omission is never silent.
     *
     * @param list<array<string, mixed>> $warnings
     *
     * @return list<array<string, mixed>>
     */
    private function applyAcceptedRisks(array $warnings, HostProfile $host, ?string $evaluatedAt): array
    {
        $out = [];
        /** @var array<string, true> $unevaluated */
        $unevaluated = [];

        foreach ($warnings as $warning) {
            $code = is_string($warning['code'] ?? null) ? $warning['code'] : '';
            $verdict = $this->acceptanceFor($host, $code, $evaluatedAt);
            if ($verdict['unevaluated']) {
                $unevaluated[$code] = true;
            }
            $out[] = [
                'kind' => $warning['kind'],
                'id' => $warning['id'],
                'surface' => $warning['surface'],
                'code' => $warning['code'],
                'requiredBy' => $warning['requiredBy'],
                'fallback' => $warning['fallback'],
                'accepted' => $verdict['accepted'],
                'acceptedReason' => $verdict['reason'],
                'acceptanceExpired' => $verdict['expired'],
                'message' => $warning['message'],
            ];
        }

        // One meta-warning per accepted-risk code whose expiry the caller gave no clock to evaluate.
        foreach (array_keys($unevaluated) as $code) {
            $out[] = [
                'kind' => 'risk-expiry',
                'id' => $code,
                'surface' => null,
                'code' => self::CODE_RISK_EXPIRY_UNEVALUATED,
                'requiredBy' => $this->hostLabel($host),
                'fallback' => null,
                'accepted' => false,
                'acceptedReason' => null,
                'acceptanceExpired' => false,
                'message' => sprintf(
                    'The accepted risk "%s" declares an expiry, but the resolution ran without an evaluatedAt clock; pass evaluatedAt or drop the expiry.',
                    $code,
                ),
            ];
        }

        return $out;
    }

    /**
     * Evaluate whether the host accepts a warning `code`, and how, against the caller's clock: an
     * accepted risk with no expiry applies; one whose expiry is unreached applies; one whose expiry has
     * passed is void (and re-degrades); one with an expiry but no clock applies but is flagged as
     * unevaluated. The clock comparison is a pure function of two ISO-8601 input strings.
     *
     * @return array{accepted: bool, reason: string|null, expired: bool, unevaluated: bool}
     */
    private function acceptanceFor(HostProfile $host, string $code, ?string $evaluatedAt): array
    {
        $none = ['accepted' => false, 'reason' => null, 'expired' => false, 'unevaluated' => false];
        if ($code === '') {
            return $none;
        }

        $risk = null;
        foreach ($host->acceptedRisks as $candidate) {
            if ($candidate->code === $code) {
                $risk = $candidate;

                break;
            }
        }
        if ($risk === null) {
            return $none;
        }

        if ($risk->expires === null) {
            return ['accepted' => true, 'reason' => $risk->reason, 'expired' => false, 'unevaluated' => false];
        }

        if ($evaluatedAt === null || $evaluatedAt === '') {
            return ['accepted' => true, 'reason' => $risk->reason, 'expired' => false, 'unevaluated' => true];
        }

        if ($this->clockIsAfter($evaluatedAt, $risk->expires)) {
            return ['accepted' => false, 'reason' => $risk->reason, 'expired' => true, 'unevaluated' => false];
        }

        return ['accepted' => true, 'reason' => $risk->reason, 'expired' => false, 'unevaluated' => false];
    }

    /**
     * Whether the caller's clock is strictly after the expiry, comparing both in UTC so the verdict is
     * independent of the host's default timezone — a deterministic comparison of two input strings, not
     * an ambient clock read.
     */
    private function clockIsAfter(string $evaluatedAt, string $expires): bool
    {
        $utc = new \DateTimeZone('UTC');

        return new \DateTimeImmutable($evaluatedAt, $utc) > new \DateTimeImmutable($expires, $utc);
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
