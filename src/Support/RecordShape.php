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

namespace Milpa\Resolver\Support;

use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;

/**
 * Flattens the milpa/core capability records back to their canonical 012 array shapes. milpa/core does
 * not serialize its own records, so the ingestion loaders round-trip a bare FQCN or a typed record
 * THROUGH a core record (which validates it and applies the unversioned defaults `contractVersion 0.0.0`
 * / `constraint *`) and back to the plain array the pure engine reads via `CapabilityProvision::parse()`.
 * Defined once here so both {@see \Milpa\Resolver\Ingest\ManifestLoader} and
 * {@see \Milpa\Resolver\Ingest\AttributeLoader} emit byte-identical record shapes.
 */
final class RecordShape
{
    /**
     * Flatten a `provides` provision to its `{id, interface, contractVersion, service, priority, exclusive}` shape.
     *
     * @return array<string, mixed>
     */
    public static function provision(CapabilityProvision $provision): array
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
     * Flatten a `requires` requirement to its `{id, interface, constraint, oneOf}` shape.
     *
     * @return array<string, mixed>
     */
    public static function requirement(CapabilityRequirement $requirement): array
    {
        return [
            'id' => $requirement->id,
            'interface' => $requirement->interface,
            'constraint' => $requirement->constraint,
            'oneOf' => $requirement->oneOf,
        ];
    }

    /**
     * Flatten a `suggests` suggestion to its `{id, interface, constraint, fallback}` shape.
     *
     * @return array<string, mixed>
     */
    public static function suggestion(CapabilitySuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'interface' => $suggestion->interface,
            'constraint' => $suggestion->constraint,
            'fallback' => $suggestion->fallback,
        ];
    }
}
