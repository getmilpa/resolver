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

namespace Milpa\Resolver\Report;

/**
 * The single source of learnable-error content (spec §13/§19). For each error code it holds the
 * cause (`why`), a message template, human `fixes`, and the LIVE, bilingual Academy `links` — and
 * builds a {@see LearnableArchitectureError} from a code plus the entry context.
 *
 * The catalog enforces spec §25 anti-pattern 4 ("error muerto"): every code carries why + fix +
 * Academy link, proven by walking the whole catalog in the test suite. Links are the URLs verified
 * live in production — never invented; since the superficies wave EVERY code points at the lesson
 * that actually teaches it (version-contrato, legacy-y-migracion, superficies-puertas,
 * riesgos-aceptados, skeleton-boot, contratos-grafo, atlas-limites) — no code rides the Academy
 * root anymore. It covers the 11 initial codes of spec §13, the two codes
 * that split the engine's ambiguous usages (`MILPA_SURFACE_NOT_ENABLED`,
 * `MILPA_SUGGESTED_CAPABILITY_MISSING`), `MILPA_RISK_EXPIRY_UNEVALUATED` (an accepted risk whose
 * expiry could not be checked because the caller supplied no clock), `MILPA_LEGACY_NOT_ALLOWED`
 * (a legacy-shaped resolution the host profile's allowlist does not permit — the enforcement of
 * `allowedLegacyContracts`), `MILPA_DEPENDENCY_CYCLE` (packages that require each other in a
 * cycle, for which no boot order exists), `MILPA_MANIFEST_DRIFT` (a `milpa.json` that no longer
 * matches the code's `#[PluginMetadata]` — caller-emitted by
 * {@see \Milpa\Resolver\Ingest\DriftDetector::toLearnableErrors()}, never by the engine), and
 * `MILPA_CAPABILITY_VERSION_UNSUPPORTED` (a capability whose providers exist but none satisfies
 * the consumer's constraint — split from the CONTRACT version code so each side teaches its own
 * upgrade path).
 *
 * Messages attribute their requirer: when `context.requiredBy` names a package or a contract, the
 * templated message names it too, so the reader learns WHO opened the graph — not just what is
 * missing. Host-origin entries (a `hostProfile:`-prefixed requiredBy) keep the host phrasing. The
 * attribution covers the missing codes AND both version codes (`MILPA_CONTRACT_VERSION_UNSUPPORTED`
 * / `MILPA_CAPABILITY_VERSION_UNSUPPORTED` — a consistent pair, per the Orden-slice precedent).
 * Three context fields refine a message further: `oneOf` (a missed capability requirement's
 * exhausted alternatives) makes the capability-missing message enumerate every candidate tried,
 * `providedBy` on a capability version miss names WHICH oneOf candidates exist only out of range
 * (so the message never claims the primary id "is provided" when only an alternative is), and
 * `fallback` (a suggestion record's declared degradation path) makes the suggested-capability
 * message name where the runtime degrades to.
 */
