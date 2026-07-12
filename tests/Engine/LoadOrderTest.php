<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

/**
 * The engine orders the boot: `loadOrder[]` is the Kahn topological sort absorbed from the legacy
 * `Milpa\Plugin\ContractResolver`, computed over the version manifests' exact-string capability and
 * contract ids. Its order IS the payload — the one report list exempt from lexicographic sorting —
 * and a dependency cycle is not an exception but a learnable, blocking `conflicts[]` entry
 * (`MILPA_DEPENDENCY_CYCLE`): the cycle members leave the order, the independents keep theirs.
 */
final class LoadOrderTest extends TestCase
{
    /**
     * (a) A linear provides→requires edge orders the provider first, even when the input arrives
     * dependent-first — and each entry carries the full name@version identity, split in two fields.
     */
    public function testALinearEdgeOrdersTheProviderFirstRegardlessOfInputOrder(): void
    {
        $report = $this->resolve([
            $this->manifest('pkg/b', '2.0.0', requires: ['cap.x']),
            $this->manifest('pkg/a', '1.0.0', provides: ['cap.x']),
        ]);

        self::assertSame(
            [
                ['name' => 'pkg/a', 'version' => '1.0.0'],
                ['name' => 'pkg/b', 'version' => '2.0.0'],
            ],
            $report->loadOrder,
        );
    }

    /**
     * (b) Ties keep the INPUT order: packages with no edges between them boot in the exact order the
     * host configured them — the order is semantic, never re-sorted lexicographically.
     */
    public function testTiesPreserveTheInputOrder(): void
    {
        $report = $this->resolve([
            $this->manifest('pkg/c', '1.0.0'),
            $this->manifest('pkg/a', '1.0.0'),
            $this->manifest('pkg/b', '1.0.0'),
        ]);

        self::assertSame(['pkg/c', 'pkg/a', 'pkg/b'], array_column($report->loadOrder, 'name'));
    }

    /**
     * (c) A requirement nobody provides creates no edge — the order stays the input order and every
     * package stays IN the order — while the miss keeps its usual missing[] entry and blocks.
     */
    public function testARequirementWithoutProviderDoesNotAffectTheOrder(): void
    {
        $report = $this->resolve(
            [
                $this->manifest('pkg/b', '1.0.0', requires: ['ghost.cap']),
                $this->manifest('pkg/a', '1.0.0', provides: ['real.cap']),
            ],
            new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['ghost.cap']),
        );

        self::assertSame(['pkg/b', 'pkg/a'], array_column($report->loadOrder, 'name'));

