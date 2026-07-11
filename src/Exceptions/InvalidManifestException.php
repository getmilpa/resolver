<?php

/**
 * This file is part of Milpa Resolver — the architecture resolver for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/resolver
 */

declare(strict_types=1);

namespace Milpa\Resolver\Exceptions;

use Milpa\Exceptions\MilpaExceptionInterface;

/**
 * Thrown when a decoded manifest array cannot be turned into a valid value object — a required
 * field is absent, a version is not valid semver, or a field has the wrong type. Implements the
 * framework marker {@see MilpaExceptionInterface} so hosts can catch every Milpa-originated error
 * uniformly. Messages are technical English and name the value-object type, the offending field,
 * and (where known) the record subject, so the failure points at the exact manifest entry.
 */
final class InvalidManifestException extends \InvalidArgumentException implements MilpaExceptionInterface
{
    /**
     * A required field is absent (or empty).
     */
    public static function missingField(string $type, string $field, ?string $subject = null): self
    {
        return new self(sprintf(
            '%s%s is missing required field "%s".',
            $type,
            self::subject($subject),
            $field,
        ));
    }

    /**
     * A version field does not parse as a semantic version.
     */
    public static function invalidVersion(string $type, string $field, string $value, ?string $subject = null): self
    {
        return new self(sprintf(
            '%s%s field "%s" is not a valid semantic version: "%s".',
            $type,
            self::subject($subject),
            $field,
            $value,
        ));
    }

    /**
     * A field expected to hold an array holds something else.
     */
    public static function notAnArray(string $type, string $field, ?string $subject = null): self
    {
        return new self(sprintf(
            '%s%s field "%s" must be an array.',
            $type,
            self::subject($subject),
            $field,
        ));
    }

    /**
     * A field holds a value outside the set the type accepts (e.g. an unknown enum case).
     */
    public static function unexpectedValue(string $type, string $field, string $value, ?string $subject = null): self
    {
        return new self(sprintf(
            '%s%s field "%s" has an unrecognized value: "%s".',
            $type,
            self::subject($subject),
            $field,
            $value,
        ));
    }

    /**
     * The manifest file does not exist at the given path (an ingestion-layer failure — the path is the
     * subject, since there is no decoded content to point at yet).
     */
    public static function missingFile(string $path): self
    {
        return new self(sprintf('Manifest file not found: "%s".', $path));
    }

    /**
     * The manifest file exists but could not be read from disk.
     */
    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Manifest file is not readable: "%s".', $path));
    }

    /**
     * The manifest file's contents are not valid JSON; `$detail` carries the decoder's reason.
     */
    public static function invalidJson(string $path, string $detail, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Manifest "%s" is not valid JSON: %s', $path, $detail), 0, $previous);
    }

    /**
     * The manifest decoded but is structurally wrong; `$detail` (often a wrapped field-level message)
     * says what, and the path says where — so a malformed manifest always names both.
     */
    public static function malformed(string $path, string $detail, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Manifest "%s" is malformed: %s', $path, $detail), 0, $previous);
    }

    private static function subject(?string $subject): string
    {
        return $subject !== null && $subject !== '' ? " \"{$subject}\"" : '';
    }
}
