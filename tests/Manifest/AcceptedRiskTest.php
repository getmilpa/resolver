<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\AcceptedRisk;
use PHPUnit\Framework\TestCase;

/**
 * The invariant Rod named: accepting a risk must never hide it. An {@see AcceptedRisk} therefore
 * demands a `reason` (accepting without one is silencing), rejects the old bare-string shape with a
 * message that teaches the new one, and validates `expires` as a parseable ISO-8601 date so the
 * caller's clock can later decide whether the acceptance still holds.
 */
final class AcceptedRiskTest extends TestCase
{
    public function testFromArrayParsesCodeReasonAndExpiry(): void
    {
        $risk = AcceptedRisk::fromArray([
            'code' => 'HTTP_SCOPES_NOT_ENFORCED',
            'reason' => 'Scopes are enforced at the gateway; reviewed 2026-07.',
            'expires' => '2026-12-31',
        ]);

        self::assertSame('HTTP_SCOPES_NOT_ENFORCED', $risk->code);
        self::assertSame('Scopes are enforced at the gateway; reviewed 2026-07.', $risk->reason);
        self::assertSame('2026-12-31', $risk->expires);
    }

    public function testExpiresIsOptionalAndDefaultsToNull(): void
    {
        $risk = AcceptedRisk::fromArray([
            'code' => 'audit.sink-missing',
            'reason' => 'Audit sink lands next sprint; low blast radius until then.',
        ]);

        self::assertNull($risk->expires);
    }

    public function testMissingReasonThrowsAMessageThatTeachesSilencing(): void
    {
        try {
            AcceptedRisk::fromArray(['code' => 'audit.sink-missing']);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsStringIgnoringCase('reason', $e->getMessage());
            self::assertStringContainsStringIgnoringCase('silenc', $e->getMessage());
        }
    }

    public function testEmptyReasonIsRejected(): void
    {
        $this->expectException(InvalidManifestException::class);
        AcceptedRisk::fromArray(['code' => 'audit.sink-missing', 'reason' => '   ']);
    }

    public function testMissingCodeThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        AcceptedRisk::fromArray(['reason' => 'no code given']);
    }

    public function testUnparseableExpiryThrowsAnIsoDateMessage(): void
    {
        try {
            AcceptedRisk::fromArray([
                'code' => 'audit.sink-missing',
                'reason' => 'valid reason',
                'expires' => 'someday',
            ]);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString('ISO-8601', $e->getMessage());
        }
    }

    public function testRelativeExpiryIsRejectedForPurity(): void
    {
        // "tomorrow" would make DateTimeImmutable read the wall clock — the resolver must stay pure.
        $this->expectException(InvalidManifestException::class);
        AcceptedRisk::fromArray([
            'code' => 'audit.sink-missing',
            'reason' => 'valid reason',
            'expires' => 'tomorrow',
        ]);
    }

    public function testDirectConstructionRejectsARelativeExpiryToo(): void
    {
        // The constructor closes the same purity hole as fromArray: a relative "now" expiry built
        // directly would otherwise reach the engine's clock comparison and read the wall clock.
        $this->expectException(InvalidManifestException::class);
        new AcceptedRisk('audit.sink-missing', 'valid reason', 'now');
    }

    public function testToArraySerializesWithFixedKeyOrder(): void
    {
        $array = AcceptedRisk::fromArray([
            'code' => 'HTTP_SCOPES_NOT_ENFORCED',
            'reason' => 'Enforced at gateway.',
            'expires' => '2026-12-31',
        ])->toArray();

        self::assertSame(['code', 'reason', 'expires'], array_keys($array));
        self::assertSame('2026-12-31', $array['expires']);
    }

    public function testToArrayOmitsNothingWhenExpiryIsAbsent(): void
    {
        $array = AcceptedRisk::fromArray(['code' => 'c', 'reason' => 'r'])->toArray();

        self::assertSame(['code', 'reason', 'expires'], array_keys($array));
        self::assertNull($array['expires']);
    }
}