        // The miss is unchanged: still a missing[] entry, still blocking.
        self::assertSame(ResolutionStatus::Blocked, $report->status);
        self::assertCount(1, $report->missing);
        self::assertSame('ghost.cap', $report->missing[0]['id']);
        self::assertSame('MILPA_CAPABILITY_MISSING', $report->missing[0]['code']);
        self::assertSame([], $report->conflicts);
    }

    /**
     * (d) A package that requires a capability it provides itself depends on nobody: the
     * self-dependency is skipped, not reported as a cycle.
     */
    public function testASelfDependencyIsIgnored(): void
    {
        $report = $this->resolve([
            $this->manifest('pkg/self', '1.0.0', provides: ['cap.self'], requires: ['cap.self']),
        ]);

        self::assertSame([['name' => 'pkg/self', 'version' => '1.0.0']], $report->loadOrder);
        self::assertSame([], $report->conflicts);
    }

    /**
     * (e) When two packages provide the same non-exclusive capability, the LAST provider in input
     * order silently wins as the edge source — the documented ContractResolver semantics. Here the
     * dependent boots after ProviderTwo (the last provider), which itself waits for Warmup; if the
     * first provider won, the dependent would boot right after it, before ProviderTwo.
     */
    public function testTheLastProviderOfADuplicatedCapabilityWinsAsTheEdgeSource(): void
    {
        $report = $this->resolve([
            $this->manifest('prov/one', '1.0.0', provides: ['service.shared']),
            $this->manifest('dep/dependent', '1.0.0', requires: ['service.shared']),
            $this->manifest('warm/up', '1.0.0', provides: ['service.warmup']),
            $this->manifest('prov/two', '1.0.0', provides: ['service.shared'], requires: ['service.warmup']),
        ]);

        self::assertSame(
            ['prov/one', 'warm/up', 'prov/two', 'dep/dependent'],
            array_column($report->loadOrder, 'name'),
        );
        self::assertSame([], $report->conflicts, 'a duplicated non-exclusive provider is not a conflict');
    }

    /**
     * (f) A dependency cycle is a learnable, blocking conflict with the exact frozen entry shape: the
     * members (lexicographic, ' <-> '-joined) leave loadOrder[], the independent package stays in it,
     * and the entry's learnable twin carries the live contratos-grafo lesson.
     */
    public function testADependencyCycleBlocksLearnablyAndLeavesTheOrder(): void
    {
        $report = $this->resolve([
            $this->manifest('pkg/zeta', '0.1.0', provides: ['cap.z'], requires: ['cap.a']),
            $this->manifest('pkg/alpha', '0.2.0', provides: ['cap.a'], requires: ['cap.z']),
            $this->manifest('pkg/free', '1.0.0'),
        ]);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        // The exact conflicts[] entry — same frozen key set and order as every conflict.
        self::assertSame(
            [
                'kind' => 'dependency-cycle',
                'id' => 'pkg/alpha <-> pkg/zeta',
                'code' => 'MILPA_DEPENDENCY_CYCLE',
                'providedBy' => ['pkg/alpha@0.2.0', 'pkg/zeta@0.1.0'],
            ],
            $this->withoutReason($report->conflicts[0]),
        );
        self::assertCount(1, $report->conflicts);
        self::assertIsString($report->conflicts[0]['reason']);
        self::assertNotSame('', $report->conflicts[0]['reason']);

        // The cycle members are OUT of the boot order; the independent package keeps its place.
        self::assertSame([['name' => 'pkg/free', 'version' => '1.0.0']], $report->loadOrder);

        // The learnable twin: attached automatically, pointing at the live contratos-grafo unit.
        $errors = array_values(array_filter(
            $report->errors,
            static fn ($error): bool => $error->code === 'MILPA_DEPENDENCY_CYCLE',
        ));
        self::assertCount(1, $errors);
        self::assertStringContainsString('pkg/alpha <-> pkg/zeta', $errors[0]->message);
        self::assertNotSame('', trim($errors[0]->why));
        self::assertNotSame([], $errors[0]->fixes);
        self::assertSame(
            'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
            $errors[0]->links['academy']['es'],
        );
    }

    /**
     * (g) Determinism, byte for byte: the same input resolved twice serializes identically — the
     * unsorted loadOrder[] is deterministic because it is a pure function of the input order.
     */
    public function testTheSameInputResolvesToAByteIdenticalReport(): void
    {
        $manifests = static fn (): array => [
            new VersionManifest('pkg/zeta', '0.1.0', ['implements' => []], ['provides' => ['cap.z'], 'requires' => ['cap.a']]),
            new VersionManifest('pkg/alpha', '0.2.0', ['implements' => []], ['provides' => ['cap.a'], 'requires' => ['cap.z']]),
            new VersionManifest('pkg/free', '1.0.0', ['implements' => []], ['provides' => ['cap.free']]),
            new VersionManifest('pkg/late', '1.0.0', ['implements' => []], ['provides' => [], 'requires' => ['cap.free']]),
        ];
        $host = new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cap.free', 'ghost.cap']);

        $first = $this->resolve($manifests(), $host)->toArray();
        $second = $this->resolve($manifests(), $host)->toArray();

        self::assertSame(json_encode($first), json_encode($second));
    }

    // --- helpers -----------------------------------------------------------------------------------

    /**
     * Resolve a manifest set against a (default bare) host profile.
     *
     * @param list<VersionManifest> $manifests
     */
    private function resolve(array $manifests, ?HostProfile $host = null): ResolutionReport
    {
        return (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: $host ?? new HostProfile('agent-ready', '2026.07'),
            versionManifests: $manifests,
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));
    }

    /**
     * A version manifest whose provides/requires are bare exact-string capability ids.
     *
     * @param list<string> $provides
     * @param list<string> $requires
     */
    private function manifest(string $package, string $version, array $provides = [], array $requires = []): VersionManifest
    {
        return new VersionManifest(
            package: $package,
            version: $version,
            contracts: ['implements' => []],
            capabilities: ['provides' => $provides, 'requires' => $requires],
        );
    }

    /**
     * The entry minus its free-text reason, so the frozen fields can be asserted exactly.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function withoutReason(array $entry): array
    {
        unset($entry['reason']);

        return $entry;
    }
}
