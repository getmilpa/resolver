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
 * `allowedLegacyContracts` is a GATE (0.2), not advisory: a legacy path the host does not permit
 * blocks. Enforcement enters the status THROUGH a `missing[]` entry (`kind: legacy-contract`, code
 * `MILPA_LEGACY_NOT_ALLOWED`), while the same denial stays visible in `legacy[]` as `permitted: false`
 * — both views carry it. `["*"]` permits every legacy path, `[]` permits none, an explicit list is
 * selective. The truth table is unchanged: an un-permitted legacy path is a missing requirement, so
 * the existing `missing !== [] → blocked` rule does all the work.
 */
final class LegacyEnforcementTest extends TestCase
{
    /**
     * Explicitly permitted (the §15-3 shape): the host names the contract in allowedLegacyContracts,
     * so the legacy path is tolerated and the graph is legacy_compatible — no missing entry.
     */
    public function testExplicitlyPermittedLegacyIsLegacyCompatible(): void
    {
        $report = $this->resolve($this->legacyContractInput(['command.host']));

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);
        self::assertSame([], $report->missing);

        $legacy = $this->entryBy($report->legacy, 'id', 'command.host');
        self::assertNotNull($legacy);
        self::assertTrue($legacy['permitted']);
    }

    /**
     * Wildcard ["*"] permits every legacy path — the CRM's real posture — so a legacy dependency
     * resolves to legacy_compatible and no MILPA_LEGACY_NOT_ALLOWED is raised.
     */
    public function testWildcardPermitsAllLegacy(): void
    {
        $report = $this->resolve($this->legacyContractInput(['*']));

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);
        self::assertNull($this->entryBy($report->missing, 'code', 'MILPA_LEGACY_NOT_ALLOWED'));

        $legacy = $this->entryBy($report->legacy, 'id', 'command.host');
        self::assertNotNull($legacy);
        self::assertTrue($legacy['permitted']);
    }

    /**
     * NOT permitted: the host restricts legacy but this contract is not in the allowlist. The graph
     * blocks — the block enters via a missing[] legacy-contract entry — and the legacy[] entry stays
     * visible with permitted:false. Both views carry the denial, and the block teaches.
     */
    public function testUnpermittedLegacyBlocksViaMissingAndStaysVisibleInLegacy(): void
    {
        $report = $this->resolve($this->legacyContractInput([]));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        // Enforcement enters through missing[] (truth table untouched).
        $missing = $this->entryBy($report->missing, 'id', 'command.host');
        self::assertNotNull($missing);
        self::assertSame('legacy-contract', $missing['kind']);
        self::assertSame('MILPA_LEGACY_NOT_ALLOWED', $missing['code']);
        self::assertSame('required', $missing['level']);
        self::assertNull($missing['constraint']);
        self::assertNull($missing['surface']);
        self::assertSame('hostProfile:agent-ready@2026.07', $missing['requiredBy']);

        // The same denial is still visible in legacy[] as permitted:false (both views).
        $legacy = $this->entryBy($report->legacy, 'id', 'command.host');
        self::assertNotNull($legacy);
        self::assertFalse($legacy['permitted']);

        // The block teaches: the new code rides into the agent errors[].
        self::assertNotNull($this->errorBy($report, 'MILPA_LEGACY_NOT_ALLOWED'));
    }

    /**
     * An empty allowlist blocks ALL legacy — including a legacy-shaped capability provider.
     */
    public function testEmptyAllowlistBlocksLegacyCapability(): void
    {
        $report = $this->resolve($this->legacyCapabilityInput([]));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'legacy.cap');
        self::assertNotNull($missing);
        self::assertSame('legacy-contract', $missing['kind']);
        self::assertSame('MILPA_LEGACY_NOT_ALLOWED', $missing['code']);
        self::assertNull($missing['constraint']);

        $legacy = $this->entryBy($report->legacy, 'id', 'legacy.cap');
        self::assertNotNull($legacy);
        self::assertFalse($legacy['permitted']);
    }

    /**
     * Mixed: two legacy paths, only one permitted. The graph blocks, and ONLY the un-permitted one
     * appears in missing[]; both remain in legacy[] with their true permitted flag.
     */
    public function testMixedAllowlistBlocksOnlyTheUnpermittedLegacy(): void
    {
        $report = $this->resolve($this->twoLegacyContractsInput(['command.host']));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        // Only the un-permitted contract is a legacy-contract block.
        $blocks = array_values(array_filter(
            $report->missing,
            static fn (array $e): bool => ($e['code'] ?? null) === 'MILPA_LEGACY_NOT_ALLOWED',
        ));
        self::assertCount(1, $blocks);
        self::assertSame('other.host', $blocks[0]['id']);

        // Both remain visible in legacy[] with their true flags.
        $permitted = $this->entryBy($report->legacy, 'id', 'command.host');
        $denied = $this->entryBy($report->legacy, 'id', 'other.host');
        self::assertNotNull($permitted);
        self::assertNotNull($denied);
        self::assertTrue($permitted['permitted']);
        self::assertFalse($denied['permitted']);
    }

    /**
     * The enforcement is DATA (a missing entry), not a new status rule. The wildcard and empty-allowlist
     * cases wire the identical legacy path — the same visible legacy[] entry — so the ONLY structural
     * difference that flips legacy_compatible → blocked is the injected missing[] entry. determineStatus
     * never inspects `permitted`; the existing `missing !== [] → blocked` rule does all the work.
     */
    public function testEnforcementEntersThroughMissingNotAStatusRule(): void
    {
        $permitted = $this->resolve($this->legacyContractInput(['*']));
        $blocked = $this->resolve($this->legacyContractInput([]));

        // Same legacy path present in both reports.
        self::assertNotNull($this->entryBy($permitted->legacy, 'id', 'command.host'));
        self::assertNotNull($this->entryBy($blocked->legacy, 'id', 'command.host'));

        // Permitted: empty missing list → legacy_compatible.
        self::assertSame([], $permitted->missing);
        self::assertSame(ResolutionStatus::LegacyCompatible, $permitted->status);

        // Un-permitted: the SAME graph gains exactly one missing[] entry and blocks on it.
        self::assertCount(1, $blocked->missing);
        self::assertSame('MILPA_LEGACY_NOT_ALLOWED', $blocked->missing[0]['code']);
        self::assertSame(ResolutionStatus::Blocked, $blocked->status);
    }

    /**
     * The report stays byte-deterministic across the enforcement path.
     */
    public function testUnpermittedLegacyReportIsByteDeterministic(): void
    {
        $a = $this->resolve($this->twoLegacyContractsInput(['command.host']))->toArray();
        $b = $this->resolve($this->twoLegacyContractsInput(['command.host']))->toArray();

        self::assertSame(json_encode($a), json_encode($b));
    }

    // --- inputs -----------------------------------------------------------------------------------

    /**
     * @param list<string> $allowedLegacyContracts
     */
    private function legacyContractInput(array $allowedLegacyContracts): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredContracts: ['command.host@0.0'],
                allowedLegacyContracts: $allowedLegacyContracts,
            ),
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/command-host',
                    version: '0.0.1',
                    contracts: ['implements' => ['command.host@0.0'], 'requires' => []],
                    capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
                    metadata: ['shape' => 'legacy-contracts'],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );
    }

    /**
     * @param list<string> $allowedLegacyContracts
     */
    private function legacyCapabilityInput(array $allowedLegacyContracts): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredCapabilities: ['legacy.cap'],
                allowedLegacyContracts: $allowedLegacyContracts,
            ),
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/cap-host',
                    version: '0.0.1',
                    contracts: ['implements' => []],
                    capabilities: ['provides' => [['id' => 'legacy.cap', 'interface' => 'L', 'contractVersion' => '0.0.1']]],
                    metadata: ['shape' => 'legacy-contracts'],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );
    }

    /**
     * @param list<string> $allowedLegacyContracts
     */
    private function twoLegacyContractsInput(array $allowedLegacyContracts): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredContracts: ['command.host@0.0', 'other.host@0.0'],
                allowedLegacyContracts: $allowedLegacyContracts,
            ),
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/command-host',
                    version: '0.0.1',
                    contracts: ['implements' => ['command.host@0.0'], 'requires' => []],
                    capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
                    metadata: ['shape' => 'legacy-contracts'],
                ),
                new VersionManifest(
                    package: 'legacy/other-host',
                    version: '0.0.1',
                    contracts: ['implements' => ['other.host@0.0'], 'requires' => []],
                    capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
                    metadata: ['shape' => 'legacy-contracts'],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );
    }

    // --- helpers ----------------------------------------------------------------------------------

    private function resolve(ResolutionInput $input): ResolutionReport
    {
        return (new GraphResolver())->resolve($input);
    }

    /**
     * @param list<array<string, mixed>> $list
     *
     * @return array<string, mixed>|null
     */
    private function entryBy(array $list, string $key, string $value): ?array
    {
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry[$key] ?? null) === $value) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function errorBy(ResolutionReport $report, string $code): ?array
    {
        foreach ($report->toArray()['errors'] as $error) {
            if (is_array($error) && ($error['code'] ?? null) === $code) {
                /** @var array<string, mixed> $error */
                return $error;
            }
        }

        return null;
    }
}
