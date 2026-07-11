<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Report;

use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

final class ResolutionStatusTest extends TestCase
{
    public function testCasesCarryTheSpecValues(): void
    {
        self::assertSame('valid', ResolutionStatus::Valid->value);
        self::assertSame('bootable_with_warnings', ResolutionStatus::BootableWithWarnings->value);
        self::assertSame('blocked', ResolutionStatus::Blocked->value);
        self::assertSame('legacy_compatible', ResolutionStatus::LegacyCompatible->value);
    }

    public function testExactlyFourStates(): void
    {
        self::assertCount(4, ResolutionStatus::cases());
    }

    public function testFromStringRoundTrips(): void
    {
        self::assertSame(ResolutionStatus::LegacyCompatible, ResolutionStatus::from('legacy_compatible'));
    }
}
