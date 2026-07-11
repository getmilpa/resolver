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

namespace Milpa\Resolver\Capability;

/**
 * How strongly the graph depends on a capability. A missing `Required` capability blocks boot; a
 * missing `Suggested` one degrades to a warning (a fallback path); an `Optional` one is purely
 * additive. This is the resolution-level classification the engine reasons in — the canonical
 * `requires` / `suggests` records themselves live in milpa/core.
 */
enum RequirementLevel: string
{
    case Required = 'required';
    case Suggested = 'suggested';
    case Optional = 'optional';
}
