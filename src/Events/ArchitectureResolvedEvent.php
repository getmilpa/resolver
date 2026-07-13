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

namespace Milpa\Resolver\Events;

use Milpa\Resolver\Report\ResolutionReport;

/**
 * Dispatched by a booting host ('architecture.resolved') the instant milpa/resolver has resolved the
 * whole architecture graph and BEFORE any plugin boots — the boot-side counterpart of the CLI's
 * `coa:inspect architecture`. It carries the full {@see ResolutionReport} so a listener sees the same
 * agent-shaped diagnosis (status, learnable errors, resolved/missing/legacy, migration hints) the CLI
 * and CI already see, without re-resolving.
 *
 * Readonly, POST, no slot — a pure notification. The runtime only reaches this dispatch on a
 * non-blocked graph (a blocked one throws a learnable {@see \Milpa\Exceptions\Plugin\PluginDependencyException}
 * before boot), so a listener can trust the report describes a bootable architecture.
 *
 * It lives in milpa/resolver, not milpa/core, on purpose: the payload it carries is the resolver's own
 * {@see ResolutionReport}, and milpa/core's frozen {@see \Milpa\Events\CapabilityResolvedEvent} could
 * not grow that payload without breaking its BC. So the report travels on this new event instead, and
 * runtime 0.3.1 dispatches BOTH — 'architecture.resolved' (this, the report) and, byte-identically to
 * before, 'capability.resolved' (the dependency-ordered load list).
 */
final readonly class ArchitectureResolvedEvent
{
    /**
     * @param ResolutionReport $report The finalized, non-blocked resolution for this boot.
     */
    public function __construct(
        public ResolutionReport $report,
    ) {
    }

    /**
     * The report's serialized agent shape (spec §20): the deterministic array a JSON/CI/agent listener
     * consumes — identical to what `coa:inspect architecture --json` emits. A convenience over reading
     * {@see $report} directly; the rich object stays available for listeners that want the typed errors.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->report->toArray();
    }
}
