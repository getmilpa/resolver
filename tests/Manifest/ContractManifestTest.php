<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\ContractManifest;
use PHPUnit\Framework\TestCase;

final class ContractManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'id' => 'milpa.command',
            'version' => '0.1',
            'requiresCapabilities' => ['event.dispatcher'],
            'providesCapabilities' => ['command.provider', 'operation.projector'],
            'suggestsCapabilities' => ['audit.sink'],
            'surfaceRequirements' => ['cli'],
            'academyUrl' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
            'migrationUrl' => 'https://docs.milpa.lat/migrate/command',
        ];
    }

    public function testFromArrayParsesEveryField(): void
    {
        $c = ContractManifest::fromArray(self::valid());

        self::assertSame('milpa.command', $c->id);
        self::assertSame('0.1', $c->version);
        self::assertSame(['event.dispatcher'], $c->requiresCapabilities);
        self::assertSame(['command.provider', 'operation.projector'], $c->providesCapabilities);
        self::assertSame(['audit.sink'], $c->suggestsCapabilities);
        self::assertSame(['cli'], $c->surfaceRequirements);
        self::assertSame('https://academy.milpa.lat/learn/fundamentos/contratos-grafo/', $c->academyUrl);
        self::assertSame('https://docs.milpa.lat/migrate/command', $c->migrationUrl);
    }

    public function testTwoPartContractVersionIsAccepted(): void
    {
        $c = ContractManifest::fromArray(['id' => 'milpa.events', 'version' => '0.1']);

        self::assertSame('0.1', $c->version);
    }

    public function testOptionalsDefault(): void
    {
        $c = ContractManifest::fromArray(['id' => 'milpa.events', 'version' => '0.3']);

        self::assertSame([], $c->requiresCapabilities);
        self::assertSame([], $c->providesCapabilities);
        self::assertSame([], $c->suggestsCapabilities);
        self::assertSame([], $c->surfaceRequirements);
        self::assertNull($c->academyUrl);
        self::assertNull($c->migrationUrl);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        ContractManifest::fromArray(['version' => '0.1']);
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        ContractManifest::fromArray(['id' => 'milpa.command']);
    }

    public function testInvalidVersionThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        ContractManifest::fromArray(['id' => 'milpa.command', 'version' => 'abc']);
    }

    public function testToArrayKeyOrderIsDeterministic(): void
    {
        $a = ContractManifest::fromArray(self::valid())->toArray();
        $b = ContractManifest::fromArray(array_reverse(self::valid(), true))->toArray();

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame(
            ['id', 'version', 'requiresCapabilities', 'providesCapabilities', 'suggestsCapabilities', 'surfaceRequirements', 'academyUrl', 'migrationUrl'],
            array_keys($a),
        );
    }
}
