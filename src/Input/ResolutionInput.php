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

namespace Milpa\Resolver\Input;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Support\ManifestData;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;

/**
 * Everything the engine needs to resolve an architecture, fully materialized and side-effect-free:
 * the {@see HostProfile}, the installed {@see VersionManifest}s and {@see ContractManifest}s, the
 * versioned capability provisions and requirements (the canonical records from milpa/core, reused
 * here rather than redefined), and the surfaces currently active plus the resolution environment.
 * The engine receives one of these and returns a report; it never reads the filesystem itself.
 */
final readonly class ResolutionInput
{
    /**
     * @param list<VersionManifest>       $versionManifests
     * @param list<ContractManifest>      $contractManifests
     * @param list<CapabilityProvision>   $capabilityProvisions
     * @param list<CapabilityRequirement> $capabilityRequirements
     * @param list<string>                $activeSurfaces
     * @param array<string, mixed>        $environment
     */
    public function __construct(
        public HostProfile $hostProfile,
        public array $versionManifests,
        public array $contractManifests,
        public array $capabilityProvisions,
        public array $capabilityRequirements,
        public array $activeSurfaces = [],
        public array $environment = [],
    ) {
    }

    /**
     * Build a resolution input from a decoded array, delegating each nested record to its own value
     * object's `fromArray()` — the capability provisions/requirements to the canonical milpa/core
     * records.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When the host profile or any nested manifest is invalid.
     */
    public static function fromArray(array $data): self
    {
        $hostProfile = HostProfile::fromArray(
            ManifestData::requireArray($data, 'hostProfile', 'ResolutionInput'),
        );

        $versionManifests = [];
        foreach (ManifestData::recordList($data, 'versionManifests') as $record) {
            $versionManifests[] = VersionManifest::fromArray($record);
        }

        $contractManifests = [];
        foreach (ManifestData::recordList($data, 'contractManifests') as $record) {
            $contractManifests[] = ContractManifest::fromArray($record);
        }

        $capabilityProvisions = [];
        foreach (ManifestData::recordList($data, 'capabilityProvisions') as $record) {
            $capabilityProvisions[] = CapabilityProvision::fromArray($record);
        }

        $capabilityRequirements = [];
        foreach (ManifestData::recordList($data, 'capabilityRequirements') as $record) {
            $capabilityRequirements[] = CapabilityRequirement::fromArray($record);
        }

        return new self(
            hostProfile: $hostProfile,
            versionManifests: $versionManifests,
            contractManifests: $contractManifests,
            capabilityProvisions: $capabilityProvisions,
            capabilityRequirements: $capabilityRequirements,
            activeSurfaces: ManifestData::stringList($data, 'activeSurfaces'),
            environment: ManifestData::optionalArray($data, 'environment'),
        );
    }

    /**
     * Serialize to an array with a fixed, deterministic key order. The capability records — which
     * milpa/core does not serialize itself — are flattened back to their canonical 012 shapes here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hostProfile' => $this->hostProfile->toArray(),
            'versionManifests' => array_map(static fn (VersionManifest $m): array => $m->toArray(), $this->versionManifests),
            'contractManifests' => array_map(static fn (ContractManifest $m): array => $m->toArray(), $this->contractManifests),
            'capabilityProvisions' => array_map(self::provisionToArray(...), $this->capabilityProvisions),
            'capabilityRequirements' => array_map(self::requirementToArray(...), $this->capabilityRequirements),
            'activeSurfaces' => $this->activeSurfaces,
            'environment' => $this->environment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function provisionToArray(CapabilityProvision $provision): array
    {
        return [
            'id' => $provision->id,
            'interface' => $provision->interface,
            'contractVersion' => $provision->contractVersion,
            'service' => $provision->service,
            'priority' => $provision->priority,
            'exclusive' => $provision->exclusive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requirementToArray(CapabilityRequirement $requirement): array
    {
        return [
            'id' => $requirement->id,
            'interface' => $requirement->interface,
            'constraint' => $requirement->constraint,
            'oneOf' => $requirement->oneOf,
        ];
    }
}
