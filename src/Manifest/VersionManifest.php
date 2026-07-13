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

namespace Milpa\Resolver\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Support\ManifestData;

/**
 * Describes one installed piece — a package or plugin — as the resolver sees it: its `package`
 * name and `version`, the architectural `contracts` it implements/requires, the `capabilities` it
 * provides/requires/suggests, the `surfaces` it supports, the things it has marked `deprecations`,
 * and free-form `metadata` (the ingestion layer stamps `metadata`, e.g. `shape = legacy-contracts`).
 * A pure value object: {@see fromArray()} validates, {@see toArray()} serializes deterministically.
 *
 * `adapters` and `profiles` are deliberately NOT modelled in slice 1: the resolver does not yet
 * resolve adapter or profile requirements, and a declared field the engine never reads is decorative
 * metadata (spec §25 anti-pattern 5). They return as typed fields when the resolver actually consumes
 * them (adapter resolution / env profiles — ROADMAP P10, "second consumer").
 */
final readonly class VersionManifest
{
    /**
     * @param array<string, mixed> $contracts
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $surfaces
     * @param array<string, mixed> $deprecations
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $package,
        public string $version,
        public array $contracts,
        public array $capabilities,
        public array $surfaces = [],
        public array $deprecations = [],
        public array $metadata = [],
    ) {
    }

    /**
     * Build a manifest from a decoded array, validating `package` (non-empty), `version` (semver),
     * and the presence of the `contracts` and `capabilities` maps.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException On a missing/empty required field or an invalid version.
     */
    public static function fromArray(array $data): self
    {
        $package = ManifestData::requireString($data, 'package', 'VersionManifest');

        return new self(
            package: $package,
            version: ManifestData::requireSemver($data, 'version', 'VersionManifest', $package),
            contracts: ManifestData::requireArray($data, 'contracts', 'VersionManifest', $package),
            capabilities: ManifestData::requireArray($data, 'capabilities', 'VersionManifest', $package),
            surfaces: ManifestData::optionalArray($data, 'surfaces'),
            deprecations: ManifestData::optionalArray($data, 'deprecations'),
            metadata: ManifestData::optionalArray($data, 'metadata'),
        );
    }

    /**
     * Serialize to an array with a fixed, deterministic key order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'version' => $this->version,
            'contracts' => $this->contracts,
            'capabilities' => $this->capabilities,
            'surfaces' => $this->surfaces,
            'deprecations' => $this->deprecations,
            'metadata' => $this->metadata,
        ];
    }
}
