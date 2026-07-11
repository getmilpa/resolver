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

use Milpa\Resolver\Manifest\VersionManifest;

/**
 * Detects manifest drift: the gap between what a package DECLARES in its `milpa.json` and what its code
 * ACTUALLY carries in `#[PluginMetadata]`. It diffs the two {@see VersionManifest}s field by field —
 * `name`, `version`, and the `provides`/`requires`/`suggests` capability sets (identity compared by
 * interface/id, normalized with `ltrim('\\')` so a leading-backslash FQCN and its bare form are equal).
 *
 * This is the tested primitive only. Turning a non-empty diff into the learnable `manifest drift` error
 * is slice 2; slice 1 ships and proves the detector.
 */
final class DriftDetector
{
    /**
     * Diff a declared manifest against the actual one.
     *
     * @return list<array{field: string, declared: string|null, actual: string|null}>
     */
    public function diff(VersionManifest $declared, VersionManifest $actual): array
    {
        $drift = [];

        if ($declared->package !== $actual->package) {
            $drift[] = ['field' => 'name', 'declared' => $declared->package, 'actual' => $actual->package];
        }

        if ($declared->version !== $actual->version) {
            $drift[] = ['field' => 'version', 'declared' => $declared->version, 'actual' => $actual->version];
        }

        foreach (['provides', 'requires', 'suggests'] as $facet) {
            $declaredIds = $this->identities($declared->capabilities, $facet);
            $actualIds = $this->identities($actual->capabilities, $facet);

            foreach (array_diff($declaredIds, $actualIds) as $id) {
                $drift[] = ['field' => $facet, 'declared' => $id, 'actual' => null];
            }
            foreach (array_diff($actualIds, $declaredIds) as $id) {
                $drift[] = ['field' => $facet, 'declared' => null, 'actual' => $id];
            }
        }

        // Deterministic order: same two manifests always yield a byte-identical diff.
        usort($drift, static function (array $a, array $b): int {
            return [$a['field'], $a['declared'] ?? '', $a['actual'] ?? '']
                <=> [$b['field'], $b['declared'] ?? '', $b['actual'] ?? ''];
        });

        return $drift;
    }

    /**
     * The normalized identity set for one capability facet — each entry's interface (or id), with any
     * leading namespace backslash stripped so the comparison is shape-agnostic.
     *
     * @param array<string, mixed> $capabilities
     *
     * @return list<string>
     */
    private function identities(array $capabilities, string $facet): array
    {
        $set = [];
        foreach ($this->list($capabilities, $facet) as $entry) {
            $identity = $this->identity($entry);
            if ($identity !== null) {
                $set[$this->normalize($identity)] = true;
            }
        }

        $ids = array_keys($set);
        sort($ids);

        return $ids;
    }

    /**
     * The identity of a capability entry: the interface (or, absent that, the id) of a structured record,
     * or the string itself for a bare FQCN.
     */
    private function identity(mixed $entry): ?string
    {
        if (is_string($entry)) {
            $entry = trim($entry);

            return $entry === '' ? null : $entry;
        }

        if (is_array($entry)) {
            foreach (['interface', 'id'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    $value = trim((string) $entry[$key]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function normalize(string $identity): string
    {
        return ltrim(trim($identity), '\\');
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
}
