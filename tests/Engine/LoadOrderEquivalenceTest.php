<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The BC contract of the absorbed Kahn pass: the engine must boot the same plugin shapes in the
 * same sequences the legacy `Milpa\Plugin\ContractResolver::getLoadOrder()` produced, so runtime T3
 * can swap resolvers without reordering anybody's boot.
 *
 * Provenance: the fixtures and their expected sequences below were replicated VERBATIM from runs of
 * the real `Milpa\Plugin\ContractResolver` (packages/milpa-plugin/src/ContractResolver.php),
 * executed from the monorepo root on 2026-07-12 — captured, not guessed. They cannot be computed
 * here live because milpa/resolver must not depend on milpa/plugin, not even as a dev dependency:
 * that would invert the dependency direction (the plugin layer consumes the resolver, never the
 * reverse).
 */
final class LoadOrderEquivalenceTest extends TestCase
{
    /**
     * The legacy fixtures, each a `{name, provides, requires}` plugin list in config order, paired
     * with the sequence the real ContractResolver emitted for it.
     *
     * @return array<string, array{0: list<array{name: string, provides?: list<string>, requires?: list<string>}>, 1: list<string>}>
     */
    public static function legacyFixtures(): array
    {
        return [
            // Linear chain, scrambled input (milpa-plugin ContractResolverTest::testGetLoadOrderWithDependencies).
            'linear' => [
                [
                    ['name' => 'PluginC', 'requires' => ['serviceB']],
                    ['name' => 'PluginA', 'provides' => ['serviceA']],
                    ['name' => 'PluginB', 'provides' => ['serviceB'], 'requires' => ['serviceA']],
                ],
                ['PluginA', 'PluginB', 'PluginC'],
            ],
            // Diamond (milpa-plugin ContractResolverTest::testGetLoadOrderWithDiamondDependency).
            'diamond' => [
                [
                    ['name' => 'D', 'requires' => ['serviceB', 'serviceC']],
                    ['name' => 'A', 'provides' => ['serviceA']],
                    ['name' => 'B', 'provides' => ['serviceB'], 'requires' => ['serviceA']],
                    ['name' => 'C', 'provides' => ['serviceC'], 'requires' => ['serviceA']],
                ],
                ['A', 'B', 'C', 'D'],
            ],
            // Multi-tier with ties inside each tier: the ties keep config order (Gamma before Alpha,
            // Mid2 before Mid1 — Mid2's single dependency frees it first in the FIFO queue).
            'multi-tier ties' => [
                [
                    ['name' => 'Gamma', 'provides' => ['tier0.gamma']],
                    ['name' => 'Alpha', 'provides' => ['tier0.alpha']],
                    ['name' => 'Mid2', 'provides' => ['tier1.two'], 'requires' => ['tier0.gamma']],
                    ['name' => 'Mid1', 'provides' => ['tier1.one'], 'requires' => ['tier0.alpha', 'tier0.gamma']],
                    ['name' => 'Top', 'requires' => ['tier1.one', 'tier1.two']],
                ],
                ['Gamma', 'Alpha', 'Mid2', 'Mid1', 'Top'],
            ],
            // No edges at all: the config order is preserved verbatim.
            'config-order preserved' => [
                [
                    ['name' => 'Zeta'],
                    ['name' => 'Alpha'],
                    ['name' => 'Mango'],
                ],
                ['Zeta', 'Alpha', 'Mango'],
            ],
            // Duplicated provider: the LAST provider in config order silently wins as the edge
            // source, so the dependent waits for ProviderTwo (which itself waits for Warmup).
            'last provider wins' => [
                [
                    ['name' => 'ProviderOne', 'provides' => ['service.shared']],
                    ['name' => 'Dependent', 'requires' => ['service.shared']],
                    ['name' => 'Warmup', 'provides' => ['service.warmup']],
                    ['name' => 'ProviderTwo', 'provides' => ['service.shared'], 'requires' => ['service.warmup']],
                ],
                ['ProviderOne', 'Warmup', 'ProviderTwo', 'Dependent'],
            ],
        ];
    }

    /**
     * Each legacy plugin shape, expressed as version manifests, boots in exactly the sequence the
     * legacy resolver produced.
     *
     * @param list<array{name: string, provides?: list<string>, requires?: list<string>}> $plugins
     * @param list<string>                                                                $expected
     */
    #[DataProvider('legacyFixtures')]
    public function testTheEngineReproducesTheLegacySequence(array $plugins, array $expected): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: array_map(
                static fn (array $plugin): VersionManifest => new VersionManifest(
                    package: $plugin['name'],
                    version: '1.0.0',
                    contracts: ['implements' => []],
                    capabilities: [
                        'provides' => $plugin['provides'] ?? [],
                        'requires' => $plugin['requires'] ?? [],
                    ],
                ),
                $plugins,
            ),
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = (new GraphResolver())->resolve($input);

        self::assertSame($expected, array_column($report->loadOrder, 'name'));
    }
}
