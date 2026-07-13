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

namespace Milpa\Resolver\Advisor;

/**
 * The actionable migration plan the {@see MigrationAdvisor} derives from a frozen
 * {@see \Milpa\Resolver\Report\ResolutionReport} — spec §14's `milpa migrate --plan`: it changes
 * nothing, it only proposes. A pure value object: the report's `status` verbatim plus one entry per
 * package with actionable work — what was `detected`, the `recommended` targets, the numbered
 * `steps` (1..n, the LAST always re-running `coa:inspect architecture`), the honest `compatibility`
 * line, and the live bilingual `academy` links the report's learnable errors already carry.
 *
 * {@see toArray()} serializes to the FROZEN plan shape (drift-locked by
 * `tests/Advisor/MigrationPlanShapeContractsTest` and the README "Migration plan shape" tables):
 * `{status, packages: [...], summary: {packages, actions}}`, in that exact key order. The `summary`
 * is DERIVED here on every serialization — `packages` is the package count and `actions` the total
 * step count — so it can never disagree with the packages it summarizes. A report with nothing
 * actionable serializes as the VISIBLE empty plan (`packages: []`, summary `0/0`), never a null:
 * nothing to migrate is a stated fact.
 */
final readonly class MigrationPlan
{
    /**
     * @param string                     $status   The originating report's status, verbatim — the
     *                                             plan never invents a fifth verdict.
     * @param list<array<string, mixed>> $packages One frozen entry per package with actionable work,
     *                                             sorted by package name; a package with nothing
     *                                             actionable never appears.
     */
    public function __construct(
        public string $status,
        public array $packages = [],
    ) {
    }

    /**
     * Serialize to the frozen plan shape with a fixed, deterministic key order; the `summary`
     * counts are re-derived from the packages on every call, so they can never drift.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $actions = 0;
        foreach ($this->packages as $package) {
            $steps = $package['steps'] ?? null;
            $actions += is_array($steps) ? count($steps) : 0;
        }

        return [
            'status' => $this->status,
            'packages' => $this->packages,
            'summary' => [
                'packages' => count($this->packages),
                'actions' => $actions,
            ],
        ];
    }
}
