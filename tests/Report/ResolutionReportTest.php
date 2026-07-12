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
        self::assertSame([], $report->loadOrder);
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
            ['status', 'errors', 'resolved', 'loadOrder', 'missing', 'conflicts', 'warnings', 'legacy', 'migrationHints', 'learnLinks', 'metadata'],
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

    /**
     * firstLearnableLine() composes THE canonical one-line teaching message from errors[0] — the exact
     * line the runtime kernel and the host's Plugins loader each composed by hand until Orden T2:
     * `{code}: {message} — {why} Fix: {fixes[0]} Learn: {learn.academy.en}`. Pinned byte for byte so
     * T3 (runtime) and T4 (host) can swap to it with zero message drift.
     */
    public function testFirstLearnableLineComposesTheCanonicalTeachingLine(): void
    {
        $report = new ResolutionReport(status: ResolutionStatus::Blocked, errors: [
            new LearnableArchitectureError(
                code: 'MILPA_CAPABILITY_MISSING',
                message: 'The host profile agent-ready@2026.07 requires the capability "command.provider", but no active package or plugin provides it.',
                why: 'With no provider, the runtime cannot wire the capability and the graph stays open.',
                context: ['id' => 'command.provider'],
                fixes: [
                    'Install milpa/command, which provides "command.provider".',
                    'Enable a plugin that provides "command.provider".',
                ],
                links: ['academy' => [
                    'es' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
                    'en' => 'https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
                ]],
            ),
        ]);

        self::assertSame(
            'MILPA_CAPABILITY_MISSING: The host profile agent-ready@2026.07 requires the capability '
            . '"command.provider", but no active package or plugin provides it. — With no provider, the '
            . 'runtime cannot wire the capability and the graph stays open. '
            . 'Fix: Install milpa/command, which provides "command.provider". '
            . 'Learn: https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
            $report->firstLearnableLine(),
        );
    }

    public function testFirstLearnableLineIsNullWhenThereAreNoErrors(): void
    {
        self::assertNull((new ResolutionReport(ResolutionStatus::Valid))->firstLearnableLine());
    }

    /**
     * The line reads errors[0] only — never a later error — and the same report always yields the
     * same line (determinism at the message boundary).
     */
    public function testFirstLearnableLineUsesTheFirstErrorAndIsDeterministic(): void
    {
        $first = new LearnableArchitectureError('MILPA_CONTRACT_MISSING', 'first message', 'first why', [], ['first fix'], []);
        $second = new LearnableArchitectureError('MILPA_CAPABILITY_MISSING', 'second message', 'second why', [], ['second fix'], []);
        $report = new ResolutionReport(status: ResolutionStatus::Blocked, errors: [$first, $second]);

        $line = $report->firstLearnableLine();

        self::assertIsString($line);
        self::assertStringStartsWith('MILPA_CONTRACT_MISSING: first message', $line);
        self::assertStringNotContainsString('second', $line);
        self::assertSame($line, $report->firstLearnableLine());
    }

    /**
     * Defensive like the consumers it replaces: an error with no fixes and no academy link still
     * composes (empty Fix/Learn segments), instead of throwing on a malformed error.
     */
    public function testFirstLearnableLineToleratesMissingFixesAndLinks(): void
    {
        $report = new ResolutionReport(status: ResolutionStatus::Blocked, errors: [
            new LearnableArchitectureError('MILPA_ARCHITECTURE_GRAPH_BLOCKED', 'the graph is blocked.', 'it does not close.'),
        ]);

        self::assertSame(
            'MILPA_ARCHITECTURE_GRAPH_BLOCKED: the graph is blocked. — it does not close. Fix:  Learn: ',
            $report->firstLearnableLine(),
        );
    }
}
