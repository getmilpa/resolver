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

namespace Milpa\Resolver\Report;

/**
 * The verdict of a resolution. `Valid`: the whole graph closes with no warnings. `BootableWithWarnings`:
 * everything required closes but a suggested capability is missing. `LegacyCompatible`: required
 * dependencies close, but through a permitted legacy adapter. `Blocked`: a required contract or
 * capability is missing (or conflicts), so boot must not proceed.
 */
enum ResolutionStatus: string
{
    case Valid = 'valid';
    case BootableWithWarnings = 'bootable_with_warnings';
    case Blocked = 'blocked';
    case LegacyCompatible = 'legacy_compatible';
}
