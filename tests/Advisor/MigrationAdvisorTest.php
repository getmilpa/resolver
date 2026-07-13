<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Advisor;

use Milpa\Resolver\Advisor\MigrationAdvisor;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\DriftDetector;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\AcceptedRisk;
use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Report\ResolutionReport;
use PHPUnit\Framework\TestCase;

/**
 * The MigrationAdvisor turns a frozen {@see ResolutionReport} into an actionable
 * {@see \Milpa\Resolver\Advisor\MigrationPlan} — spec §6's separation of duties: the resolver
 * detects and explains, the Advisor PROPOSES, and only a human-approved command ever executes.
 * Every example here is engine-generated (real inputs → GraphResolver->resolve() → advise()),
 * never a hand-written report, so the plan is always proven against what the engine actually
 * emits. The brief's six cases: (a) a permitted legacy path becomes one package with a
 * legacy-contract detection, migration steps, and the wildcard compatibility line; (b) caller-built
 * drift errors merge into the SAME package instead of duplicating it; (c) a blocked report plans
 * the install from the error's fixes[0]; (d) a valid report yields the visible empty plan (0/0,
 * never null); (e) the same input advises byte-identically; (f) the last step of every package is
 * ALWAYS re-running `coa:inspect architecture`.
 */
final class MigrationAdvisorTest extends TestCase
{
    private const REINSPECT = 'Run php coa coa:inspect architecture again.';

    /**
     * Case (a) — a CRM-like host closing a required contract through a permitted legacy manifest
     * (wildcard allowance) plans exactly one package: the legacy provider, with a legacy-contract
     * detection carrying the catalog code and the engine's reason verbatim.
     */
    public function testALegacyContractBecomesOnePackageWithALegacyContractDetection(): void
    {
        $plan = $this->advisor()->advise($this->legacyReport(), [], $this->legacyProfile())->toArray();

        self::assertSame('legacy_compatible', $plan['status']);
        self::assertIsArray($plan['packages']);
        self::assertCount(1, $plan['packages'], 'only the legacy provider is actionable — the host package must not appear');

        $package = $plan['packages'][0];
        self::assertIsArray($package);
        self::assertSame('legacy/command-host', $package['package']);
        self::assertSame(
            [
                [
                    'kind' => 'legacy-contract',
                    'id' => 'command.host',
                    'code' => 'MILPA_LEGACY_CONTRACT_ACTIVE',
                    'detail' => 'Contract "command.host" is satisfied by the legacy-shaped manifest "legacy/command-host@0.0.1".',
                ],
            ],
            $package['detected'],
        );
    }

    /**
     * Case (a), steps — the migration step cites the canonical shape (contracts.* to capabilities.*
     * records, straight from the legacy error's fixes[0]) and the recommended entry names the
     * migration hint's concrete target version.
     */
    public function testALegacyContractPlansTheCanonicalShapeMigration(): void
    {
        $plan = $this->advisor()->advise($this->legacyReport(), [], $this->legacyProfile())->toArray();

        self::assertIsArray($plan['packages'][0]);
        $package = $plan['packages'][0];

        self::assertSame(
            [
                ['n' => 1, 'action' => 'Migrate "command.host" to the canonical contract shape (contracts.* to capabilities.* records).'],
                ['n' => 2, 'action' => self::REINSPECT],
            ],
            $package['steps'],
        );
        self::assertSame([['id' => 'command.host', 'to' => '0.1']], $package['recommended']);
    }

    /**
     * Case (a), compatibility — the wildcard allowance is stated honestly: no deadline exists and
     * none is invented; the plan teaches how to set one.
     */
    public function testAWildcardAllowanceStatesTheHonestNoDeadlineCompatibility(): void
    {
        $plan = $this->advisor()->advise($this->legacyReport(), [], $this->legacyProfile())->toArray();

        self::assertIsArray($plan['packages'][0]);
        self::assertSame(
            'allowedLegacyContracts: * — no deadline; declare an explicit list to set one',
            $plan['packages'][0]['compatibility'],
        );
    }

