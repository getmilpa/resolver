<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Report;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

final class ResolutionReportTest extends TestCase
{
    public function testDefaultsAreEmptyCollections(): void
    {
        $report = new ResolutionReport(ResolutionStatus::Valid);

        self::assertSame(ResolutionStatus::Valid, $report->status);
        self::assertSame([], $report->resolved);
        self::assertSame([], $report->missing);
        self::assertSame([], $report->conflicts);
        self::assertSame([], $report->warnings);
        self::assertSame([], $report->legacy);
        self::assertSame([], $report->migrationHints);
        self::assertSame([], $report->learnLinks);
        self::assertSame([], $report->metadata);
        self::assertSame([], $report->errors);
    }

    public function testToArraySerializesStatusAsStringInDeterministicOrder(): void
    {
        $report = new ResolutionReport(
            status: ResolutionStatus::Blocked,
            missing: [['id' => 'command.provider', 'requiredBy' => 'milpa.runtime@0.3']],
            warnings: [['id' => 'audit.sink']],
        );

        $array = $report->toArray();

        self::assertSame('blocked', $array['status']);
        self::assertSame(
            ['status', 'errors', 'resolved', 'missing', 'conflicts', 'warnings', 'legacy', 'migrationHints', 'learnLinks', 'metadata'],
            array_keys($array),
        );
        self::assertSame([['id' => 'command.provider', 'requiredBy' => 'milpa.runtime@0.3']], $array['missing']);
    }

    public function testAttachedErrorsSerializeInTheAgentShapeRightAfterStatus(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_MISSING',
            message: 'msg',
            why: 'why',
            context: ['id' => 'command.provider'],
            fixes: ['Install milpa/command.'],
            links: ['academy' => ['es' => 'https://academy.milpa.lat/', 'en' => 'https://academy.milpa.lat/en/']],
        );

        $report = new ResolutionReport(status: ResolutionStatus::Blocked, errors: [$error]);
        $array = $report->toArray();

        self::assertSame($error->toArray(), $array['errors'][0]);
    }

    public function testFromArrayRehydratesAttachedErrors(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_MISSING',
            message: 'msg',
            why: 'why',
            context: ['id' => 'command.provider'],
            fixes: ['Install milpa/command.'],
            links: ['academy' => ['es' => 'https://academy.milpa.lat/', 'en' => 'https://academy.milpa.lat/en/']],
        );

        $report = new ResolutionReport(status: ResolutionStatus::Blocked, errors: [$error]);
        $round = ResolutionReport::fromArray($report->toArray());

        self::assertSame(json_encode($report->toArray()), json_encode($round->toArray()));
        self::assertCount(1, $round->errors);
        self::assertInstanceOf(LearnableArchitectureError::class, $round->errors[0]);
    }

    public function testFromArrayRoundTrips(): void
    {
        $report = new ResolutionReport(
            status: ResolutionStatus::LegacyCompatible,
            legacy: [['contract' => 'milpa.command@0.0', 'via' => 'legacy-adapter']],
            migrationHints: [['from' => 'legacy', 'to' => 'canonical']],
        );

        $array = $report->toArray();
        $again = ResolutionReport::fromArray($array)->toArray();

        self::assertSame(json_encode($array), json_encode($again));
        self::assertSame(ResolutionStatus::LegacyCompatible, ResolutionReport::fromArray($array)->status);
    }

    public function testFromArrayRejectsUnknownStatus(): void
    {
        $this->expectException(InvalidManifestException::class);
        ResolutionReport::fromArray(['status' => 'exploded']);
    }

    public function testFromArrayRejectsMissingStatus(): void
    {
        $this->expectException(InvalidManifestException::class);
        ResolutionReport::fromArray([]);
    }

    public function testToArrayIsIdempotent(): void
    {
        $report = new ResolutionReport(ResolutionStatus::BootableWithWarnings, warnings: [['id' => 'audit.sink']]);

        self::assertSame(json_encode($report->toArray()), json_encode($report->toArray()));
    }
}
