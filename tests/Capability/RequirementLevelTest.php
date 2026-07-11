<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Capability;

use Milpa\Resolver\Capability\RequirementLevel;
use PHPUnit\Framework\TestCase;

final class RequirementLevelTest extends TestCase
{
    public function testCasesCarryTheSpecValues(): void
    {
        self::assertSame('required', RequirementLevel::Required->value);
        self::assertSame('suggested', RequirementLevel::Suggested->value);
        self::assertSame('optional', RequirementLevel::Optional->value);
    }

    public function testExactlyThreeLevels(): void
    {
        self::assertCount(3, RequirementLevel::cases());
    }

    public function testFromStringRoundTrips(): void
    {
        self::assertSame(RequirementLevel::Required, RequirementLevel::from('required'));
        self::assertSame(RequirementLevel::Suggested, RequirementLevel::from('suggested'));
        self::assertSame(RequirementLevel::Optional, RequirementLevel::from('optional'));
    }
}