    /**
     * Case (a), academy — the package's academy links are the live bilingual pairs the report's
     * learnable errors already carry (never invented, never hardcoded anew).
     */
    public function testAcademyLinksComeFromTheReportsLearnableErrors(): void
    {
        $report = $this->legacyReport();
        $plan = $this->advisor()->advise($report, [], $this->legacyProfile())->toArray();

        $legacyError = null;
        foreach ($report->errors as $error) {
            if ($error->code === 'MILPA_LEGACY_CONTRACT_ACTIVE') {
                $legacyError = $error;

                break;
            }
        }
        self::assertNotNull($legacyError, 'the engine must attach the legacy learnable error');

        self::assertIsArray($plan['packages'][0]);
        self::assertSame([$legacyError->links['academy']], $plan['packages'][0]['academy']);
    }

    /**
     * An explicit allowlist is NAMED in the compatibility line — the real posture of the profile,
     * not a paraphrase.
     */
    public function testAnExplicitAllowlistIsNamedInTheCompatibilityLine(): void
    {
        $profile = new HostProfile(
            name: 'crm-host',
            version: '2026.07',
            requiredContracts: ['command.host@0.0'],
            allowedLegacyContracts: ['command.host'],
        );
        $report = $this->resolve($this->legacyInput($profile));

        $plan = $this->advisor()->advise($report, [], $profile)->toArray();

        self::assertIsArray($plan['packages'][0]);
        self::assertSame(
            'allowedLegacyContracts: ["command.host"] — explicit allowance, no deadline declared',
            $plan['packages'][0]['compatibility'],
        );
    }

    /**
     * An accepted risk with an expiry over one of the package's detected codes IS the compatibility
     * window: the plan names the real date the acceptance lapses — the one honest deadline available.
     */
    public function testAnAcceptedRiskExpiryOverADetectedCodeBecomesTheCompatibilityDate(): void
    {
        $profile = new HostProfile(
            name: 'crm-host',
            version: '2026.07',
            requiredContracts: ['command.host@0.0'],
            allowedLegacyContracts: ['*'],
            acceptedRisks: [new AcceptedRisk('MILPA_LEGACY_CONTRACT_ACTIVE', 'Migration scheduled for Q4.', '2026-12-31')],
        );
        $report = $this->resolve($this->legacyInput($profile, evaluatedAt: '2026-07-01'));

        $plan = $this->advisor()->advise($report, [], $profile)->toArray();

        self::assertIsArray($plan['packages'][0]);
        self::assertSame(
            'acceptedRisk "MILPA_LEGACY_CONTRACT_ACTIVE" expires 2026-12-31 — the acceptance lapses on that date',
            $plan['packages'][0]['compatibility'],
        );
    }

    /**
     * Without the host profile the allowance is NOT recoverable from the report (its frozen metadata
     * carries only the `hostProfile` label and the verbatim `hostMetadata`), so the advisor says so
     * instead of inventing a window.
     */
    public function testWithoutTheProfileTheCompatibilityIsHonestlyUnknown(): void
    {
        $plan = $this->advisor()->advise($this->legacyReport())->toArray();

        self::assertIsArray($plan['packages'][0]);
        self::assertSame(
            'allowedLegacyContracts unknown — the host profile was not supplied to the advisor, so no compatibility window can be stated',
            $plan['packages'][0]['compatibility'],
        );
    }

    /**
     * Case (b) — caller-built drift errors (the exact {@see LearnableArchitectureError} objects
     * {@see DriftDetector::toLearnableErrors()} returns) merge into the SAME package entry as the
     * legacy detection: one package, both detections, plus the regenerate step.
     */
    public function testDriftErrorsMergeIntoTheSamePackageEntry(): void
    {
        $plan = $this->advisor()
            ->advise($this->legacyReport(), $this->driftErrors(), $this->legacyProfile())
            ->toArray();

        self::assertIsArray($plan['packages']);
        self::assertCount(1, $plan['packages'], 'drift on the same package must merge, never duplicate');

        $package = $plan['packages'][0];
        self::assertIsArray($package);
        self::assertIsArray($package['detected']);
        self::assertSame(
            ['legacy-contract', 'manifest-drift'],
            array_column($package['detected'], 'kind'),
        );
        self::assertIsArray($package['detected'][1]);
        self::assertSame('MILPA_MANIFEST_DRIFT', $package['detected'][1]['code']);
        self::assertSame('legacy/command-host', $package['detected'][1]['id']);

        self::assertIsArray($package['steps']);
        $actions = array_column($package['steps'], 'action');
        self::assertContains(
            'Regenerate the manifest from the code: php coa coa:plugins manifest legacy/command-host.',
            $actions,
            'the drift detection must plan the regenerate step from its fixes[0]',
        );
        self::assertSame(self::REINSPECT, $actions[count($actions) - 1]);
    }

