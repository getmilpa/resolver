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
 * class carries — into a {@see VersionManifest}. The attribute's `provides`/`requires`/`suggests` accept
 * BOTH real entry shapes, exactly like the manifest loader does:
 *
 * - a bare interface FQCN string (legacy) — synthesized into exactly the same unversioned records the
 *   legacy manifest loader produces (`contractVersion 0.0.0` / `constraint *`, with `exclusive: false`
 *   pinned by core's `fromInterface()`);
 * - a structured capability record (canonical 012, T087) — routed through core's `fromArray()` verbatim,
 *   so it validates and normalizes exactly like a canonical `milpa.json` record (real
 *   `contractVersion`/`service`, `exclusive` defaulting TRUE per capability-spec §3.1).
 *
 * Mixing both shapes in one attribute is valid — that is the incremental migration path. The attribute's
 * `type` — the only surface declaration available at the attribute level — rides through as
 * `metadata['pluginType']`. The manifest is marked `metadata['shape'] = 'attribute'`, never
 * `legacy-contracts`: that marker belongs to legacy-shaped FILES, so an attribute-declared record — rich
 * or bare — resolves through the engine's non-legacy path.
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
                    'provides' => $this->synthesizeProvides(array_values($metadata->provides)),
                    'requires' => $this->synthesizeRequires(array_values($metadata->requires)),
                    'suggests' => $this->synthesizeSuggests(array_values($metadata->suggests)),
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

    /**
     * Synthesize the `provides` entries — bare FQCNs and structured records alike — through core's
     * `CapabilityProvision::parse()` into the canonical flattened shape.
     *
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
     * Synthesize the `requires` entries through core's `CapabilityRequirement::parse()`.
     *
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
     * Synthesize the `suggests` entries through core's `CapabilitySuggestion::parse()`.
     *
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
     * Assert a capability entry is a parseable shape — a bare FQCN string or a structured record —
     * mirroring {@see ManifestLoader}'s guard so both metadata sources teach the same lesson.
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
            '#[PluginMetadata] %s entry #%s must be an FQCN string or a record object.',
            $field,
            (string) $index,
        ));
    }
}
