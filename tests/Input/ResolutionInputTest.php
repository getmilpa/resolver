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
    }

    public function testMissingHostProfileThrows(): void
    {
        $data = self::valid();
        unset($data['hostProfile']);

        $this->expectException(InvalidManifestException::class);
        ResolutionInput::fromArray($data);
    }

    public function testToArrayRoundTripsAndIsDeterministic(): void
    {
        $input = ResolutionInput::fromArray(self::valid());
        $array = $input->toArray();

        self::assertSame(
            ['hostProfile', 'versionManifests', 'contractManifests', 'capabilityProvisions', 'capabilityRequirements', 'activeSurfaces', 'environment'],
            array_keys($array),
        );

        // Round-trip: re-hydrating the serialized form yields a byte-identical serialization.
        $again = ResolutionInput::fromArray($array)->toArray();
        self::assertSame(json_encode($array), json_encode($again));
    }
}
