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
 * Legacy paths must TEACH, not just resolve. Two ledger-carried Minors closed here (M4 of the 0.1
 * final review, the T5 slice-1 concern): a PERMITTED legacy path now carries its learnable
 * `MILPA_LEGACY_CONTRACT_ACTIVE` into the agent `errors[]` (until now the code was catalogued but
 * never attached), and a legacy CAPABILITY now emits its own `migrationHints[]` entry (until now only
 * legacy contracts did). Both are strictly BC-additive: `errors[]` and `migrationHints[]` only gain
 * entries, never lose or reshape one.
 *
 * Dedupe decision — the legacy attachment is scoped to PERMITTED entries. An un-permitted legacy path
 * is blocked, not active: it already teaches through its `missing[]` twin (`MILPA_LEGACY_NOT_ALLOWED`),
 * so attaching an "active" lesson on top would be both a duplicate for the same underlying entry and a
 * contradiction. Permitted legacy degrades to legacy_compatible and is genuinely active, so
 * `MILPA_LEGACY_CONTRACT_ACTIVE` ("allowed, but never silent") is exactly its lesson.
 */
final class LegacyLearnabilityTest extends TestCase
{
    /**
     * M4 — a permitted legacy CONTRACT carries its learnable error into errors[], with the live
     * Academy learn links and the migrate-contract action, and WITHOUT degrading the status: a
     * tolerated legacy path stays legacy_compatible, it does not block.
     */
    public function testPermittedLegacyContractTeachesThroughErrorsWithoutDegradingStatus(): void
    {
        $report = $this->resolve($this->legacyContractInput(['command.host']));

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);
        self::assertSame([], $report->missing);

        $error = $this->errorBy($report, 'MILPA_LEGACY_CONTRACT_ACTIVE');
        self::assertNotNull($error, 'permitted legacy contract must teach through errors[]');
        self::assertSame('command.host', $error['context']['id']);
        self::assertSame('legacy/command-host@0.0.1', $error['context']['providedBy']);
        self::assertSame('agent-ready@2026.07', $error['context']['hostProfile']);
        // The legacy[] entry carries no `requiredBy`, so the error context honestly omits it (context is
        // free-form: only the fields the source entry actually holds).
        self::assertArrayNotHasKey('requiredBy', $error['context']);

        // The learnable error is complete: why + fixes + live Academy learn links + a typed action.
        self::assertNotSame('', $error['why']);
        self::assertNotSame([], $error['fixes']);
        self::assertSame(
            'https://academy.milpa.lat/learn/arquitectura/legacy-y-migracion/',
            $error['learn']['academy']['es'],
        );
        self::assertContains(['type' => 'migrate-contract', 'contract' => 'command.host'], $error['recommendedActions']);
    }

    /**
     * M4 — a permitted legacy CAPABILITY (a legacy-shaped manifest that provides a required capability)
     * teaches the same way: its MILPA_LEGACY_CONTRACT_ACTIVE reaches errors[].
     */
    public function testPermittedLegacyCapabilityTeachesThroughErrors(): void
    {
        $report = $this->resolve($this->legacyCapabilityInput(['*']));

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);

        $error = $this->errorBy($report, 'MILPA_LEGACY_CONTRACT_ACTIVE');
        self::assertNotNull($error, 'permitted legacy capability must teach through errors[]');
        self::assertSame('legacy.cap', $error['context']['id']);
    }

    /**
     * The dedupe guard — an UN-permitted legacy path is not double-taught. It teaches once, through its
     * missing[] twin (MILPA_LEGACY_NOT_ALLOWED); NO MILPA_LEGACY_CONTRACT_ACTIVE rides in on top for the
     * same underlying entry. Exactly one error, coded for the block.
     */
    public function testUnpermittedLegacyIsTaughtOnceThroughItsMissingTwin(): void
    {
        $report = $this->resolve($this->legacyContractInput([]));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors, 'an un-permitted legacy path must not produce a duplicate error');
        self::assertSame('MILPA_LEGACY_NOT_ALLOWED', $errors[0]['code']);
        self::assertNull($this->errorBy($report, 'MILPA_LEGACY_CONTRACT_ACTIVE'));
    }

    /**
     * The T5 slice-1 Minor — a legacy CAPABILITY now emits a per-entry migrationHint with the canonical
     * `to: 'capabilities.*'` shape, consistent with the existing legacy-contract hints.
     */
    public function testLegacyCapabilityEmitsPerEntryMigrationHint(): void
    {
        $report = $this->resolve($this->legacyCapabilityInput(['*']));

        $hint = $this->entryBy($report->migrationHints, 'id', 'legacy.cap');
        self::assertNotNull($hint, 'a legacy capability must emit its own migration hint');
        self::assertSame('capabilities.*', $hint['to']);
        self::assertSame('0.0.1', $hint['from']);
        self::assertNull($hint['migrationUrl']);
        self::assertNotSame('', $hint['message']);
        self::assertSame(['id', 'from', 'to', 'migrationUrl', 'message'], array_keys($hint));
    }

    /**
     * The CRM-like corpus: a legacy-shaped manifest provides EIGHT required capabilities, all permitted.
     * The report emits exactly 8 migration hints (each `to: 'capabilities.*'`) and 8 learnable
     * MILPA_LEGACY_CONTRACT_ACTIVE errors — one per legacy capability, and it stays byte-deterministic.
     */
    public function testCrmCorpusOfEightLegacyCapabilitiesEmitsEightHintsAndErrorsDeterministically(): void
    {
        $input = $this->crmLegacyCapabilitiesInput();
        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);

        // Exactly one migration hint per legacy capability, each to the canonical capabilities.* shape.
        self::assertCount(8, $report->migrationHints);
        foreach ($report->migrationHints as $hint) {
            self::assertSame('capabilities.*', $hint['to']);
        }

        // Exactly one learnable "legacy active" error per legacy capability.
        $active = array_values(array_filter(
            $report->toArray()['errors'],
            static fn (array $e): bool => $e['code'] === 'MILPA_LEGACY_CONTRACT_ACTIVE',
        ));
        self::assertCount(8, $active);

        // Byte-deterministic across two independent resolutions.
        $a = $this->resolve($input)->toArray();
        $b = $this->resolve($input)->toArray();
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
     * A CRM-like host requiring eight capabilities, all served by a single legacy-shaped manifest the
     * host tolerates wholesale (`allowedLegacyContracts: ["*"]`).
     */
    private function crmLegacyCapabilitiesInput(): ResolutionInput
    {
        $ids = [
            'crm.contacts', 'crm.deals', 'crm.pipeline', 'crm.tasks',
            'crm.notes', 'crm.email', 'crm.calendar', 'crm.reports',
        ];

        $provides = array_map(
            static fn (string $id): array => ['id' => $id, 'interface' => 'I', 'contractVersion' => '0.0.1'],
            $ids,
        );

        return new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'acme-crm',
                version: '2026.07',
                requiredCapabilities: $ids,
                allowedLegacyContracts: ['*'],
            ),
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/crm-suite',
                    version: '0.0.1',
                    contracts: ['implements' => []],
                    capabilities: ['provides' => $provides, 'requires' => [], 'suggests' => []],
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
