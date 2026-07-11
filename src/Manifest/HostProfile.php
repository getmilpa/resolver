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

namespace Milpa\Resolver\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Support\ManifestData;

/**
 * The architectural shape an application expects — deliberately NOT the same thing as
 * `composer.json`. It names the contracts the host requires, the surfaces it enables, the
 * capabilities it depends on, the legacy contracts it tolerates, and the risks it has explicitly
 * accepted (a warning the host has acknowledged stays visible in the report but does not degrade
 * the status). A pure value object: {@see fromArray()} validates, {@see toArray()} serializes
 * deterministically.
 */
final readonly class HostProfile
{
    /**
     * @param list<string>         $requiredContracts
     * @param list<string>         $enabledSurfaces
     * @param list<string>         $requiredCapabilities
     * @param list<string>         $allowedLegacyContracts
     * @param list<string>         $acceptedRisks
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $requiredContracts = [],
        public array $enabledSurfaces = [],
        public array $requiredCapabilities = [],
        public array $allowedLegacyContracts = [],
        public array $acceptedRisks = [],
        public array $metadata = [],
    ) {
    }

    /**
     * Build a host profile from a decoded array, validating `name` (non-empty) and `version`
     * (a valid — possibly calendar — version).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException On a missing/empty required field or an invalid version.
     */
    public static function fromArray(array $data): self
    {
        $name = ManifestData::requireString($data, 'name', 'HostProfile');

        return new self(
            name: $name,
            version: ManifestData::requireSemver($data, 'version', 'HostProfile', $name),
            requiredContracts: ManifestData::stringList($data, 'requiredContracts'),
            enabledSurfaces: ManifestData::stringList($data, 'enabledSurfaces'),
            requiredCapabilities: ManifestData::stringList($data, 'requiredCapabilities'),
            allowedLegacyContracts: ManifestData::stringList($data, 'allowedLegacyContracts'),
            acceptedRisks: ManifestData::stringList($data, 'acceptedRisks'),
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
            'name' => $this->name,
            'version' => $this->version,
            'requiredContracts' => $this->requiredContracts,
            'enabledSurfaces' => $this->enabledSurfaces,
            'requiredCapabilities' => $this->requiredCapabilities,
            'allowedLegacyContracts' => $this->allowedLegacyContracts,
            'acceptedRisks' => $this->acceptedRisks,
            'metadata' => $this->metadata,
        ];
    }
}
