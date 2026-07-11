<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\AcceptedRisk;
use Milpa\Resolver\Manifest\HostProfile;
use PHPUnit\Framework\TestCase;

final class HostProfileTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'name' => 'agent-ready',
            'version' => '2026.07',
            'requiredContracts' => ['milpa.runtime@0.3', 'milpa.command@0.1'],
            'enabledSurfaces' => ['cli', 'mcp', 'http'],
            'requiredCapabilities' => ['event.dispatcher', 'command.provider'],
            'allowedLegacyContracts' => ['*'],
            'acceptedRisks' => [
                ['code' => 'audit.sink-missing', 'reason' => 'Audit sink lands next sprint; low blast radius.'],
            ],
            'metadata' => ['env' => 'prod'],
        ];
    }

    public function testFromArrayParsesEveryField(): void
    {
        $p = HostProfile::fromArray(self::valid());

        self::assertSame('agent-ready', $p->name);
        self::assertSame('2026.07', $p->version);
        self::assertSame(['milpa.runtime@0.3', 'milpa.command@0.1'], $p->requiredContracts);
        self::assertSame(['cli', 'mcp', 'http'], $p->enabledSurfaces);
        self::assertSame(['event.dispatcher', 'command.provider'], $p->requiredCapabilities);
        self::assertSame(['*'], $p->allowedLegacyContracts);

        self::assertCount(1, $p->acceptedRisks);
        self::assertInstanceOf(AcceptedRisk::class, $p->acceptedRisks[0]);
        self::assertSame('audit.sink-missing', $p->acceptedRisks[0]->code);
        self::assertSame('Audit sink lands next sprint; low blast radius.', $p->acceptedRisks[0]->reason);
        self::assertNull($p->acceptedRisks[0]->expires);

        self::assertSame(['env' => 'prod'], $p->metadata);
    }

    public function testAcceptedRiskRequiresAReason(): void
    {
        $data = self::valid();
        $data['acceptedRisks'] = [['code' => 'audit.sink-missing']];

        try {
            HostProfile::fromArray($data);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsStringIgnoringCase('reason', $e->getMessage());
            self::assertStringContainsStringIgnoringCase('silenc', $e->getMessage());
        }
    }

    public function testOldBareStringShapeIsRejectedWithATeachingMessage(): void
    {
        $data = self::valid();
        $data['acceptedRisks'] = ['audit.sink-missing'];

        try {
            HostProfile::fromArray($data);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            // The message shows the new object shape: code + reason.
            self::assertStringContainsString('code', $e->getMessage());
            self::assertStringContainsString('reason', $e->getMessage());
            self::assertStringContainsString('audit.sink-missing', $e->getMessage());
        }
    }

    public function testAcceptedRiskExpiryIsValidatedAsIsoDate(): void
    {
        $data = self::valid();
        $data['acceptedRisks'] = [['code' => 'c', 'reason' => 'r', 'expires' => 'not-a-date']];

        $this->expectException(InvalidManifestException::class);
        HostProfile::fromArray($data);
    }

    public function testAcceptedRisksDefaultsToEmpty(): void
    {
        $p = HostProfile::fromArray(['name' => 'minimal', 'version' => '2026.07']);

        self::assertSame([], $p->acceptedRisks);
        self::assertSame([], $p->allowedLegacyContracts);
    }

    public function testCalendarVersionIsAccepted(): void
    {
        $p = HostProfile::fromArray(['name' => 'crm', 'version' => '2026.07']);

        self::assertSame('2026.07', $p->version);
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        HostProfile::fromArray(['version' => '2026.07']);
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        HostProfile::fromArray(['name' => 'crm']);
    }

    public function testInvalidVersionThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        HostProfile::fromArray(['name' => 'crm', 'version' => 'garbage']);
    }

    public function testToArrayIncludesAcceptedRisksAndIsDeterministic(): void
    {
        $a = HostProfile::fromArray(self::valid())->toArray();
        $b = HostProfile::fromArray(array_reverse(self::valid(), true))->toArray();

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame(
            ['name', 'version', 'requiredContracts', 'enabledSurfaces', 'requiredCapabilities', 'allowedLegacyContracts', 'acceptedRisks', 'metadata'],
            array_keys($a),
        );
        self::assertSame(
            [['code' => 'audit.sink-missing', 'reason' => 'Audit sink lands next sprint; low blast radius.', 'expires' => null]],
            $a['acceptedRisks'],
        );
    }
}