    /**
     * Case (c) — a blocked report with a missing capability plans the install: the step is the
     * error's fixes[0] verbatim, the detection groups under the requirer (the host profile's NAME,
     * per the grouping rule: attribution label minus its scheme prefix and `@version` suffix), and
     * the recommended entry names the canonical package with the requirement's constraint.
     */
    public function testABlockedReportPlansTheInstallFromTheErrorsFirstFix(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['command.provider']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));
        self::assertSame('blocked', $report->status->value);

        $plan = $this->advisor()->advise($report)->toArray();

        self::assertSame('blocked', $plan['status']);
        self::assertIsArray($plan['packages']);
        self::assertCount(1, $plan['packages']);

        $package = $plan['packages'][0];
        self::assertIsArray($package);
        self::assertSame('crm-host', $package['package']);
        self::assertSame(
            [
                [
                    'kind' => 'missing',
                    'id' => 'command.provider',
                    'code' => 'MILPA_CAPABILITY_MISSING',
                    'detail' => 'No active provider offers the capability "command.provider".',
                ],
            ],
            $package['detected'],
        );
        self::assertSame(
            [
                ['n' => 1, 'action' => 'Install milpa/command, which provides "command.provider".'],
                ['n' => 2, 'action' => self::REINSPECT],
            ],
            $package['steps'],
        );
        self::assertSame(
            [['id' => 'command.provider', 'to' => 'milpa/command', 'constraint' => '*']],
            $package['recommended'],
        );
        self::assertSame('no legacy allowance in play — no compatibility window applies', $package['compatibility']);
    }

    /**
     * Case (d) — a valid report with no drift yields the visible empty plan: status kept, zero
     * packages, summary 0/0. Nothing to do is a stated fact, never a null.
     */
    public function testAValidReportYieldsTheVisibleEmptyPlan(): void
    {
        $report = $this->resolve($this->validInput());
        self::assertSame('valid', $report->status->value);

        $plan = $this->advisor()->advise($report, [], $this->validInput()->hostProfile);

        self::assertSame(
            [
                'status' => 'valid',
                'packages' => [],
                'summary' => ['packages' => 0, 'actions' => 0],
            ],
            $plan->toArray(),
        );
    }

    /**
     * Case (e) — determinism, byte for byte: two independent resolve()+advise() runs over the same
     * materialized input serialize identically. The advisor adds no clock, no filesystem, no
     * randomness on top of the engine's own purity.
     */
    public function testTheSameInputAdvisesByteIdentically(): void
    {
        $first = $this->advisor()->advise($this->legacyReport(), $this->driftErrors(), $this->legacyProfile());
        $second = $this->advisor()->advise($this->legacyReport(), $this->driftErrors(), $this->legacyProfile());

        self::assertSame(json_encode($first->toArray()), json_encode($second->toArray()));
    }

    /**
     * Case (f) — the LAST step of every package, in every plan, is re-running the inspection: a plan
     * that does not end in verification is a plan that trusts itself, and the advisor never does.
     */
    public function testTheLastStepOfEveryPackageIsAlwaysTheReInspect(): void
    {
        $plans = [
            $this->advisor()->advise($this->legacyReport(), [], $this->legacyProfile())->toArray(),
            $this->advisor()->advise($this->legacyReport(), $this->driftErrors(), $this->legacyProfile())->toArray(),
            $this->advisor()->advise($this->resolve(new ResolutionInput(
                hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['command.provider', 'tool.registry']),
                versionManifests: [],
                contractManifests: [],
                capabilityProvisions: [],
                capabilityRequirements: [],
            )))->toArray(),
        ];

        $packages = 0;
        foreach ($plans as $plan) {
            self::assertIsArray($plan['packages']);
            foreach ($plan['packages'] as $package) {
                self::assertIsArray($package);
                self::assertIsArray($package['steps']);
                $last = $package['steps'][count($package['steps']) - 1];
                self::assertIsArray($last);
                self::assertSame(self::REINSPECT, $last['action']);
                self::assertSame(count($package['steps']), $last['n'], 'steps are numbered 1..n with no gaps');
                ++$packages;
            }
        }

        self::assertGreaterThan(0, $packages, 'the case plans produced no packages to prove the rule over');
    }

    /**
     * A legacy CAPABILITY path is its own detection kind, and its recommendation targets the
     * canonical `capabilities.*` shape the migration hint names.
     */
    public function testALegacyCapabilityDetectsAndRecommendsTheCanonicalShape(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['legacy.cap'], allowedLegacyContracts: ['*']),
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
        ));

        $plan = $this->advisor()->advise($report)->toArray();

        self::assertIsArray($plan['packages']);
        self::assertCount(1, $plan['packages']);
        $package = $plan['packages'][0];
        self::assertIsArray($package);
        self::assertSame('legacy/cap-host', $package['package']);
        self::assertIsArray($package['detected']);
        self::assertIsArray($package['detected'][0]);
        self::assertSame('legacy-capability', $package['detected'][0]['kind']);
        self::assertSame([['id' => 'legacy.cap', 'to' => 'capabilities.*']], $package['recommended']);
    }

    // --- fixtures ------------------------------------------------------------------------------

    private function advisor(): MigrationAdvisor
    {
        return new MigrationAdvisor();
    }

    private function resolve(ResolutionInput $input): ResolutionReport
    {
        return (new GraphResolver())->resolve($input);
    }

    /**
     * The CRM-like fixture: a host that requires `command.host@0.0`, closed only by a legacy-shaped
     * manifest, with a contract manifest declaring the canonical 0.1 target.
     */
    private function legacyReport(): ResolutionReport
    {
        return $this->resolve($this->legacyInput($this->legacyProfile()));
    }

    private function legacyProfile(): HostProfile
    {
        return new HostProfile(
            name: 'crm-host',
            version: '2026.07',
            requiredContracts: ['command.host@0.0'],
            allowedLegacyContracts: ['*'],
        );
    }

    private function legacyInput(HostProfile $profile, ?string $evaluatedAt = null): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: $profile,
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/command-host',
                    version: '0.0.1',
                    contracts: ['implements' => ['command.host@0.0'], 'requires' => []],
                    capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
                    metadata: ['shape' => 'legacy-contracts'],
                ),
            ],
            contractManifests: [
                new ContractManifest(id: 'command.host', version: '0.1'),
            ],
            capabilityProvisions: [],
            capabilityRequirements: [],
            evaluatedAt: $evaluatedAt,
        );
    }

    /**
     * Drift on the SAME package the legacy fixture resolves through — built exactly the way the
     * host's inspect surface builds it: two real manifests diffed by the {@see DriftDetector},
     * lifted to the learnable-error objects the advisor accepts verbatim.
     *
     * @return list<LearnableArchitectureError>
     */
    private function driftErrors(): array
    {
        $detector = new DriftDetector();
        $declared = new VersionManifest(
            package: 'legacy/command-host',
            version: '0.0.1',
            contracts: ['implements' => ['command.host@0.0']],
            capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
        );
        $actual = new VersionManifest(
            package: 'legacy/command-host',
            version: '0.0.2',
            contracts: ['implements' => ['command.host@0.0']],
            capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
        );

        return $detector->toLearnableErrors($detector->diff($declared, $actual), 'legacy/command-host');
    }

    private function validInput(): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['command.provider']),
            versionManifests: [
                new VersionManifest(
                    package: 'milpa/command',
                    version: '0.1.0',
                    contracts: ['implements' => []],
                    capabilities: ['provides' => [['id' => 'command.provider', 'interface' => 'Cmd', 'contractVersion' => '0.1.0']]],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );
    }
}
