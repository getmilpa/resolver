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

namespace Milpa\Resolver\Ingest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Support\ManifestData;
use Milpa\Resolver\Support\RecordShape;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;

/**
 * Reads a `milpa.json` from disk into a uniform {@see VersionManifest}, in BOTH real shapes:
 *
 * - Canonical (012, `capabilities.*` typed records) — passed through core's capability records to
 *   validate and normalize, and marked `metadata['shape'] = 'canonical'`.
 * - Legacy (`contracts.*` bare-FQCN lists — the five CRM plugins) — each FQCN synthesized into an
 *   unversioned record (`contractVersion 0.0.0` for provides, `constraint *` for requires/suggests,
 *   exactly the defaults core's `fromInterface()` applies), moved under `capabilities.*`, and marked
 *   `metadata['shape'] = 'legacy-contracts'` — the marker the engine's legacy detector reads.
 *
 * The real extras neither shape resolves yet (`milpa.min-version` / `php-version`, `env-vars`,
 * `dependencies`, `compatibility`, `config`, `assets`, `type`) ride through in `metadata` as honest
 * passthrough — no new value-object fields for data the engine does not consume (spec §25 anti-pattern 5).
 *
 * This layer DOES touch the filesystem (that is its job) but is otherwise deterministic. Every failure
 * — missing file, unreadable file, invalid JSON, a shapeless or field-corrupt manifest — surfaces as an
 * {@see InvalidManifestException} naming the file path and, where known, the offending field.
 */
final class ManifestLoader
{
    /**
     * Load and normalize the manifest at `$path`.
     *
     * @throws InvalidManifestException When the file is missing/unreadable, is not valid JSON, or decodes
     *                                  to a structurally invalid manifest.
     */
    public function load(string $path): VersionManifest
    {
        if (!is_file($path)) {
            throw InvalidManifestException::missingFile($path);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw InvalidManifestException::unreadableFile($path);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidManifestException::invalidJson($path, $e->getMessage(), $e);
        }

        if (!is_array($decoded)) {
            throw InvalidManifestException::malformed($path, 'the top-level JSON value is not an object.');
        }

        /** @var array<string, mixed> $decoded */
        try {
            return $this->fromDecoded($decoded);
        } catch (\InvalidArgumentException $e) {
            // Wrap every field-level failure (VersionManifest's own InvalidManifestException, or a core
            // capability record's InvalidArgumentException) so the message names BOTH the path and the field.
            throw InvalidManifestException::malformed($path, $e->getMessage(), $e);
        }
    }

    /**
     * Turn a decoded manifest array into a normalized VersionManifest, detecting shape and synthesizing
     * legacy FQCNs into records.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When a required field is missing/invalid or the shape is unrecognized.
     */
    private function fromDecoded(array $data): VersionManifest
    {
        $name = ManifestData::requireString($data, 'name', 'ManifestLoader');
        $version = ManifestData::requireSemver($data, 'version', 'ManifestLoader', $name);

        $capabilities = $data['capabilities'] ?? null;
        $contracts = $data['contracts'] ?? null;

        if (is_array($capabilities)) {
            $shape = 'canonical';
            /** @var array<string, mixed> $capabilities */
            $source = $capabilities;
            $engineContracts = is_array($contracts) ? $contracts : [];
        } elseif (is_array($contracts) && $this->looksLikeLegacyContracts($contracts)) {
            $shape = 'legacy-contracts';
            /** @var array<string, mixed> $contracts */
            $source = $contracts;
            // The legacy `contracts.*` lists ARE capability declarations; nothing remains as engine-level
            // contract implements/requires.
            $engineContracts = [];
        } else {
            // Plain InvalidArgumentException: load()'s outer catch wraps it once, with the file path.
            throw new \InvalidArgumentException(
                'manifest declares neither a "capabilities" block nor a legacy "contracts" block.',
            );
        }

        /** @var array<string, mixed> $engineContracts */
        return VersionManifest::fromArray([
            'package' => $name,
            'version' => $version,
            'contracts' => $engineContracts,
            'capabilities' => [
                'provides' => $this->synthesizeProvides($this->list($source, 'provides')),
                'requires' => $this->synthesizeRequires($this->list($source, 'requires')),
                'suggests' => $this->synthesizeSuggests($this->list($source, 'suggests')),
            ],
            'surfaces' => is_array($data['surfaces'] ?? null) ? $data['surfaces'] : [],
            'deprecations' => is_array($data['deprecations'] ?? null) ? $data['deprecations'] : [],
            'metadata' => $this->metadata($data, $shape),
        ]);
    }

    /**
     * A legacy plugin manifest carries its capabilities under `contracts.{provides,requires,suggests}`.
     *
     * @param array<string, mixed> $contracts
     */
    private function looksLikeLegacyContracts(array $contracts): bool
    {
        return array_key_exists('provides', $contracts)
            || array_key_exists('requires', $contracts)
            || array_key_exists('suggests', $contracts);
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function synthesizeProvides(array $entries): array
    {
        $out = [];
        foreach ($entries as $index => $entry) {
            $out[] = RecordShape::provision(CapabilityProvision::parse($this->entry($entry, 'provides', $index)));
        }

        return $out;
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function synthesizeRequires(array $entries): array
    {
        $out = [];
        foreach ($entries as $index => $entry) {
            $out[] = RecordShape::requirement(CapabilityRequirement::parse($this->entry($entry, 'requires', $index)));
        }

        return $out;
    }

    /**
     * @param list<mixed> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function synthesizeSuggests(array $entries): array
    {
        $out = [];
        foreach ($entries as $index => $entry) {
            $out[] = RecordShape::suggestion(CapabilitySuggestion::parse($this->entry($entry, 'suggests', $index)));
        }

        return $out;
    }

    /**
     * Assert a capability entry is a parseable shape — a bare FQCN string or a structured record.
     *
     * @return string|array<string, mixed>
     *
     * @throws \InvalidArgumentException When the entry is neither a string nor an array.
     */
    private function entry(mixed $entry, string $field, int|string $index): string|array
    {
        if (is_string($entry) || is_array($entry)) {
            /** @var string|array<string, mixed> $entry */
            return $entry;
        }

        throw new \InvalidArgumentException(sprintf(
            'capabilities.%s entry #%s must be an FQCN string or a record object.',
            $field,
            (string) $index,
        ));
    }

    /**
     * Read a nested list under `$key`, tolerating a missing or non-array value.
     *
     * @param array<string, mixed> $map
     *
     * @return list<mixed>
     */
    private function list(array $map, string $key): array
    {
        $value = $map[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Build the manifest metadata: the shape marker plus honest passthrough of every real extra the
     * engine does not resolve yet.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function metadata(array $data, string $shape): array
    {
        $metadata = ManifestData::optionalArray($data, 'metadata');
        $metadata['shape'] = $shape;

        $type = $data['type'] ?? null;
        if (is_string($type) && trim($type) !== '') {
            $metadata['pluginType'] = trim($type);
        }

        foreach (['milpa', 'dependencies', 'compatibility', 'config', 'assets'] as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $metadata[$key] = $value;
            }
        }

        if (array_key_exists('env-vars', $data)) {
            $metadata['env-vars'] = $data['env-vars'];
        }

        return $metadata;
    }
}
