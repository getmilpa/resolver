<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Contracts\ArchitectureResolver;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\AcceptedRisk;
use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use PHPUnit\Framework\TestCase;

/**
 * The engine, driven by the five mandatory scenarios of spec §15 plus the constraint,
 * conflict, accepted-risk, determinism and edge cases the brief calls for.
 */
final class GraphResolverTest extends TestCase
{
    public function testTheEngineIsAnArchitectureResolver(): void
    {
        self::assertInstanceOf(ArchitectureResolver::class, new GraphResolver());
    }

    /**
     * Scenario 1 — capability faltante: the host requires command.provider, nobody provides it.
     */
    public function testScenario1MissingRequiredCapabilityBlocks(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredCapabilities: ['command.provider', 'tool.registry'],
            ),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('tool.registry', 'Tool\\Registry', '0.1.0')],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'command.provider');
        self::assertNotNull($missing);
        self::assertSame('capability', $missing['kind']);
        self::assertSame('MILPA_CAPABILITY_MISSING', $missing['code']);
        self::assertSame('required', $missing['level']);
        self::assertSame('hostProfile:agent-ready@2026.07', $missing['requiredBy']);

        // The satisfied requirement is still reported as resolved.
        self::assertNotNull($this->entryBy($report->resolved, 'id', 'tool.registry'));
    }

    /**
     * Scenario 2 — suggested faltante: audit.sink is suggested but has no provider.
     */
    public function testScenario2MissingSuggestedCapabilityIsBootableWithWarnings(): void
    {
        $manifest = new VersionManifest(
            package: 'milpa/command',
            version: '0.1.0',
            contracts: ['implements' => ['milpa.command@0.1']],
            capabilities: [
                'provides' => [['id' => 'command.provider', 'interface' => 'Cmd', 'contractVersion' => '0.1.0']],
                'requires' => [],
                'suggests' => ['audit.sink'],
            ],
        );

        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['command.provider']),
            versionManifests: [$manifest],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('command.provider', 'Cmd', '0.1.0')],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);
        self::assertSame([], $report->missing);

        $warning = $this->entryBy($report->warnings, 'id', 'audit.sink');
        self::assertNotNull($warning);
        self::assertSame('suggested-capability', $warning['kind']);
        self::assertSame('MILPA_SUGGESTED_CAPABILITY_MISSING', $warning['code']);
        self::assertFalse($warning['accepted']);
    }

    /**
     * Scenario 3 — legacy contract activo: command.host@0.0 is served by a legacy-shaped manifest
     * the host tolerates; a migration hint is emitted and the legacy stays visible.
     */
    public function testScenario3LegacyContractActiveIsLegacyCompatible(): void
    {
        $legacyManifest = new VersionManifest(
            package: 'legacy/command-host',
            version: '0.0.1',
            contracts: ['implements' => ['command.host@0.0'], 'requires' => []],
            capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
            metadata: ['shape' => 'legacy-contracts'],
        );

        $input = new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredContracts: ['command.host@0.0'],
                allowedLegacyContracts: ['command.host'],
            ),
            versionManifests: [$legacyManifest],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);
        self::assertSame([], $report->missing);

        $legacy = $this->entryBy($report->legacy, 'id', 'command.host');
        self::assertNotNull($legacy);
        self::assertSame('MILPA_LEGACY_CONTRACT_ACTIVE', $legacy['code']);
        self::assertTrue($legacy['permitted']);
        self::assertSame('legacy/command-host@0.0.1', $legacy['providedBy']);

        self::assertNotSame([], $report->migrationHints);
        self::assertNotNull($this->entryBy($report->migrationHints, 'id', 'command.host'));
    }

    /**
     * Scenario 4 — surface incompleta: MCP is active but mcp.transport is absent.
     */
    public function testScenario4UnmetSurfaceRequirementBlocks(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', enabledSurfaces: ['mcp']),
            versionManifests: [
                new VersionManifest('milpa/mcp-server', '0.1.0', ['implements' => []], ['provides' => []], surfaces: ['supports' => ['mcp']]),
            ],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('tool.registry', 'I', '0.1.0'),
                new CapabilityProvision('principal.resolver', 'I', '0.1.0'),
            ],
            capabilityRequirements: [],
            activeSurfaces: ['mcp'],
            environment: [
                'surfaces' => [
                    ['surface' => 'mcp', 'requires' => ['tool.registry', 'mcp.transport', 'principal.resolver'], 'warnings' => []],
                ],
            ],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'mcp.transport');
        self::assertNotNull($missing);
        self::assertSame('surface-requirement', $missing['kind']);
        self::assertSame('MILPA_SURFACE_REQUIREMENT_UNMET', $missing['code']);
        self::assertSame('mcp', $missing['surface']);
        self::assertSame('surface:mcp', $missing['requiredBy']);
    }

    /**
     * Scenario 5 — conflict provider: two providers both claim exclusive persistence.store.
     */
    public function testScenario5ExclusiveConflictBlocks(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['persistence.store']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\MysqlStore', exclusive: true),
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\SqliteStore', exclusive: true),
            ],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $conflict = $this->entryBy($report->conflicts, 'id', 'persistence.store');
        self::assertNotNull($conflict);
        self::assertSame('MILPA_CAPABILITY_CONFLICT', $conflict['code']);
        self::assertSame(['App\\MysqlStore', 'App\\SqliteStore'], $conflict['providedBy']);
    }

    /**
     * A same-id provider exists but its version falls outside the required constraint.
     */
    public function testContractVersionUnsupportedBlocks(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredContracts: ['milpa.persistence@^2.0']),
            versionManifests: [
                new VersionManifest('milpa/persistence', '1.4.0', ['implements' => ['milpa.persistence@1.4.0']], ['provides' => []]),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'milpa.persistence');
        self::assertNotNull($missing);
        self::assertSame('contract', $missing['kind']);
        self::assertSame('MILPA_CONTRACT_VERSION_UNSUPPORTED', $missing['code']);
        self::assertSame('^2.0', $missing['constraint']);
    }

    /**
     * A oneOf requirement is satisfied by its second listed alternative.
     */
    public function testOneOfSatisfiedBySecondCandidateIsValid(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('log.syslog', 'Log', '0.1.0', service: 'App\\SyslogLogger')],
            capabilityRequirements: [
                new CapabilityRequirement('log.sink', 'Log', '*', ['log.file', 'log.syslog']),
            ],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $resolved = $this->entryBy($report->resolved, 'id', 'log.sink');
        self::assertNotNull($resolved);
        self::assertSame('oneOf', $resolved['via']);
        self::assertSame('App\\SyslogLogger', $resolved['providedBy']);
    }

    /**
     * A declared surface warning whose code the host has accepted stays visible but does not degrade,
     * and carries the acceptance reason so the acceptance is never anonymous.
     */
    public function testAcceptedRiskKeepsWarningVisibleWithoutDegrading(): void
    {
        $report = $this->resolve($this->httpSurfaceInput([
            new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Scopes enforced at the gateway; reviewed 2026-07.'),
        ]));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertTrue($warning['accepted']);
        self::assertSame('Scopes enforced at the gateway; reviewed 2026-07.', $warning['acceptedReason']);
        self::assertFalse($warning['acceptanceExpired']);
    }

    /**
     * The same declared surface warning, unaccepted, degrades the status to bootable_with_warnings.
     */
    public function testUnacceptedSurfaceWarningDegradesToBootable(): void
    {
        $report = $this->resolve($this->httpSurfaceInput([]));

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertFalse($warning['accepted']);
        self::assertNull($warning['acceptedReason']);
        self::assertFalse($warning['acceptanceExpired']);
    }

    /**
     * Rule — accepted risk with an expiry NOT yet reached (evaluatedAt <= expires): the acceptance
     * holds, the warning stays visible and does not degrade.
     */
    public function testAcceptedRiskWithUnreachedExpiryStillApplies(): void
    {
        $report = $this->resolve($this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
            evaluatedAt: '2026-07-11T00:00:00Z',
        ));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertTrue($warning['accepted']);
        self::assertFalse($warning['acceptanceExpired']);
        // No unevaluated-expiry meta-warning when the clock was supplied.
        self::assertNull($this->entryBy($report->warnings, 'code', 'MILPA_RISK_EXPIRY_UNEVALUATED'));
    }

    /**
     * Boundary — `evaluatedAt == expires` exactly: the comparison is a strict `>`, so at the precise
     * expiry instant the acceptance still HOLDS. A date-only expires means 00:00 UTC of that day, so
     * the equal instant is that midnight.
     */
    public function testAcceptanceStillHoldsWhenEvaluatedAtEqualsExpires(): void
    {
        $report = $this->resolve($this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
            evaluatedAt: '2026-12-31T00:00:00Z',
        ));

        self::assertSame(ResolutionStatus::Valid, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertTrue($warning['accepted']);
        self::assertFalse($warning['acceptanceExpired']);
    }

    /**
     * Boundary — date-only expiry semantics: `expires: "2026-12-31"` is 00:00 UTC of that day, so any
     * later moment of the SAME day (here noon) already voids the acceptance. Fails toward visibility.
     */
    public function testDateOnlyExpiryVoidsFromStartOfTheNamedDay(): void
    {
        $report = $this->resolve($this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
            evaluatedAt: '2026-12-31T12:00:00Z',
        ));

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertFalse($warning['accepted']);
        self::assertTrue($warning['acceptanceExpired']);
    }

    /**
     * Rule — accepted risk whose expiry has passed against the caller's clock: the acceptance is VOID,
     * the warning degrades the status again and the entry is marked acceptanceExpired.
     */
    public function testExpiredAcceptanceIsVoidAndDegradesAgain(): void
    {
        $report = $this->resolve($this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
            evaluatedAt: '2027-01-02T00:00:00Z',
        ));

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        $warning = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($warning);
        self::assertFalse($warning['accepted']);
        self::assertTrue($warning['acceptanceExpired']);
        // The reason the (now void) acceptance stated is still carried, so the report explains itself.
        self::assertSame('Temporary; gateway migration by year-end.', $warning['acceptedReason']);
    }

    /**
     * Rule — accepted risk carries an expiry but the input has NO evaluatedAt clock: the acceptance
     * applies (so nothing is silently blocked) BUT an additional, unaccepted MILPA_RISK_EXPIRY_UNEVALUATED
     * warning is emitted so the oversight is visible and the status still degrades.
     */
    public function testExpiryWithoutAClockAppliesButEmitsAVisibleWarning(): void
    {
        $report = $this->resolve($this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
        ));

        // The base acceptance applied — the surface warning is accepted...
        $accepted = $this->entryBy($report->warnings, 'code', 'HTTP_SCOPES_NOT_ENFORCED');
        self::assertNotNull($accepted);
        self::assertTrue($accepted['accepted']);
        self::assertFalse($accepted['acceptanceExpired']);

        // ...but the expiry-without-a-clock oversight is surfaced as its own unaccepted warning.
        $meta = $this->entryBy($report->warnings, 'code', 'MILPA_RISK_EXPIRY_UNEVALUATED');
        self::assertNotNull($meta);
        self::assertSame('risk-expiry', $meta['kind']);
        self::assertSame('HTTP_SCOPES_NOT_ENFORCED', $meta['id']);
        self::assertFalse($meta['accepted']);
        self::assertNull($meta['acceptedReason']);

        // The oversight degrades the status (never silent) and rides into the agent errors[].
        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);
        $error = null;
        foreach ($report->toArray()['errors'] as $candidate) {
            if ($candidate['code'] === 'MILPA_RISK_EXPIRY_UNEVALUATED') {
                $error = $candidate;
            }
        }
        self::assertNotNull($error);
        self::assertNotSame([], $error['fixes']);
    }

    /**
     * The report serializes byte-for-byte identically across repeated calls and repeated resolutions.
     */
    public function testReportIsByteIdempotent(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['command.provider', 'tool.registry']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('tool.registry', 'I', '0.1.0')],
            capabilityRequirements: [],
        );

        $first = $this->resolve($input)->toArray();
        $second = $this->resolve($input)->toArray();

        self::assertSame(json_encode($first), json_encode($second));
        // A single report re-serializes identically too.
        $report = $this->resolve($input);
        self::assertSame(json_encode($report->toArray()), json_encode($report->toArray()));
    }

    /**
     * The same input WITH the same evaluatedAt clock yields a byte-identical report — the expiry clock
     * is data, not an ambient read, so determinism holds across the acceptance/expiry paths too.
     */
    public function testReportIsByteIdempotentWithAnEvaluatedAtClock(): void
    {
        $input = $this->httpSurfaceInput(
            [new AcceptedRisk('HTTP_SCOPES_NOT_ENFORCED', 'Temporary; gateway migration by year-end.', '2026-12-31')],
            evaluatedAt: '2027-01-02T00:00:00Z',
        );

        $first = $this->resolve($input)->toArray();
        $second = $this->resolve($input)->toArray();

        self::assertSame(json_encode($first), json_encode($second));
    }

    /**
     * Reordering the inputs does not change the report — the arrays are stably sorted.
     */
    public function testResolutionIsDeterministicRegardlessOfInputOrder(): void
    {
        $one = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['alpha.missing', 'beta.missing']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('shared.store', 'S', '0.1.0', service: 'A\\One', exclusive: true),
                new CapabilityProvision('shared.store', 'S', '0.1.0', service: 'A\\Two', exclusive: true),
            ],
            capabilityRequirements: [],
        );

        $two = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['beta.missing', 'alpha.missing']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('shared.store', 'S', '0.1.0', service: 'A\\Two', exclusive: true),
                new CapabilityProvision('shared.store', 'S', '0.1.0', service: 'A\\One', exclusive: true),
            ],
            capabilityRequirements: [],
        );

        self::assertSame(
            json_encode($this->resolve($one)->toArray()),
            json_encode($this->resolve($two)->toArray()),
        );
    }

    /**
     * A host with no requirements and no manifests resolves cleanly to valid.
     */
    public function testEmptyInputResolvesToValid(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('bare', '1.0.0'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Valid, $report->status);
        self::assertSame([], $report->missing);
        self::assertSame([], $report->conflicts);
        self::assertSame([], $report->warnings);
        self::assertSame([], $report->legacy);
        self::assertSame([], $report->resolved);
    }

    /**
     * A single exclusive provider is not a conflict — exclusivity only bites when two claim the id.
     */
    public function testSingleExclusiveProviderIsNotAConflict(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['persistence.store']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\MysqlStore', exclusive: true),
            ],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Valid, $report->status);
        self::assertSame([], $report->conflicts);
    }

    /**
     * A contract manifest contributes its provided capability, its required capability, its suggestion,
     * its unmet surface requirement, and its Academy link to the resolution.
     */
    public function testContractManifestContributesProvidersRequirementsAndLinks(): void
    {
        $host = new HostProfile(
            name: 'agent-ready',
            version: '2026.07',
            requiredContracts: ['milpa.command@0.1'],
            requiredCapabilities: ['command.provider'],
            enabledSurfaces: ['cli'],
        );

        $manifest = new VersionManifest('milpa/command', '0.1.0', ['implements' => ['milpa.command@0.1']], ['provides' => []]);

        $contract = new ContractManifest(
            id: 'milpa.command',
            version: '0.1',
            requiresCapabilities: ['event.dispatcher'],
            providesCapabilities: ['command.provider'],
            suggestsCapabilities: ['audit.sink'],
            surfaceRequirements: ['telegram'],
            academyUrl: 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
            migrationUrl: 'https://academy.milpa.lat/artifacts/#atomo',
        );

        $input = new ResolutionInput(
            hostProfile: $host,
            versionManifests: [$manifest],
            contractManifests: [$contract],
            capabilityProvisions: [new CapabilityProvision('event.dispatcher', 'E', '0.1.0')],
            capabilityRequirements: [],
            activeSurfaces: ['cli'],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        // command.provider closes through the contract's provided capability.
        $provided = $this->entryBy($report->resolved, 'id', 'command.provider');
        self::assertNotNull($provided);
        self::assertSame('contract:milpa.command@0.1', $provided['providedBy']);

        // event.dispatcher — the contract's own requirement — closes against the typed provision.
        self::assertNotNull($this->entryBy($report->resolved, 'id', 'event.dispatcher'));

        // audit.sink — suggested by the contract — is a warning.
        self::assertNotNull($this->entryBy($report->warnings, 'id', 'audit.sink'));

        // telegram — a surface the contract wants but the host has not enabled — is a warning, not a block.
        $surfaceWarning = $this->entryBy($report->warnings, 'id', 'telegram');
        self::assertNotNull($surfaceWarning);
        self::assertSame('surface', $surfaceWarning['kind']);
        // The not-enabled case carries its own code, distinct from the blocking surface-requirement code.
        self::assertSame('MILPA_SURFACE_NOT_ENABLED', $surfaceWarning['code']);

        // the contract's Academy link rides along.
        $link = $this->entryBy($report->learnLinks, 'id', 'milpa.command');
        self::assertNotNull($link);
        self::assertSame('https://academy.milpa.lat/learn/fundamentos/contratos-grafo/', $link['academy']);
    }

    /**
     * A required contract that no installed package implements at all blocks with MILPA_CONTRACT_MISSING.
     */
    public function testUnimplementedRequiredContractBlocks(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredContracts: ['milpa.runtime@0.3']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);
        $missing = $this->entryBy($report->missing, 'id', 'milpa.runtime');
        self::assertNotNull($missing);
        self::assertSame('MILPA_CONTRACT_MISSING', $missing['code']);
        self::assertSame('hostProfile:agent-ready@2026.07', $missing['requiredBy']);
    }

    /**
     * Scenario 1, agent shape (spec §20/§22): the report's `errors[]` carries the complete learnable
     * error for the missing capability — code, message, why, context, human fixes, typed
     * recommendedActions, and the LIVE Academy learn links. Verified by deep equality of the whole
     * error entry against the fixture — the anti-dead-error guarantee at the report boundary.
     */
    public function testScenario1EmitsTheFullLearnableErrorInTheAgentShape(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                requiredCapabilities: ['command.provider', 'tool.registry'],
            ),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('tool.registry', 'Tool\\Registry', '0.1.0')],
            capabilityRequirements: [],
        );

        $array = $this->resolve($input)->toArray();

        self::assertSame('blocked', $array['status']);
        self::assertSame(
            [
                [
                    'code' => 'MILPA_CAPABILITY_MISSING',
                    'message' => 'The host profile agent-ready@2026.07 requires the capability "command.provider", but no active package or plugin provides it.',
                    'why' => 'A required capability closes the architecture graph only when an installed package or plugin declares that it provides it. With no provider, the runtime cannot wire the capability and the graph stays open.',
                    'context' => [
                        'id' => 'command.provider',
                        'constraint' => '*',
                        'requiredBy' => 'hostProfile:agent-ready@2026.07',
                        'hostProfile' => 'agent-ready@2026.07',
                    ],
                    'fixes' => [
                        'Install milpa/command, which provides "command.provider".',
                        'Enable a plugin that provides "command.provider".',
                        'Remove "command.provider" from the host profile if the capability is not needed.',
                    ],
                    'recommendedActions' => [
                        ['type' => 'install-package', 'package' => 'milpa/command'],
                        ['type' => 'enable-plugin', 'capability' => 'command.provider'],
                        ['type' => 'disable-feature', 'feature' => 'command.provider'],
                    ],
                    'learn' => [
                        'academy' => [
                            'es' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
                            'en' => 'https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
                        ],
                        'artifact' => [
                            'es' => 'https://academy.milpa.lat/artifacts/#siembra',
                            'en' => 'https://academy.milpa.lat/en/artifacts/#siembra',
                        ],
                        'llms' => [
                            'es' => 'https://academy.milpa.lat/llms.txt',
                            'en' => 'https://academy.milpa.lat/en/llms.txt',
                        ],
                    ],
                ],
            ],
            $array['errors'],
        );
    }

    /**
     * A conflict is attached to the agent errors[] too, with a choose-provider action naming the
     * conflicting candidates from the entry context.
     */
    public function testConflictIsAttachedToTheAgentErrors(): void
    {
        $report = $this->resolve(new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['persistence.store']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\MysqlStore', exclusive: true),
                new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\SqliteStore', exclusive: true),
            ],
            capabilityRequirements: [],
        ));

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame('MILPA_CAPABILITY_CONFLICT', $errors[0]['code']);
        self::assertContains(
            ['type' => 'choose-provider', 'capability' => 'persistence.store', 'candidates' => ['App\\MysqlStore', 'App\\SqliteStore']],
            $errors[0]['recommendedActions'],
        );
    }

    /**
     * DEUDA de T2 resolved — deprecations WIRED: a manifest declaring a deprecation emits a
     * non-blocking warning coded MILPA_DEPRECATED_CONTRACT_USED, which also surfaces as an agent error.
     */
    public function testDeclaredDeprecationEmitsAWarningAndLearnableError(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [
                new VersionManifest(
                    package: 'legacy/thing',
                    version: '1.0.0',
                    contracts: ['implements' => []],
                    capabilities: ['provides' => []],
                    deprecations: ['old.command.host'],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        $warning = $this->entryBy($report->warnings, 'id', 'old.command.host');
        self::assertNotNull($warning);
        self::assertSame('deprecation', $warning['kind']);
        self::assertSame('MILPA_DEPRECATED_CONTRACT_USED', $warning['code']);
        self::assertSame('legacy/thing@1.0.0', $warning['requiredBy']);

        $error = null;
        foreach ($report->toArray()['errors'] as $candidate) {
            if ($candidate['code'] === 'MILPA_DEPRECATED_CONTRACT_USED') {
                $error = $candidate;
            }
        }
        self::assertNotNull($error);
        self::assertContains(['type' => 'migrate-contract', 'contract' => 'old.command.host'], $error['recommendedActions']);
    }

    /**
     * DEUDA de T2 resolved — HostProfile.metadata WIRED: the host profile's free-form metadata passes
     * through, verbatim, into the report metadata.
     */
    public function testHostProfileMetadataPassesThroughToReport(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('acme-crm', '2026.07', metadata: ['env' => 'prod', 'region' => 'mx']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame('acme-crm@2026.07', $report->metadata['hostProfile']);
        self::assertSame(['env' => 'prod', 'region' => 'mx'], $report->metadata['hostMetadata']);
    }

    /**
     * Non-catalog surface warnings (e.g. HTTP_SCOPES_NOT_ENFORCED) are NOT forced into errors[]: they
     * carry no Academy lesson, so attaching a dead error would violate anti-pattern 4.
     */
    public function testNonCatalogWarningIsNotAttachedAsAnError(): void
    {
        $report = $this->resolve($this->httpSurfaceInput([]));

        // The only warning here (HTTP_SCOPES_NOT_ENFORCED) has no catalog entry, so errors[] is empty.
        self::assertSame([], $report->toArray()['errors']);
        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);
    }

    private function resolve(ResolutionInput $input): ResolutionReport
    {
        return (new GraphResolver())->resolve($input);
    }

    /**
     * @param list<AcceptedRisk> $acceptedRisks
     */
    private function httpSurfaceInput(array $acceptedRisks, ?string $evaluatedAt = null): ResolutionInput
    {
        return new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'agent-ready',
                version: '2026.07',
                enabledSurfaces: ['http'],
                acceptedRisks: $acceptedRisks,
            ),
            versionManifests: [
                new VersionManifest('milpa/http', '0.1.0', ['implements' => []], ['provides' => []], surfaces: ['supports' => ['http']]),
            ],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('http.router', 'R', '0.1.0')],
            capabilityRequirements: [],
            activeSurfaces: ['http'],
            environment: [
                'surfaces' => [
                    [
                        'surface' => 'http',
                        'requires' => ['http.router'],
                        'warnings' => [
                            ['code' => 'HTTP_SCOPES_NOT_ENFORCED', 'message' => 'HTTP routes do not enforce Operation scopes in this profile.'],
                        ],
                    ],
                ],
            ],
            evaluatedAt: $evaluatedAt,
        );
    }

    /**
     * Find the first entry in a report list whose $key equals $value.
     *
     * @param array<string, mixed> $list
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
}
