<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Contracts;

use Milpa\Resolver\Contracts\ArchitectureResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

final class ArchitectureResolverTest extends TestCase
{
    public function testInterfaceDefinesResolveInputToReport(): void
    {
        self::assertTrue(interface_exists(ArchitectureResolver::class));

        $method = new \ReflectionMethod(ArchitectureResolver::class, 'resolve');
        $params = $method->getParameters();

        self::assertCount(1, $params);
        self::assertSame(ResolutionInput::class, (string) $params[0]->getType());
        self::assertSame(ResolutionReport::class, (string) $method->getReturnType());
    }

    public function testAnImplementationSatisfiesTheContract(): void
    {
        $resolver = new class () implements ArchitectureResolver {
            public function resolve(ResolutionInput $input): ResolutionReport
            {
                return new ResolutionReport(ResolutionStatus::Valid);
            }
        };

        $input = new ResolutionInput(
            hostProfile: new HostProfile(name: 'agent-ready', version: '2026.07'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        self::assertSame(ResolutionStatus::Valid, $resolver->resolve($input)->status);
    }
}
