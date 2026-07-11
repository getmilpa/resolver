<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Input;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use PHPUnit\Framework\TestCase;

final class ResolutionInputTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'hostProfile' => [
                'name' => 'agent-ready',
                'version' => '2026.07',
                'requiredCapabilities' => ['command.provider'],
            ],
            'versionManifests' => [
                ['package' => 'milpa/command', 'version' => '0.1.0', 'contracts' => [], 'capabilities' => []],
            ],
            'contractManifests' => [
                ['id' => 'milpa.command', 'version' => '0.1'],
            ],
            'capabilityProvisions' => [
                ['id' => 'command.provider', 'interface' => 'Milpa\\Command\\CommandProvider', 'contractVersion' => '0.1.0', 'priority' => 10, 'exclusive' => true],
            ],
            'capabilityRequirements' => [
                ['id' => 'event.dispatcher', 'interface' => 'Psr\\EventDispatcher\\EventDispatcherInterface', 'constraint' => '^1.0', 'oneOf' => ['symfony.dispatcher']],
            ],
            'activeSurfaces' => ['cli', 'mcp'],
            'environment' => ['profile' => 'prod'],
            'evaluatedAt' => '2026-07-11T12:00:00Z',
        ];
    }

    public function testFromArrayBuildsChildValueObjects(): void
    {
        $input = ResolutionInput::fromArray(self::valid());

        self::assertInstanceOf(HostProfile::class, $input->hostProfile);
        self::assertSame('agent-ready', $input->hostProfile->name);

        self::assertCount(1, $input->versionManifests);
        self::assertSame('milpa/command', $input->versionManifests[0]->package);

        self::assertCount(1, $input->contractManifests);
        self::assertSame('milpa.command', $input->contractManifests[0]->id);

        self::assertCount(1, $input->capabilityProvisions);
        self::assertInstanceOf(CapabilityProvision::class, $input->capabilityProvisions[0]);
        self::assertSame(10, $input->capabilityProvisions[0]->priority);
        self::assertTrue($input->capabilityProvisions[0]->exclusive);

        self::assertCount(1, $input->capabilityRequirements);
        self::assertInstanceOf(CapabilityRequirement::class, $input->capabilityRequirements[0]);
        self::assertSame(['symfony.dispatcher'], $input->capabilityRequirements[0]->oneOf);

        self::assertSame(['cli', 'mcp'], $input->activeSurfaces);
        self::assertSame(['profile' => 'prod'], $input->environment);
        self::assertSame('2026-07-11T12:00:00Z', $input->evaluatedAt);
    }

    public function testMissingHostProfileThrows(): void
    {
        $data = self::valid();
        unset($data['hostProfile']);

        $this->expectException(InvalidManifestException::class);
        ResolutionInput::fromArray($data);
    }

    public function testEvaluatedAtIsOptionalAndDefaultsToNullNeverNow(): void
    {
        $data = self::valid();
        unset($data['evaluatedAt']);

        $input = ResolutionInput::fromArray($data);

        // Purity: the caller owns the clock — an absent evaluatedAt stays null, never a wall-clock read.
        self::assertNull($input->evaluatedAt);
    }

    public function testInvalidEvaluatedAtThrows(): void
    {
        $data = self::valid();
        $data['evaluatedAt'] = 'yesterday';

        $this->expectException(InvalidManifestException::class);
        ResolutionInput::fromArray($data);
    }

    public function testFromArrayRejectsNowAsEvaluatedAt(): void
    {
        // "now" would make expiry parsing read the wall clock — rejected on the fromArray path.
        $data = self::valid();
        $data['evaluatedAt'] = 'now';

        $this->expectException(InvalidManifestException::class);
        ResolutionInput::fromArray($data);
    }

    public function testDirectConstructionRejectsNowAsEvaluatedAt(): void
    {
        // The purity hole the constructor must close: bypassing fromArray with a relative expression
        // would otherwise reach the engine's clock comparison and read the wall clock.
        $this->expectException(InvalidManifestException::class);
        new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
            evaluatedAt: 'now',
        );
    }

    public function testToArrayRoundTripsAndIsDeterministic(): void
    {
        $input = ResolutionInput::fromArray(self::valid());
        $array = $input->toArray();

        self::assertSame(
            ['hostProfile', 'versionManifests', 'contractManifests', 'capabilityProvisions', 'capabilityRequirements', 'activeSurfaces', 'environment', 'evaluatedAt'],
            array_keys($array),
        );
        self::assertSame('2026-07-11T12:00:00Z', $array['evaluatedAt']);

        // Round-trip: re-hydrating the serialized form yields a byte-identical serialization.
        $again = ResolutionInput::fromArray($array)->toArray();
        self::assertSame(json_encode($array), json_encode($again));
    }
}
