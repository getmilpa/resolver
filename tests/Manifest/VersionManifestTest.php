<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\VersionManifest;
use PHPUnit\Framework\TestCase;

final class VersionManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'package' => 'milpa/command',
            'version' => '0.1.0',
            'contracts' => ['implements' => ['milpa.command@0.1'], 'requires' => ['milpa.events@0.1']],
            'capabilities' => ['provides' => ['command.provider'], 'requires' => ['event.dispatcher'], 'suggests' => ['audit.sink']],
            'surfaces' => ['supports' => ['cli', 'mcp', 'http']],
            'deprecations' => ['old.thing'],
            'metadata' => ['shape' => 'canonical'],
        ];
    }

    public function testFromArrayParsesEveryField(): void
    {
        $m = VersionManifest::fromArray(self::valid());

        self::assertSame('milpa/command', $m->package);
        self::assertSame('0.1.0', $m->version);
        self::assertSame(['implements' => ['milpa.command@0.1'], 'requires' => ['milpa.events@0.1']], $m->contracts);
        self::assertSame(['provides' => ['command.provider'], 'requires' => ['event.dispatcher'], 'suggests' => ['audit.sink']], $m->capabilities);
        self::assertSame(['supports' => ['cli', 'mcp', 'http']], $m->surfaces);
        self::assertSame(['old.thing'], $m->deprecations);
        self::assertSame(['shape' => 'canonical'], $m->metadata);
    }

    public function testOptionalArraysDefaultToEmpty(): void
    {
        $m = VersionManifest::fromArray([
            'package' => 'milpa/core',
            'version' => '1.0.0',
            'contracts' => [],
            'capabilities' => [],
        ]);

        self::assertSame([], $m->surfaces);
        self::assertSame([], $m->deprecations);
        self::assertSame([], $m->metadata);
    }

    public function testMissingPackageThrows(): void
    {
        $data = self::valid();
        unset($data['package']);

        $this->expectException(InvalidManifestException::class);
        VersionManifest::fromArray($data);
    }

    public function testMissingVersionThrows(): void
    {
        $data = self::valid();
        unset($data['version']);

        $this->expectException(InvalidManifestException::class);
        VersionManifest::fromArray($data);
    }

    public function testMissingContractsThrows(): void
    {
        $data = self::valid();
        unset($data['contracts']);

        $this->expectException(InvalidManifestException::class);
        VersionManifest::fromArray($data);
    }

    public function testMissingCapabilitiesThrows(): void
    {
        $data = self::valid();
        unset($data['capabilities']);

        $this->expectException(InvalidManifestException::class);
        VersionManifest::fromArray($data);
    }

    public function testInvalidSemverVersionThrows(): void
    {
        $data = self::valid();
        $data['version'] = 'not-a-version';

        $this->expectException(InvalidManifestException::class);
        VersionManifest::fromArray($data);
    }

    public function testToArrayKeyOrderIsDeterministicRegardlessOfInputOrder(): void
    {
        $ordered = self::valid();
        $shuffled = array_reverse($ordered, true);

        $a = VersionManifest::fromArray($ordered)->toArray();
        $b = VersionManifest::fromArray($shuffled)->toArray();

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame(
            ['package', 'version', 'contracts', 'capabilities', 'surfaces', 'deprecations', 'metadata'],
            array_keys($a),
        );
    }

    public function testToArrayIsIdempotent(): void
    {
        $m = VersionManifest::fromArray(self::valid());

        self::assertSame(json_encode($m->toArray()), json_encode($m->toArray()));
    }
}
