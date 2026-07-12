<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use PHPUnit\Framework\TestCase;

/**
 * Provider selection honors `priority` — the spec's §3.1 clause verbatim: "priority resolves
 * deterministic ordering for multiple providers". Among multiple providers of a non-exclusive id,
 * the highest `priority` wins (absent = 0); a tie falls back to the previous behaviour — non-legacy
 * first, then the lexicographically first label. The consistency invariant couples selection to
 * ordering: when priority selects a winner, the Kahn edge points at THAT winner, so a dependent
 * boots after the provider that actually satisfies it. When no priority is in play, both selection
 * and ordering are byte-identical to the pre-priority engine (the legacy last-provider-wins edge
 * included), keeping the LoadOrderEquivalenceTest fixtures untouched. Exclusive conflicts are NOT
 * rescued by priority: two exclusive claimants still block, whatever their priorities say.
 */
final class ProviderPriorityTest extends TestCase
{
    /**
     * (a) Two manifest-declared providers of the same non-exclusive id, priority 10 vs 1: the
     * priority-10 provider wins `resolved[].providedBy` — even though its label sorts
     * lexicographically LAST, proving priority outranks the lexicographic fallback.
     */
    public function testHigherPriorityWinsProviderSelection(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cap.shared']),
            versionManifests: [
                $this->providerManifest('prov/alpha', 'cap.shared', priority: 1),
                $this->providerManifest('prov/zeta', 'cap.shared', priority: 10),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $resolved = $this->entryBy($report->resolved, 'id', 'cap.shared');
        self::assertNotNull($resolved);
        self::assertSame('prov/zeta@1.0.0', $resolved['providedBy']);
    }

    /**
     * (a bis) The same rule through the OTHER ingestion path — typed `capabilityProvisions` records:
     * the priority-10 service wins over the priority-1 service whose label sorts first.
     */
    public function testHigherPriorityWinsAmongTypedProvisions(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['log.sink']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('log.sink', 'Log', '0.1.0', service: 'App\\AaaLogger', priority: 1, exclusive: false),
                new CapabilityProvision('log.sink', 'Log', '0.1.0', service: 'App\\ZzzLogger', priority: 10, exclusive: false),
            ],
            capabilityRequirements: [],
        ));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $resolved = $this->entryBy($report->resolved, 'id', 'log.sink');
        self::assertNotNull($resolved);
        self::assertSame('App\\ZzzLogger', $resolved['providedBy']);
    }

    /**
     * (b) With NO priority declared anywhere, behaviour is byte-identical to the pre-priority
     * engine — the fixture is LoadOrderTest's duplicated-capability case, extended with a host
     * requirement: selection still picks the lexicographically first label (`prov/one`), while the
     * ordering edge still lets the LAST provider win (`prov/two`) — the documented legacy mismatch,
     * preserved verbatim for equivalence.
     */
    public function testWithoutPriorityBehaviourIsByteIdenticalToTheCurrentEngine(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['service.shared']),
            versionManifests: [
                $this->manifest('prov/one', provides: ['service.shared']),
                $this->manifest('dep/dependent', requires: ['service.shared']),
                $this->manifest('warm/up', provides: ['service.warmup']),
                $this->manifest('prov/two', provides: ['service.shared'], requires: ['service.warmup']),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        // Selection: lexicographically first label, exactly as before.
        $resolved = $this->entryBy($report->resolved, 'id', 'service.shared');
        self::assertNotNull($resolved);
        self::assertSame('prov/one@1.0.0', $resolved['providedBy']);

        // Ordering: the LAST provider in input order still wins as the edge source.
        self::assertSame(
            ['prov/one', 'warm/up', 'prov/two', 'dep/dependent'],
            array_column($report->loadOrder, 'name'),
        );
        self::assertSame([], $report->conflicts);
    }

    /**
     * (c) The consistency invariant — you boot after whoever satisfies you: when priority selects
     * the FIRST provider as the winner, the Kahn edge follows it, so the dependent boots right
     * after `prov/high` instead of waiting for the last-in-input `prov/low` (which the legacy
     * last-wins map would have made the edge source).
     */
    public function testBootOrderPlacesTheDependentAfterThePriorityWinner(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cap.shared']),
            versionManifests: [
                $this->providerManifest('prov/high', 'cap.shared', priority: 10),
                $this->manifest('dep/dependent', requires: ['cap.shared']),
                $this->manifest('warm/up', provides: ['warmup.cap']),
                $this->providerManifest('prov/low', 'cap.shared', priority: 1, requires: ['warmup.cap']),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        // Selection and ordering agree on the SAME winner.
        $resolved = $this->entryBy($report->resolved, 'id', 'cap.shared');
        self::assertNotNull($resolved);
        self::assertSame('prov/high@1.0.0', $resolved['providedBy']);

        self::assertSame(
            ['prov/high', 'warm/up', 'dep/dependent', 'prov/low'],
            array_column($report->loadOrder, 'name'),
        );

        $dependentAt = array_search('dep/dependent', array_column($report->loadOrder, 'name'), true);
        $winnerAt = array_search('prov/high', array_column($report->loadOrder, 'name'), true);
        self::assertIsInt($dependentAt);
        self::assertIsInt($winnerAt);
        self::assertGreaterThan($winnerAt, $dependentAt, 'the dependent boots AFTER the priority winner');
    }

    /**
     * (d) Priority does NOT rescue an exclusive conflict: two providers claiming the same exclusive
     * id still block with MILPA_CAPABILITY_CONFLICT, whatever their priorities — exclusivity is a
     * claim about the id, not a tie to break.
     */
    public function testExclusiveConflictIsNotRescuedByPriority(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['persistence.store']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\MysqlStore', priority: 10, exclusive: true),
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\SqliteStore', priority: 1, exclusive: true),
            ],
            capabilityRequirements: [],
        ));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $conflict = $this->entryBy($report->conflicts, 'id', 'persistence.store');
        self::assertNotNull($conflict);
        self::assertSame('MILPA_CAPABILITY_CONFLICT', $conflict['code']);
        self::assertSame(['App\\MysqlStore', 'App\\SqliteStore'], $conflict['providedBy']);
    }

    /**
     * (e) Determinism, byte for byte: the same priority-laden input resolved twice serializes
     * identically — priority changes WHO wins, never whether the outcome is reproducible.
     */
    public function testPriorityLadenResolutionIsByteIdenticalAcrossRuns(): void
    {
        $input = fn (): ResolutionInput => new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cap.shared']),
            versionManifests: [
                $this->providerManifest('prov/high', 'cap.shared', priority: 10),
                $this->manifest('dep/dependent', requires: ['cap.shared']),
                $this->manifest('warm/up', provides: ['warmup.cap']),
                $this->providerManifest('prov/low', 'cap.shared', priority: 1, requires: ['warmup.cap']),
            ],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('log.sink', 'Log', '0.1.0', service: 'App\\ZzzLogger', priority: 10, exclusive: false),
                new CapabilityProvision('log.sink', 'Log', '0.1.0', service: 'App\\AaaLogger', priority: 1, exclusive: false),
            ],
            capabilityRequirements: [],
        );

        $first = $this->resolve($input())->toArray();
        $second = $this->resolve($input())->toArray();

        self::assertSame(json_encode($first), json_encode($second));
    }

    // --- helpers -----------------------------------------------------------------------------------

    private function resolve(ResolutionInput $input): ResolutionReport
    {
        return (new GraphResolver())->resolve($input);
    }

    /**
     * A manifest providing ONE structured capability record carrying an explicit `priority`,
     * and an explicit `exclusive: false` — these fixtures deliberately duplicate an id across
     * providers, which the §3.1 canon default (exclusive=true) would otherwise turn into a
     * blocking MILPA_CAPABILITY_CONFLICT.
     *
     * @param list<string> $requires
     */
    private function providerManifest(string $package, string $capabilityId, int $priority, array $requires = []): VersionManifest
    {
        return new VersionManifest(
            package: $package,
            version: '1.0.0',
            contracts: ['implements' => []],
            capabilities: [
                'provides' => [[
                    'id' => $capabilityId,
                    'interface' => 'Shared\\Contract',
                    'contractVersion' => '1.0.0',
                    'priority' => $priority,
                    'exclusive' => false,
                ]],
                'requires' => $requires,
            ],
        );
    }

    /**
     * A manifest whose provides/requires are bare exact-string capability ids (no priority anywhere).
     *
     * @param list<string> $provides
     * @param list<string> $requires
     */
    private function manifest(string $package, array $provides = [], array $requires = []): VersionManifest
    {
        return new VersionManifest(
            package: $package,
            version: '1.0.0',
            contracts: ['implements' => []],
            capabilities: ['provides' => $provides, 'requires' => $requires],
        );
    }

    /**
     * The first entry whose `$key` equals `$value`, or null.
     *
     * @param list<array<string, mixed>> $list
     *
     * @return array<string, mixed>|null
     */
    private function entryBy(array $list, string $key, string $value): ?array
    {
        foreach ($list as $entry) {
            if (($entry[$key] ?? null) === $value) {
                return $entry;
            }
        }

        return null;
    }
}
