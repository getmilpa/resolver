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
 * Describes one architectural contract: its `id` and `version` (a two-part contract version such as
 * `0.1`), the capabilities it requires/provides/suggests, its surface requirements, and optional
 * links to the contract's Academy unit and migration guide. A pure value object:
 * {@see fromArray()} validates, {@see toArray()} serializes deterministically.
 *
 * `adapterRequirements` is deliberately NOT modelled in slice 1: the resolver does not yet resolve
 * adapters, and a field the engine never reads is decorative metadata (spec §25 anti-pattern 5). It
 * returns as a typed field when adapter resolution lands (ROADMAP P10).
 */
final readonly class ContractManifest
{
    /**
     * @param list<string> $requiresCapabilities
     * @param list<string> $providesCapabilities
     * @param list<string> $suggestsCapabilities
     * @param list<string> $surfaceRequirements
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $requiresCapabilities = [],
        public array $providesCapabilities = [],
        public array $suggestsCapabilities = [],
        public array $surfaceRequirements = [],
        public ?string $academyUrl = null,
        public ?string $migrationUrl = null,
    ) {
    }

    /**
     * Build a contract manifest from a decoded array, validating `id` (non-empty) and `version`
     * (a valid — possibly two-part — version).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException On a missing/empty required field or an invalid version.
     */
    public static function fromArray(array $data): self
    {
        $id = ManifestData::requireString($data, 'id', 'ContractManifest');

        return new self(
            id: $id,
            version: ManifestData::requireSemver($data, 'version', 'ContractManifest', $id),
            requiresCapabilities: ManifestData::stringList($data, 'requiresCapabilities'),
            providesCapabilities: ManifestData::stringList($data, 'providesCapabilities'),
            suggestsCapabilities: ManifestData::stringList($data, 'suggestsCapabilities'),
            surfaceRequirements: ManifestData::stringList($data, 'surfaceRequirements'),
            academyUrl: ManifestData::optionalString($data, 'academyUrl'),
            migrationUrl: ManifestData::optionalString($data, 'migrationUrl'),
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
            'id' => $this->id,
            'version' => $this->version,
            'requiresCapabilities' => $this->requiresCapabilities,
            'providesCapabilities' => $this->providesCapabilities,
            'suggestsCapabilities' => $this->suggestsCapabilities,
            'surfaceRequirements' => $this->surfaceRequirements,
            'academyUrl' => $this->academyUrl,
            'migrationUrl' => $this->migrationUrl,
        ];
    }
}
