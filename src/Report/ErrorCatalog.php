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
 * live in production — never invented; codes without a dedicated lesson point honestly at the
 * Academy root plus its llms resource. It covers the 11 initial codes of spec §13 plus the two
 * codes that split the engine's ambiguous usages (`MILPA_SURFACE_NOT_ENABLED`,
 * `MILPA_SUGGESTED_CAPABILITY_MISSING`).
 */
final class ErrorCatalog
{
    /** @var array{es: string, en: string} */
    private const UNIT_CONTRATOS_GRAFO = [
        'es' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
        'en' => 'https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
    ];

    /** @var array{es: string, en: string} */
    private const ACADEMY_ROOT = [
        'es' => 'https://academy.milpa.lat/',
        'en' => 'https://academy.milpa.lat/en/',
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
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CAPABILITY_MISSING' => [
                'why' => 'A required capability closes the architecture graph only when an installed package or plugin declares that it provides it. With no provider, the runtime cannot wire the capability and the graph stays open.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
            'MILPA_CAPABILITY_CONFLICT' => [
                'why' => 'Two or more providers claim the same capability and at least one marks it exclusive. The resolver refuses to pick silently: a hidden choice is exactly the invisible architecture the resolver exists to prevent.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_SURFACE_REQUIREMENT_UNMET' => [
                'why' => 'An enabled surface projects operations through a set of capabilities. If one of those capabilities has no provider, the surface has an open door and the runtime would expose it half-wired.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_ADAPTER_MISSING' => [
                'why' => 'A contract expects an adapter to bridge it to a surface or a legacy shape, and none is installed. Without the adapter the contract cannot be projected where the host wants it.',
                'links' => ['academy' => self::ACADEMY_ROOT, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_HOST_PROFILE_OUTDATED' => [
                'why' => 'The host profile describes an architectural shape the current packages no longer match. The profile, not the code, is stale: it asks for a world that has moved on.',
                'links' => ['academy' => self::ACADEMY_ROOT, 'llms' => self::LLMS],
            ],
            'MILPA_LEGACY_CONTRACT_ACTIVE' => [
                'why' => 'A dependency closes through a legacy-shaped manifest. This is allowed, but never silent: legacy compatibility is named so it stays visible instead of decaying into invisible archaeology.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_DEPRECATED_CONTRACT_USED' => [
                'why' => 'A package still declares something it has marked deprecated. It works today, but the metadata is warning you that this shape is scheduled to leave; migrating before removal is cheaper than after.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED' => [
                'why' => 'The architecture graph does not close: at least one required contract or capability is missing, or an exclusive capability conflicts. The runtime must not boot on an open graph.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_FRONTERA, 'llms' => self::LLMS],
            ],
            'MILPA_BOOTABLE_WITH_WARNINGS' => [
                'why' => 'Every required dependency closes, but the graph carries warnings: suggested capabilities without providers, or surfaces with declared caveats. The host can boot, with the trade-offs made explicit.',
                'links' => ['academy' => self::ACADEMY_ROOT, 'llms' => self::LLMS],
            ],
            'MILPA_SURFACE_NOT_ENABLED' => [
                'why' => 'A contract wants to project through a surface the host has not enabled. Nothing is broken — the projection simply will not happen — but the mismatch is surfaced so it is a choice, not an accident.',
                'links' => ['academy' => self::ACADEMY_ROOT, 'artifact' => self::ARTIFACT_ATOMO, 'llms' => self::LLMS],
            ],
            'MILPA_SUGGESTED_CAPABILITY_MISSING' => [
                'why' => 'A suggested capability has no provider. Suggested means optional: the graph still closes and the fallback path applies, but the suggested behaviour is absent.',
                'links' => ['academy' => self::UNIT_CONTRATOS_GRAFO, 'artifact' => self::ARTIFACT_SIEMBRA, 'llms' => self::LLMS],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function message(string $code, array $context): string
    {
        $id = self::str($context, 'id');
        $surface = self::str($context, 'surface');
        $host = self::str($context, 'hostProfile');
        $constraint = self::str($context, 'constraint');
        $requiredBy = self::str($context, 'requiredBy');

        return match ($code) {
            'MILPA_CONTRACT_MISSING' => sprintf('The host profile %s requires the contract "%s", but no installed package implements it.', $host, $id),
            'MILPA_CONTRACT_VERSION_UNSUPPORTED' => sprintf('The contract "%s" is implemented, but no implementation satisfies the constraint "%s".', $id, $constraint),
            'MILPA_CAPABILITY_MISSING' => sprintf('The host profile %s requires the capability "%s", but no active package or plugin provides it.', $host, $id),
            'MILPA_CAPABILITY_CONFLICT' => sprintf('The exclusive capability "%s" is claimed by more than one provider.', $id),
            'MILPA_SURFACE_REQUIREMENT_UNMET' => sprintf('The active surface "%s" requires the capability "%s", which no provider offers.', $surface, $id),
            'MILPA_ADAPTER_MISSING' => sprintf('The adapter "%s" that a contract expects is not installed.', $id),
            'MILPA_HOST_PROFILE_OUTDATED' => sprintf('The host profile %s is out of date for the installed packages.', $host),
            'MILPA_LEGACY_CONTRACT_ACTIVE' => sprintf('The contract "%s" is satisfied through a legacy-shaped manifest.', $id),
            'MILPA_DEPRECATED_CONTRACT_USED' => sprintf('Package %s declares "%s" as deprecated.', $requiredBy, $id),
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED' => 'The architecture graph is blocked; the host cannot boot until every required dependency closes.',
            'MILPA_BOOTABLE_WITH_WARNINGS' => 'The architecture graph closes with warnings; the host can boot once the warnings are reviewed or accepted.',
            'MILPA_SURFACE_NOT_ENABLED' => sprintf('A contract expects the surface "%s", which the host profile has not enabled.', $surface),
            'MILPA_SUGGESTED_CAPABILITY_MISSING' => sprintf('The suggested capability "%s" has no provider; its fallback path applies.', $id),
            default => '',
        };
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
}
