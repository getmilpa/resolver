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

namespace Milpa\Resolver\Contracts;

use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Report\ResolutionReport;

/**
 * The public seam of the resolver: given a fully-materialized {@see ResolutionInput}, decide whether
 * the architecture closes and return a {@see ResolutionReport} classifying it as valid,
 * bootable-with-warnings, blocked, or legacy-compatible. Implementations are pure — same input, same
 * report — and never touch the filesystem, the network, or the clock.
 */
interface ArchitectureResolver
{
    /**
     * Resolve the architecture described by the input into a report.
     */
    public function resolve(ResolutionInput $input): ResolutionReport;
}
