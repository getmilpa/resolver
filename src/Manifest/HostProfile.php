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
     * @param list<AcceptedRisk>   $acceptedRisks
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
            acceptedRisks: self::parseAcceptedRisks($data),
            metadata: ManifestData::optionalArray($data, 'metadata'),
        );
    }

    /**
     * Parse `acceptedRisks` into validated {@see AcceptedRisk} objects. The pre-0.2 bare-string shape
     * is rejected with a message that teaches the object shape (a bare code can carry no reason, so it
     * could only ever silence the warning it names).
     *
     * @param array<string, mixed> $data
     *
     * @return list<AcceptedRisk>
     *
     * @throws InvalidManifestException On a bare-string entry, a non-object entry, or an invalid risk.
     */
    private static function parseAcceptedRisks(array $data): array
    {
        $out = [];
        foreach (ManifestData::optionalArray($data, 'acceptedRisks') as $entry) {
            if (is_string($entry)) {
                throw InvalidManifestException::acceptedRiskLegacyShape($entry);
            }
            if (!is_array($entry)) {
                throw InvalidManifestException::acceptedRiskLegacyShape(is_scalar($entry) ? (string) $entry : get_debug_type($entry));
            }
            /** @var array<string, mixed> $entry */
            $out[] = AcceptedRisk::fromArray($entry);
        }

        return $out;
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
            'acceptedRisks' => array_map(static fn (AcceptedRisk $r): array => $r->toArray(), $this->acceptedRisks),
            'metadata' => $this->metadata,
        ];
    }
}