final class ErrorCatalog
{
    /** @var array{es: string, en: string} */
    private const UNIT_CONTRATOS_GRAFO = [
        'es' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
        'en' => 'https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_VERSION_CONTRATO = [
        'es' => 'https://academy.milpa.lat/learn/fundamentos/version-contrato/',
        'en' => 'https://academy.milpa.lat/en/learn/fundamentos/version-contrato/',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_LEGACY_Y_MIGRACION = [
        'es' => 'https://academy.milpa.lat/learn/arquitectura/legacy-y-migracion/',
        'en' => 'https://academy.milpa.lat/en/learn/arquitectura/legacy-y-migracion/',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_SUPERFICIES_PUERTAS = [
        'es' => 'https://academy.milpa.lat/learn/arquitectura/superficies-puertas/',
        'en' => 'https://academy.milpa.lat/en/learn/arquitectura/superficies-puertas/',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_RIESGOS_ACEPTADOS = [
        'es' => 'https://academy.milpa.lat/learn/arquitectura/riesgos-aceptados/',
        'en' => 'https://academy.milpa.lat/en/learn/arquitectura/riesgos-aceptados/',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_SKELETON_BOOT = [
        'es' => 'https://academy.milpa.lat/learn/construye/skeleton-boot/',
        'en' => 'https://academy.milpa.lat/en/learn/construye/skeleton-boot/',
    ];

    /** @var array{es: string, en: string} */
    private const ARTIFACT_SIEMBRA = [
        'es' => 'https://academy.milpa.lat/artifacts/#siembra',
        'en' => 'https://academy.milpa.lat/en/artifacts/#siembra',
    ];

    /** @var array{es: string, en: string} */
    private const ARTIFACT_ATOMO = [
        'es' => 'https://academy.milpa.lat/artifacts/#atomo',
        'en' => 'https://academy.milpa.lat/en/artifacts/#atomo',
    ];

    /** @var array{es: string, en: string} */
    private const ARTIFACT_FRONTERA = [
        'es' => 'https://academy.milpa.lat/artifacts/#frontera',
        'en' => 'https://academy.milpa.lat/en/artifacts/#frontera',
    ];

    /** @var array{es: string, en: string} */
    private const ARTIFACT_COMPUERTA_ARRANQUE = [
        'es' => 'https://academy.milpa.lat/artifacts/#compuerta-arranque',
        'en' => 'https://academy.milpa.lat/en/artifacts/#compuerta-arranque',
    ];

    /** @var array{es: string, en: string} */
    private const UNIT_ATLAS_LIMITES = [
        'es' => 'https://academy.milpa.lat/learn/arquitectura/atlas-limites/',
        'en' => 'https://academy.milpa.lat/en/learn/arquitectura/atlas-limites/',
    ];

    /** @var array{es: string, en: string} */
    private const LLMS = [
        'es' => 'https://academy.milpa.lat/llms.txt',
        'en' => 'https://academy.milpa.lat/en/llms.txt',
    ];

    /**
     * Every code the catalog knows, in a stable order.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Whether a code has a catalog entry (used to decide which report entries earn a learnable error).
     */
    public static function has(string $code): bool
    {
        return isset(self::definitions()[$code]);
    }

    /**
     * Build the learnable error for a code, templating its message and fixes with the entry context.
     *
     * @param array<string, mixed> $context
     *
     * @throws \InvalidArgumentException When the code has no catalog entry.
     */
    public static function for(string $code, array $context = []): LearnableArchitectureError
    {
        $definitions = self::definitions();
        if (!isset($definitions[$code])) {
            throw new \InvalidArgumentException(sprintf('Unknown error code "%s" has no catalog entry.', $code));
        }

        return new LearnableArchitectureError(
            code: $code,
            message: self::message($code, $context),
            why: $definitions[$code]['why'],
            context: $context,
            fixes: self::fixes($code, $context),
            links: $definitions[$code]['links'],
        );
    }

    /**
     * The static content of every code: its cause and its bilingual Academy links.
     *
     * @return array<string, array{why: string, links: array<string, array{es: string, en: string}>}>
     */
    private static function definitions(): array
    {
        return [
            'MILPA_CONTRACT_MISSING' => [
                'why' => 'A required architectural contract closes only when an installed package declares it implements that contract at a compatible version. Nothing implements it here, so the contract stays open.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CONTRACT_VERSION_UNSUPPORTED' => [
                'why' => 'The contract is implemented, but at a version outside the range the host asked for. A version is a contract, not a label: an out-of-range implementation cannot be trusted to honour the expected shape.',
                'links' => ['academy' => self::UNIT_VERSION_CONTRATO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CAPABILITY_MISSING' => [
                'why' => 'A required capability closes the architecture graph only when an installed package or plugin declares that it provides it. With no provider, the runtime cannot wire the capability and the graph stays open.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CAPABILITY_VERSION_UNSUPPORTED' => [
                'why' => 'A provider for the capability exists, but its contractVersion falls outside the range the consumer asked for. A version is a contract, not a label: the capability is present at the wrong version, so the requirement stays open until the provider upgrades or the constraint admits what is installed.',
                'links' => ['academy' => self::UNIT_VERSION_CONTRATO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CAPABILITY_CONFLICT' => [
                'why' => 'Two or more providers claim the same capability and at least one marks it exclusive. The resolver refuses to pick silently: a hidden choice is exactly the invisible architecture the resolver exists to prevent.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_SURFACE_REQUIREMENT_UNMET' => [
                'why' => 'An enabled surface projects operations through a set of capabilities. If one of those capabilities has no provider, the surface has an open door and the runtime would expose it half-wired.',
                'links' => ['academy' => self::UNIT_SUPERFICIES_PUERTAS, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_ADAPTER_MISSING' => [
                'why' => 'A contract expects an adapter to bridge it to a surface or a legacy shape, and none is installed. Without the adapter the contract cannot be projected where the host wants it.',
                'links' => ['academy' => self::UNIT_SUPERFICIES_PUERTAS, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_HOST_PROFILE_OUTDATED' => [
                'why' => 'The host profile describes an architectural shape the current packages no longer match. The profile, not the code, is stale: it asks for a world that has moved on.',
                'links' => ['academy' => self::UNIT_SKELETON_BOOT, 'llms' => self::LLMS],
            ],
            'MILPA_LEGACY_CONTRACT_ACTIVE' => [
                'why' => 'A dependency closes through a legacy-shaped manifest. This is allowed, but never silent: legacy compatibility is named so it stays visible instead of decaying into invisible archaeology.',
                'links' => ['academy' => self::UNIT_LEGACY_Y_MIGRACION, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_LEGACY_NOT_ALLOWED' => [
                'why' => 'The host explicitly restricts which dependencies may close through a legacy-shaped manifest, and this one resolves only through a shape the profile does not permit. allowedLegacyContracts is a gate, not a note: unlike a tolerated legacy path — which degrades to legacy_compatible — an un-permitted one blocks. An empty or selective allowlist is a deliberate boundary the resolver enforces instead of quietly crossing.',
                'links' => ['academy' => self::UNIT_LEGACY_Y_MIGRACION, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_DEPRECATED_CONTRACT_USED' => [
                'why' => 'A package still declares something it has marked deprecated. It works today, but the metadata is warning you that this shape is scheduled to leave; migrating before removal is cheaper than after.',
                'links' => ['academy' => self::UNIT_LEGACY_Y_MIGRACION, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED' => [
                'why' => 'The architecture graph does not close: at least one required contract or capability is missing, or an exclusive capability conflicts. The runtime must not boot on an open graph.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_BOOTABLE_WITH_WARNINGS' => [
                'why' => 'Every required dependency closes, but the graph carries warnings: suggested capabilities without providers, or surfaces with declared caveats. The host can boot, with the trade-offs made explicit.',
                'links' => ['academy' => self::UNIT_RIESGOS_ACEPTADOS, 'llms' => self::LLMS],
            ],
            'MILPA_SURFACE_NOT_ENABLED' => [
                'why' => 'A contract wants to project through a surface the host has not enabled. Nothing is broken — the projection simply will not happen — but the mismatch is surfaced so it is a choice, not an accident.',
                'links' => ['academy' => self::UNIT_SUPERFICIES_PUERTAS, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_SUGGESTED_CAPABILITY_MISSING' => [
                'why' => 'A suggested capability has no provider. Suggested means optional: the graph still closes and the fallback path applies, but the suggested behaviour is absent.',
                'links' => ['academy' => self::UNIT_RIESGOS_ACEPTADOS, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_RISK_EXPIRY_UNEVALUATED' => [
                'why' => 'An accepted risk carries an expiry, but the resolution ran without an evaluatedAt clock, so the resolver could not tell whether the acceptance is still valid. The resolver stays pure — it never reads the wall clock itself — so instead of silently trusting an expiry it never checked, it flags the oversight: an unevaluated expiry is a risk you think is bounded but is not being enforced.',
                'links' => ['academy' => self::UNIT_RIESGOS_ACEPTADOS, 'llms' => self::LLMS],
            ],
            'MILPA_DEPENDENCY_CYCLE' => [
                'why' => 'A dependency cycle has no possible boot order — nobody can go first. Each member requires something another member provides, so whichever package boots first finds its requirement not yet wired. The cycle members are excluded from loadOrder[] and the graph blocks until the cycle is broken.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_COMPUERTA_ARRANQUE, 'llms' => self::LLMS],
            ],
            'MILPA_MANIFEST_DRIFT' => [
                'why' => 'The manifest promises one architecture and the code carries another. The contract that teaches is the one that runs, not the one that is written: a drifted milpa.json teaches humans and agents a shape that no longer exists, so every decision made from it inherits the gap.',
                'links' => ['academy' => self::UNIT_ATLAS_LIMITES, 'artifact' => self::ARTIFACT_COMPUERTA_ARRANQUE, 'llms' => self::LLMS],
            ],
        ];
    }

    /**
     * Template the message for a code from the entry context. `MILPA_CAPABILITY_MISSING` and BOTH
     * version codes attribute their requirer per {@see namedRequirer()}: a package
     * (`vendor/package@x.y.z`) or contract (`contract:<id>@<v>`) requiredBy is named in the message;
     * a `hostProfile:`-prefixed requiredBy, an absent one, and the owner-less `input` sentinel (a
     * caller-supplied typed requirement no installed manifest declares — NOT a package) all keep the
     * host phrasing.
     *
     * @param array<string, mixed> $context
     */
    private static function message(string $code, array $context): string
    {
        $id = self::str($context, 'id');
        $surface = self::str($context, 'surface');
        $host = self::str($context, 'hostProfile');
        $constraint = self::str($context, 'constraint');
        $requiredBy = self::str($context, 'requiredBy');
        $driftedFields = $context['fields'] ?? null;

        return match ($code) {
            'MILPA_CONTRACT_MISSING' => sprintf('The host profile %s requires the contract "%s", but no installed package implements it.', $host, $id),
            'MILPA_CONTRACT_VERSION_UNSUPPORTED' => self::contractVersionUnsupportedMessage($id, $constraint, $requiredBy),
            'MILPA_CAPABILITY_MISSING' => self::capabilityMissingMessage($context, $id, $host, $requiredBy),
            'MILPA_CAPABILITY_VERSION_UNSUPPORTED' => self::capabilityVersionUnsupportedMessage($context, $id, $constraint, $requiredBy),
            'MILPA_CAPABILITY_CONFLICT' => sprintf('The exclusive capability "%s" is claimed by more than one provider.', $id),
            'MILPA_SURFACE_REQUIREMENT_UNMET' => sprintf('The active surface "%s" requires the capability "%s", which no provider offers.', $surface, $id),
            'MILPA_ADAPTER_MISSING' => sprintf('The adapter "%s" that a contract expects is not installed.', $id),
            'MILPA_HOST_PROFILE_OUTDATED' => sprintf('The host profile %s is out of date for the installed packages.', $host),
            'MILPA_LEGACY_CONTRACT_ACTIVE' => sprintf('The contract "%s" is satisfied through a legacy-shaped manifest.', $id),
            'MILPA_LEGACY_NOT_ALLOWED' => sprintf('The host profile %s does not permit the legacy-shaped resolution of "%s"; allowedLegacyContracts restricts which legacy paths may close.', $host, $id),
            'MILPA_DEPRECATED_CONTRACT_USED' => sprintf('Package %s declares "%s" as deprecated.', $requiredBy, $id),
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED' => 'The architecture graph is blocked; the host cannot boot until every required dependency closes.',
            'MILPA_BOOTABLE_WITH_WARNINGS' => 'The architecture graph closes with warnings; the host can boot once the warnings are reviewed or accepted.',
            'MILPA_SURFACE_NOT_ENABLED' => sprintf('A contract expects the surface "%s", which the host profile has not enabled.', $surface),
            'MILPA_SUGGESTED_CAPABILITY_MISSING' => self::str($context, 'fallback') === ''
                ? sprintf('The suggested capability "%s" has no provider; its fallback path applies.', $id)
                : sprintf(
                    'The suggested capability "%s" has no provider; its fallback path applies: degrades to "%s".',
                    $id,
                    self::str($context, 'fallback'),
                ),
            'MILPA_RISK_EXPIRY_UNEVALUATED' => sprintf('The accepted risk "%s" has an expiry, but the resolution ran without an evaluatedAt clock, so the expiry could not be checked.', $id),
            'MILPA_DEPENDENCY_CYCLE' => sprintf('The packages %s require each other in a cycle; no boot order exists.', $id),
            'MILPA_MANIFEST_DRIFT' => sprintf(
                'The manifest of %s declares an architecture its code does not carry; %d field(s) drifted between milpa.json and #[PluginMetadata].',
                self::str($context, 'package'),
                is_array($driftedFields) ? count($driftedFields) : 0,
            ),
            default => '',
        };
    }

    /**
     * The requirer a message may name: the `requiredBy` itself when it is a package
     * (`vendor/package@x.y.z`) or a contract (`contract:<id>@<v>`); `null` — keep the host phrasing —
     * for a `hostProfile:`-prefixed origin, an absent one, and the owner-less `input` sentinel (a
     * caller-supplied typed requirement no installed manifest declares, which is NOT a package). One
     * predicate shared by every attributing message, so "who counts as a requirer" can never fork
     * between codes.
     */
    private static function namedRequirer(string $requiredBy): ?string
    {
        return $requiredBy !== '' && $requiredBy !== 'input' && !str_starts_with($requiredBy, 'hostProfile:')
            ? $requiredBy
            : null;
    }

    /**
     * The capability-missing message, attribution rules unchanged (a package or contract requirer is
     * named; host/`input`/absent origins keep the host phrasing). When the context carries `oneOf` —
     * a requirement whose alternatives were ALL exhausted — the message enumerates every candidate
     * tried (the primary id plus each alternative), so the reader sees the whole search space instead
     * of a single id that quietly had substitutes.
     *
     * @param array<string, mixed> $context
     */
    private static function capabilityMissingMessage(array $context, string $id, string $host, string $requiredBy): string
    {
        $who = self::namedRequirer($requiredBy) ?? sprintf('The host profile %s', $host);

        $oneOf = self::strList($context, 'oneOf');
        if ($oneOf === []) {
            return sprintf('%s requires the capability "%s", but no active package or plugin provides it.', $who, $id);
        }

        return sprintf(
            '%s requires the capability "%s", but none of ["%s"] provides it.',
            $who,
            $id,
            implode('", "', [$id, ...$oneOf]),
        );
    }

    /**
     * The contract version-miss message. Attribution parity with the capability side (the two version
     * codes change as a consistent pair): a package or contract requirer is NAMED — `%s requires the
     * contract …` — while host-origin, `input`, and absent origins keep the original phrasing byte
     * for byte.
     */
    private static function contractVersionUnsupportedMessage(string $id, string $constraint, string $requiredBy): string
    {
        $who = self::namedRequirer($requiredBy);
        if ($who === null) {
            return sprintf('The contract "%s" is implemented, but no implementation satisfies the constraint "%s".', $id, $constraint);
        }

        return sprintf(
            '%s requires the contract "%s"; it is implemented, but no implementation satisfies the constraint "%s".',
            $who,
            $id,
            $constraint,
        );
    }

    /**
     * The capability version-miss message. Two refinements over the original single phrase, neither
     * touching the host-origin/no-oneOf case (byte-identical): (1) attribution parity — a package or
     * contract requirer is named, exactly as {@see contractVersionUnsupportedMessage()} does; (2) when
     * the context carries `providedBy` — a oneOf requirement whose only existing candidates sit out of
     * range — the message says the capability `is provided only through [...]`, naming WHICH candidate
     * fell out of range instead of implying the primary id itself "is provided" when it may have no
     * provider at all.
     *
     * @param array<string, mixed> $context
     */
    private static function capabilityVersionUnsupportedMessage(array $context, string $id, string $constraint, string $requiredBy): string
    {
        $who = self::namedRequirer($requiredBy);
        $subject = $who === null
            ? sprintf('The capability "%s"', $id)
            : sprintf('%s requires the capability "%s"; it', $who, $id);

        $provided = self::strList($context, 'providedBy');
        $providedClause = $provided === []
            ? 'is provided'
            : sprintf('is provided only through ["%s"]', implode('", "', $provided));

        return sprintf(
            '%s %s, but no provider\'s contractVersion satisfies the constraint "%s".',
            $subject,
            $providedClause,
            $constraint,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private static function fixes(string $code, array $context): array
    {
        $id = self::str($context, 'id');
        $surface = self::str($context, 'surface');
        $constraint = self::str($context, 'constraint');
        $package = $id !== '' ? (LearnableArchitectureError::KNOWN_PACKAGES[$id] ?? null) : null;

        return match ($code) {
            'MILPA_CONTRACT_MISSING' => [
                $package !== null
                    ? sprintf('Install %s, which implements "%s".', $package, $id)
                    : sprintf('Install a package that implements "%s".', $id),
                sprintf('Enable a plugin that implements "%s".', $id),
                sprintf('Remove "%s" from the host profile if the contract is not needed.', $id),
            ],
            'MILPA_CONTRACT_VERSION_UNSUPPORTED' => [
                $package !== null
                    ? sprintf('Upgrade %s to a version that satisfies "%s".', $package, $constraint)
                    : sprintf('Install an implementation of "%s" that satisfies "%s".', $id, $constraint),
                sprintf('Relax the "%s" constraint in the host profile if an older implementation is acceptable.', $id),
            ],
            'MILPA_CAPABILITY_MISSING' => [
                $package !== null
                    ? sprintf('Install %s, which provides "%s".', $package, $id)
                    : sprintf('Install a package that provides "%s".', $id),
                sprintf('Enable a plugin that provides "%s".', $id),
                sprintf('Remove "%s" from the host profile if the capability is not needed.', $id),
            ],
            // Both sides of the version mismatch are named: upgrade the PROVIDER (the known package
            // when the id has a canonical one), or relax the REQUIRER's constraint.
            'MILPA_CAPABILITY_VERSION_UNSUPPORTED' => [
                $package !== null
                    ? sprintf('Upgrade %s so its "%s" provision satisfies "%s".', $package, $id, $constraint)
                    : sprintf('Upgrade a provider of "%s" to a contractVersion that satisfies "%s".', $id, $constraint),
                sprintf('Relax the "%s" constraint "%s" on the requirer if an installed provider version is acceptable.', $id, $constraint),
            ],
            'MILPA_CAPABILITY_CONFLICT' => [
                sprintf('Keep exactly one provider of "%s" and disable the others.', $id),
                sprintf('Mark "%s" non-exclusive if multiple providers are intended.', $id),
            ],
            'MILPA_SURFACE_REQUIREMENT_UNMET' => [
                $package !== null
                    ? sprintf('Install %s, which provides "%s".', $package, $id)
                    : sprintf('Install a package that provides "%s".', $id),
                sprintf('Disable the "%s" surface until "%s" has a provider.', $surface, $id),
            ],
            'MILPA_ADAPTER_MISSING' => [
                $package !== null
                    ? sprintf('Install %s, which supplies the "%s" adapter.', $package, $id)
                    : sprintf('Install the package that supplies the "%s" adapter.', $id),
                sprintf('Enable a plugin that supplies the "%s" adapter.', $id),
            ],
            'MILPA_HOST_PROFILE_OUTDATED' => [
                'Regenerate the host profile from the current package set.',
                'Align the profile\'s required contracts and capabilities with what is installed.',
            ],
            'MILPA_LEGACY_CONTRACT_ACTIVE' => [
                sprintf('Migrate "%s" to the canonical contract shape (contracts.* to capabilities.* records).', $id),
                'Keep the legacy adapter and record it as accepted while you plan the migration.',
            ],
            'MILPA_LEGACY_NOT_ALLOWED' => [
                sprintf('Add "%s" to the host profile\'s allowedLegacyContracts to permit this legacy path explicitly.', $id),
                'Set allowedLegacyContracts to ["*"] to permit every legacy path consciously.',
                sprintf('Migrate "%s" to the canonical capabilities.* shape so it no longer needs a legacy allowance.', $id),
            ],
            'MILPA_DEPRECATED_CONTRACT_USED' => [
                sprintf('Migrate off "%s" before the package removes it.', $id),
                sprintf('Consult the package\'s migration notes for the replacement of "%s".', $id),
            ],
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED' => [
                'Resolve each missing contract and capability listed in the report.',
                'Resolve every exclusive-capability conflict listed in the report.',
            ],
            'MILPA_BOOTABLE_WITH_WARNINGS' => [
                'Review each warning and provide the suggested capabilities you want.',
                'Record the remaining warnings as accepted risks in the host profile.',
            ],
            'MILPA_SURFACE_NOT_ENABLED' => [
                sprintf('Enable the "%s" surface in the host profile if the projection is wanted.', $surface),
                sprintf('Ignore this if the "%s" surface is intentionally left off.', $surface),
            ],
            'MILPA_SUGGESTED_CAPABILITY_MISSING' => [
                $package !== null
                    ? sprintf('Install %s to enable "%s".', $package, $id)
                    : sprintf('Install a package that provides "%s" to enable it.', $id),
                sprintf('Accept the missing "%s" suggestion as a known risk in the host profile.', $id),
            ],
            'MILPA_RISK_EXPIRY_UNEVALUATED' => [
                'Pass evaluatedAt (an ISO-8601 datetime) in the resolution input so the acceptance expiry can be checked.',
                sprintf('Remove the "expires" field from the accepted risk "%s" if the acceptance is not meant to lapse.', $id),
            ],
            'MILPA_DEPENDENCY_CYCLE' => [
                sprintf('Break the cycle (%s) by extracting the shared contract into a third package both sides can require.', $id),
                'Invert one direction of the cycle: downgrade the weaker dependency to a suggests so one member can boot first.',
            ],
            'MILPA_MANIFEST_DRIFT' => [
                sprintf(
                    'Regenerate the manifest from the code: php coa coa:plugins manifest %s.',
                    self::str($context, 'package') !== '' ? self::str($context, 'package') : '<Plugin>',
                ),
                'Fix the #[PluginMetadata] attribute instead, if the manifest is right and the code is what drifted.',
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function str(array $context, string $key): string
    {
        $value = $context[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private static function strList(array $context, string $key): array
    {
        $value = $context[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
