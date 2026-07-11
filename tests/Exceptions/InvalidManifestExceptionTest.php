<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Exceptions;

use Milpa\Exceptions\MilpaExceptionInterface;
use Milpa\Resolver\Exceptions\InvalidManifestException;
use PHPUnit\Framework\TestCase;

final class InvalidManifestExceptionTest extends TestCase
{
    public function testIsAMilpaException(): void
    {
        $e = InvalidManifestException::missingField('VersionManifest', 'version', 'milpa/command');

        self::assertInstanceOf(MilpaExceptionInterface::class, $e);
        self::assertInstanceOf(\Throwable::class, $e);
    }

    public function testMissingFieldMessageIsTechnicalEnglish(): void
    {
        $e = InvalidManifestException::missingField('VersionManifest', 'version', 'milpa/command');

        self::assertSame(
            'VersionManifest "milpa/command" is missing required field "version".',
            $e->getMessage(),
        );
    }

    public function testMissingFieldWithoutSubject(): void
    {
        $e = InvalidManifestException::missingField('HostProfile', 'name');

        self::assertSame('HostProfile is missing required field "name".', $e->getMessage());
    }

    public function testInvalidVersionMessageNamesTheOffendingValue(): void
    {
        $e = InvalidManifestException::invalidVersion('ContractManifest', 'version', 'abc', 'milpa.command');

        self::assertSame(
            'ContractManifest "milpa.command" field "version" is not a valid semantic version: "abc".',
            $e->getMessage(),
        );
    }

    public function testNotAnArrayMessage(): void
    {
        $e = InvalidManifestException::notAnArray('VersionManifest', 'contracts', 'milpa/command');

        self::assertSame(
            'VersionManifest "milpa/command" field "contracts" must be an array.',
            $e->getMessage(),
        );
    }
}
