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

use Milpa\Attributes\PluginMetadata;
use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Support\RecordShape;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;

/**
 * Reads the ecosystem's second real metadata source — the `#[PluginMetadata]` attribute a plugin's main
 * class carries — into a {@see VersionManifest}. The attribute's `provides`/`requires`/`suggests` are
 * bare interface FQCN lists (it carries no version information), so they synthesize into exactly the same
 * unversioned records the legacy manifest loader produces (`contractVersion 0.0.0` / `constraint *`), and
 * its `type` — the only surface declaration available at the attribute level — rides through as
 * `metadata['pluginType']`. The manifest is marked `metadata['shape'] = 'attribute'`.
 *
 * Following the metadataOf pattern of {@see \Milpa\Services\CapabilityGraphChecker}, a `PluginMetadata`
 * record may also be handed in directly (via {@see fromMetadata()}) when it was already extracted and
 * no reflection is needed.
 */
final class AttributeLoader
{
    /**
     * Reflect the `#[PluginMetadata]` off `$class` and build its manifest.
     *
     * @param class-string|string $class
     *
     * @throws InvalidManifestException When the class cannot be loaded or carries no `#[PluginMetadata]`.
     */
    public function fromClass(string $class): VersionManifest
    {
        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw InvalidManifestException::malformed($class, 'class does not exist or is not loadable.', $e);
        }

        $attributes = $reflection->getAttributes(PluginMetadata::class);
        if ($attributes === []) {
            throw InvalidManifestException::malformed($class, 'class carries no #[PluginMetadata] attribute.');
        }

        return $this->fromMetadata($attributes[0]->newInstance());
    }

    /**
     * Build a manifest from an already-resolved `#[PluginMetadata]` record.
     *
     * @throws InvalidManifestException When the metadata's name/version do not form a valid manifest.
     */
    public function fromMetadata(PluginMetadata $metadata): VersionManifest
    {
        try {
            return VersionManifest::fromArray([
                'package' => $metadata->name,
                'version' => $metadata->version,
                'contracts' => [],
                'capabilities' => [
                    'provides' => array_map(
                        static fn (string $fqcn): array => RecordShape::provision(CapabilityProvision::fromInterface($fqcn)),
                        array_values($metadata->provides),
                    ),
                    'requires' => array_map(
                        static fn (string $fqcn): array => RecordShape::requirement(CapabilityRequirement::fromInterface($fqcn)),
                        array_values($metadata->requires),
                    ),
                    'suggests' => array_map(
                        static fn (string $fqcn): array => RecordShape::suggestion(CapabilitySuggestion::fromInterface($fqcn)),
                        array_values($metadata->suggests),
                    ),
                ],
                'metadata' => [
                    'shape' => 'attribute',
                    'pluginType' => $metadata->type,
                ],
            ]);
        } catch (InvalidManifestException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw InvalidManifestException::malformed($metadata->name, $e->getMessage(), $e);
        }
    }
}
