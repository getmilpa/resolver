<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Report;

use Milpa\Resolver\Report\LearnableArchitectureError;
use PHPUnit\Framework\TestCase;

/**
 * The learnable error value object (spec §12): a readonly carrier of code/message/why/context/fixes/
 * links whose toArray() emits the agent shape of spec §20 — deriving typed recommendedActions from
 * its own code and context, and exposing its links as the bilingual `learn` map.
 */
final class LearnableArchitectureErrorTest extends TestCase
{
    public function testConstructorExposesSpecTwelveFields(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_MISSING',
            message: 'command.provider is missing.',
            why: 'The runtime cannot wire it.',
            context: ['id' => 'command.provider'],
            fixes: ['Install milpa/command.'],
            links: ['academy' => ['es' => 'https://academy.milpa.lat/', 'en' => 'https://academy.milpa.lat/en/']],
        );

        self::assertSame('MILPA_CAPABILITY_MISSING', $error->code);
        self::assertSame('command.provider is missing.', $error->message);
        self::assertSame('The runtime cannot wire it.', $error->why);
        self::assertSame(['id' => 'command.provider'], $error->context);
        self::assertSame(['Install milpa/command.'], $error->fixes);
        self::assertArrayHasKey('academy', $error->links);
    }

    public function testToArrayEmitsAgentShapeWithRecommendedActionsAndLearn(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_MISSING',
            message: 'msg',
            why: 'why',
            context: ['id' => 'command.provider', 'requiredBy' => 'hostProfile:x@1'],
            fixes: ['fix one'],
            links: ['llms' => ['es' => 'https://academy.milpa.lat/llms.txt', 'en' => 'https://academy.milpa.lat/en/llms.txt']],
        );

        $array = $error->toArray();

        self::assertSame(
            ['code', 'message', 'why', 'context', 'fixes', 'recommendedActions', 'learn'],
            array_keys($array),
        );
        // learn is the constructed links map, verbatim.
        self::assertSame($error->links, $array['learn']);
        // command.provider is a known package, so an install-package action is derived.
        self::assertContains(['type' => 'install-package', 'package' => 'milpa/command'], $array['recommendedActions']);
        self::assertContains(['type' => 'enable-plugin', 'capability' => 'command.provider'], $array['recommendedActions']);
        self::assertContains(['type' => 'disable-feature', 'feature' => 'command.provider'], $array['recommendedActions']);
    }

    public function testConflictDerivesChooseProviderActionFromContext(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_CONFLICT',
            message: 'msg',
            why: 'why',
            context: ['id' => 'persistence.store', 'providedBy' => ['App\\MysqlStore', 'App\\SqliteStore']],
            fixes: ['Choose one provider.'],
            links: [],
        );

        self::assertContains(
            ['type' => 'choose-provider', 'capability' => 'persistence.store', 'candidates' => ['App\\MysqlStore', 'App\\SqliteStore']],
            $error->toArray()['recommendedActions'],
        );
    }

    public function testUnknownCapabilityOmitsInstallPackageAction(): void
    {
        $error = new LearnableArchitectureError(
            code: 'MILPA_CAPABILITY_MISSING',
            message: 'msg',
            why: 'why',
            context: ['id' => 'some.unknown.capability'],
            fixes: ['fix'],
            links: [],
        );

        $types = array_column($error->toArray()['recommendedActions'], 'type');
        self::assertNotContains('install-package', $types);
        self::assertContains('enable-plugin', $types);
    }

    public function testToArrayIsIdempotent(): void
    {
        $error = new LearnableArchitectureError('MILPA_SURFACE_NOT_ENABLED', 'm', 'w', ['surface' => 'http'], ['f'], []);

        self::assertSame(json_encode($error->toArray()), json_encode($error->toArray()));
    }
}
