<?php

/**
 * This file is part of Milpa Resolver — the architecture resolver for the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/resolver
 */

declare(strict_types=1);

namespace Milpa\Resolver\Support;

use Composer\Semver\VersionParser;
use Milpa\Resolver\Exceptions\InvalidManifestException;

/**
 * Internal helpers that turn a decoded manifest array into validated, strictly-typed value-object
 * fields. Every failure surfaces as an {@see InvalidManifestException}; callers pass the value-object
 * `$type` (and, where known, the record `$subject`) so a failure names the exact offending entry.
 * Shared by the manifest value objects so version and required-field validation is defined once.
 */
final class ManifestData
{
    /**
     * Extract a required, non-empty string field.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When the field is absent or empty.
     */
    public static function requireString(array $data, string $key, string $type, ?string $subject = null): string
    {
        $raw = $data[$key] ?? null;
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        if ($value === '') {
            throw InvalidManifestException::missingField($type, $key, $subject);
        }

        return $value;
    }

    /**
     * Extract a required field and assert it parses as a semantic version. Uses composer/semver's
     * parser, which is lenient enough for two-part contract versions (`0.1`) and calendar host
     * versions (`2026.07`) yet still rejects non-versions.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When the field is absent or not a valid version.
     */
    public static function requireSemver(array $data, string $key, string $type, ?string $subject = null): string
    {
        $raw = $data[$key] ?? null;
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        if ($value === '') {
            throw InvalidManifestException::missingField($type, $key, $subject);
        }

        try {
            (new VersionParser())->normalize($value);
        } catch (\UnexpectedValueException) {
            throw InvalidManifestException::invalidVersion($type, $key, $value, $subject);
        }

        return $value;
    }

    /**
     * Extract a required array field (also used for nested value objects such as the host profile).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     *
     * @throws InvalidManifestException When the field is absent or not an array.
     */
    public static function requireArray(array $data, string $key, string $type, ?string $subject = null): array
    {
        if (!array_key_exists($key, $data)) {
            throw InvalidManifestException::missingField($type, $key, $subject);
        }
        if (!is_array($data[$key])) {
            throw InvalidManifestException::notAnArray($type, $key, $subject);
        }

        /** @var array<string, mixed> $record */
        $record = $data[$key];

        return $record;
    }

    /**
     * Extract an optional array field, defaulting to an empty array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function optionalArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }

        /** @var array<string, mixed> $value */
        $value = $data[$key];

        return $value;
    }

    /**
     * Extract an optional non-empty string field, defaulting to null.
     *
     * @param array<string, mixed> $data
     */
    public static function optionalString(array $data, string $key): ?string
    {
        $raw = $data[$key] ?? null;
        if (!is_scalar($raw)) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * Extract an optional ISO-8601 date/datetime field, defaulting to null. Present-but-unparseable
     * values throw so an expiry can be trusted. Relative expressions ("now", "tomorrow", "+1 day") are
     * rejected as non-ISO on purpose: they would make parsing read the wall clock and break the
     * resolver's purity — the clock is data the caller supplies, never an ambient read.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When the field is present but not a valid ISO-8601 date.
     */
    public static function optionalIsoDate(array $data, string $key, string $type, ?string $subject = null): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        $raw = $data[$key];
        if (!is_string($raw)) {
            throw InvalidManifestException::invalidIsoDate($type, $key, is_scalar($raw) ? (string) $raw : get_debug_type($raw), $subject);
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (!self::isIsoDate($value)) {
            throw InvalidManifestException::invalidIsoDate($type, $key, $value, $subject);
        }

        return $value;
    }

    /**
     * Whether a string is an ISO-8601 date or datetime: year-first shape (so relative expressions are
     * rejected) that the datetime parser also accepts.
     */
    public static function isIsoDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ][0-9:.,+\-Z]*)?$/', $value) !== 1) {
            return false;
        }
        try {
            new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    /**
     * Coerce an optional field into a list of non-empty strings, dropping non-scalar entries.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $out = [];
        foreach (self::optionalArray($data, $key) as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Coerce an optional field into a list of associative records, dropping non-array entries.
     * Each record is ready to hand to another value object's `fromArray()`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function recordList(array $data, string $key): array
    {
        $out = [];
        foreach (self::optionalArray($data, $key) as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }
}
