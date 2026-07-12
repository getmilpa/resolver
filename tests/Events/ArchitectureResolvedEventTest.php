<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Events;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Events\ArchitectureResolvedEvent;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

/**
 * The boot-side carrier of the resolution report: a readonly, pure-notification event that hands a
 * finalized {@see ResolutionReport} to 'architecture.resolved' listeners without them re-resolving.
 * It exists because milpa/core's frozen CapabilityResolvedEvent could not grow the report payload
 * without breaking BC — the report rides its own event instead.
 */
final class ArchitectureResolvedEventTest extends TestCase
{
    public function testCarriesTheResolutionReportObjectVerbatim(): void
    {
        $report = new ResolutionReport(status: ResolutionStatus::Valid);
        $event = new ArchitectureResolvedEvent($report);

        self::assertSame($report, $event->report);
        self::assertSame(ResolutionStatus::Valid, $event->report->status);
    }

    public function testToArrayDelegatesToTheReportAgentShapeByteForByte(): void
    {
        $report = new ResolutionReport(
            status: ResolutionStatus::Blocked,
            missing: [['kind' => 'capability', 'id' => 'command.provider']],
            errors: [new LearnableArchitectureError(
                code: 'MILPA_CAPABILITY_MISSING',
                message: 'msg',
                why: 'why',
                links: ['academy' => ['es' => 'https://academy.milpa.lat/', 'en' => 'https://academy.milpa.lat/en/']],
            )],
        );
        $event = new ArchitectureResolvedEvent($report);

        self::assertSame($report->toArray(), $event->toArray());
        self::assertSame('blocked', $event->toArray()['status']);
    }

    public function testCarriesARealEngineReportUnchanged(): void
    {
        $report = (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: new HostProfile('host', '0.0.0', allowedLegacyContracts: ['*']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        $event = new ArchitectureResolvedEvent($report);

        self::assertSame(ResolutionStatus::Valid, $event->report->status);
        self::assertSame($report->toArray(), $event->toArray());
    }

    public function testTheEventIsReadonly(): void
    {
        self::assertTrue((new \ReflectionClass(ArchitectureResolvedEvent::class))->isReadOnly());
    }
}
