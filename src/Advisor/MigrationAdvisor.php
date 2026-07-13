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

namespace Milpa\Resolver\Advisor;

use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Report\ResolutionReport;

/**
 * Turns a frozen {@see ResolutionReport} into an actionable {@see MigrationPlan} — spec §6's
 * separation of duties, honoured strictly: the resolver detects and explains, the **Advisor
 * proposes**, a command executes only if the human accepts, and the Academy teaches the concept.
 * The advisor is PURE: it never touches the filesystem, the clock, or the network, and it never
 * writes — the same inputs always yield the same plan, byte for byte.
 *
 * Raw materials, all read-only: the report's `legacy[]` paths (with their `migrationHints[]`
 * targets), `missing[]` and `conflicts[]` entries, and the learnable `errors[]` whose `fixes[0]`
 * becomes each step's action and whose live bilingual `learn.academy` links become the plan's
 * `academy` pairs — nothing is invented, every step and link already exists in the diagnosis.
 *
 * Two caller-supplied inputs complete the picture (the same pattern as the engine's caller-owned
 * `evaluatedAt` clock):
 *
 *  - `$driftErrors` — the exact {@see LearnableArchitectureError} OBJECTS
 *    {@see \Milpa\Resolver\Ingest\DriftDetector::toLearnableErrors()} returns (code
 *    `MILPA_MANIFEST_DRIFT`, context `{package, fields}`). The objects, not their serialized
 *    arrays: the advisor stays pure either way, but the host's inspect surface already holds them
 *    verbatim, so this keeps the caller a pass-through. Entries with any other code are ignored.
 *  - `$hostProfile` — the profile the caller resolved against. `allowedLegacyContracts` and
 *    `acceptedRisks` live ONLY in the profile: the report's frozen `metadata` carries just the
 *    `hostProfile` name@version label and the verbatim `hostMetadata`, and the engine is frozen —
 *    injecting the allowlist into the report is not an option. So the caller, who built the
 *    profile to resolve in the first place, passes it here too; when it is omitted the plan's
 *    `compatibility` line says honestly that no window can be stated. No deadline is ever invented.
 *
 * Grouping rule: detections are grouped per package NAME — the attributing label (`legacy[]`'s
 * `providedBy`, `missing[]`'s `requiredBy`, a drift error's `context.package`; conflicts attach to
 * the host, whose label the report's `metadata.hostProfile` carries) with its `hostProfile:` scheme
 * prefix and its `@version` suffix stripped, so `legacy/command-host@0.0.1` and a drift on
 * `legacy/command-host` land in the SAME package entry. A package with nothing actionable never
 * appears; a report with nothing actionable yields the visible empty plan (summary `0/0`).
 */
final class MigrationAdvisor
{
    private const KIND_LEGACY_CONTRACT = 'legacy-contract';
    private const KIND_LEGACY_CAPABILITY = 'legacy-capability';
    private const KIND_MANIFEST_DRIFT = 'manifest-drift';
    private const KIND_MISSING = 'missing';
    private const KIND_CONFLICT = 'conflict';

    private const DRIFT_CODE = 'MILPA_MANIFEST_DRIFT';

    /** The verification step that closes EVERY package's step list — a plan never trusts itself. */
    private const REINSPECT_ACTION = 'Run php coa coa:inspect architecture again.';

    /**
     * Derive the migration plan for a report: group the actionable detections per package, plan the
     * steps from each diagnosis's first fix (numbered 1..n, the last ALWAYS the re-inspect), name
     * the recommended targets, state the honest compatibility line, and attach the live Academy
     * links the errors already carry. See the class DocBlock for the two caller-supplied inputs.
     *
     * @param list<LearnableArchitectureError> $driftErrors The drift diagnoses the caller built via
     *                                                      DriftDetector::toLearnableErrors()
     * @param HostProfile|null                 $hostProfile The profile resolved against — the only
     *                                                      source of allowedLegacyContracts and
     *                                                      acceptedRisks (they are not in the report)
     */
    public function advise(ResolutionReport $report, array $driftErrors = [], ?HostProfile $hostProfile = null): MigrationPlan
    {
        $groups = $this->groupDetections($report, $driftErrors);
        ksort($groups, SORT_STRING);

        $packages = [];
        foreach ($groups as $package => $detections) {
            $packages[] = [
                'package' => $package,
                'detected' => $this->detected($detections),
                'recommended' => $this->recommended($detections, $report),
                'steps' => $this->steps($detections),
                'compatibility' => $this->compatibility($detections, $hostProfile),
                'academy' => $this->academy($detections),
            ];
        }

        return new MigrationPlan($report->status->value, $packages);
    }

    /**
     * Group every actionable detection under its package name, in a fixed pass order — legacy
     * paths, then the caller's drift, then missing entries, then conflicts — so the same report
     * always groups identically. Each internal detection keeps its matched learnable error (the
     * source of steps and academy links) and, for `missing[]`, its constraint.
     *
     * @param list<LearnableArchitectureError> $driftErrors
     *
     * @return array<string, list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}>>
     */
    private function groupDetections(ResolutionReport $report, array $driftErrors): array
    {
        $groups = [];

        foreach ($report->legacy as $entry) {
            $kind = $this->str($entry, 'kind') === 'contract' ? self::KIND_LEGACY_CONTRACT : self::KIND_LEGACY_CAPABILITY;
            $id = $this->str($entry, 'id');
            $code = $this->str($entry, 'code');
            $groups[$this->packageName($this->str($entry, 'providedBy'))][] = [
                'kind' => $kind,
                'id' => $id,
                'code' => $code,
                'detail' => $this->str($entry, 'reason'),
                'constraint' => null,
                'error' => $this->findError($report, $code, $id),
            ];
        }

        foreach ($driftErrors as $error) {
            $package = is_scalar($error->context['package'] ?? null) ? (string) $error->context['package'] : '';
            if ($error->code !== self::DRIFT_CODE || $package === '') {
                continue;
            }
            $groups[$this->packageName($package)][] = [
                'kind' => self::KIND_MANIFEST_DRIFT,
                'id' => $package,
                'code' => $error->code,
                'detail' => $error->message,
                'constraint' => null,
                'error' => $error,
            ];
        }

        foreach ($report->missing as $entry) {
            $id = $this->str($entry, 'id');
            $code = $this->str($entry, 'code');
            $constraint = is_string($entry['constraint'] ?? null) && $entry['constraint'] !== '' ? $entry['constraint'] : null;
            $groups[$this->packageName($this->str($entry, 'requiredBy'))][] = [
                'kind' => self::KIND_MISSING,
                'id' => $id,
                'code' => $code,
                'detail' => $this->str($entry, 'reason'),
                'constraint' => $constraint,
                'error' => $this->findError($report, $code, $id),
            ];
        }

        $hostLabel = $this->str($report->metadata, 'hostProfile');
        foreach ($report->conflicts as $entry) {
            $id = $this->str($entry, 'id');
            $code = $this->str($entry, 'code');
            $groups[$this->packageName($hostLabel !== '' ? $hostLabel : 'host')][] = [
                'kind' => self::KIND_CONFLICT,
                'id' => $id,
                'code' => $code,
                'detail' => $this->str($entry, 'reason'),
                'constraint' => null,
                'error' => $this->findError($report, $code, $id),
            ];
        }

        return $groups;
    }

    /**
     * The frozen `detected[]` projection of a package's detections: exactly kind/id/code/detail.
     *
     * @param list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}> $detections
     *
     * @return list<array<string, string>>
     */
    private function detected(array $detections): array
    {
        $out = [];
        foreach ($detections as $detection) {
            $out[] = [
                'kind' => $detection['kind'],
                'id' => $detection['id'],
                'code' => $detection['code'],
                'detail' => $detection['detail'],
            ];
        }

        return $out;
    }

    /**
     * The recommended targets: a legacy detection recommends its migration hint's concrete `to`
     * (skipped when the hint declares none — nothing concrete is recommended over an invented
     * target), and a missing detection recommends the canonical package its diagnosis's typed
     * actions name, carrying the requirement's constraint when there is one (`constraint` is the
     * plan's ONE optional key — omitted, never null). Duplicates collapse.
     *
     * @param list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}> $detections
     *
     * @return list<array<string, string>>
     */
    private function recommended(array $detections, ResolutionReport $report): array
    {
        $out = [];
        $seen = [];
        $push = static function (array $entry) use (&$out, &$seen): void {
            $key = implode("\0", $entry);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $entry;
            }
        };

        foreach ($detections as $detection) {
            if ($detection['kind'] === self::KIND_LEGACY_CONTRACT || $detection['kind'] === self::KIND_LEGACY_CAPABILITY) {
                $to = $this->hintTarget($report, $detection['id']);
                if ($to !== null) {
                    $push(['id' => $detection['id'], 'to' => $to]);
                }

                continue;
            }

            if ($detection['kind'] === self::KIND_MISSING && $detection['error'] !== null) {
                $package = $this->actionPackage($detection['error']);
                if ($package === null) {
                    continue;
                }
                $entry = ['id' => $detection['id'], 'to' => $package];
                if ($detection['constraint'] !== null) {
                    $entry['constraint'] = $detection['constraint'];
                }
                $push($entry);
            }
        }

        return $out;
    }

    /**
     * The numbered steps: one action per detection — its diagnosis's `fixes[0]`, the human fix the
     * catalog already wrote, falling back to the detection's own detail if a diagnosis is absent —
     * deduplicated in order, then the re-inspect verification step, ALWAYS last. Numbered 1..n.
     *
     * @param list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}> $detections
     *
     * @return list<array{n: int, action: string}>
     */
    private function steps(array $detections): array
    {
        $actions = [];
        foreach ($detections as $detection) {
            $action = $detection['error']->fixes[0] ?? $detection['detail'];
            if (!in_array($action, $actions, true)) {
                $actions[] = $action;
            }
        }
        $actions[] = self::REINSPECT_ACTION;

        $steps = [];
        foreach ($actions as $i => $action) {
            $steps[] = ['n' => $i + 1, 'action' => $action];
        }

        return $steps;
    }

    /**
     * The honest compatibility line — always what the profile REALLY says, never an invented
     * deadline, in priority order: an accepted risk with an expiry over one of the package's
     * detected codes names the real date the acceptance lapses; a package with no legacy path has
     * no window at all; without the profile the line says the allowance is unknowable from the
     * report; a wildcard allowance has no deadline (and teaches how to set one); an empty
     * allowlist permits nothing; an explicit list is named verbatim.
     *
     * @param list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}> $detections
     */
    private function compatibility(array $detections, ?HostProfile $hostProfile): string
    {
        if ($hostProfile !== null) {
            foreach ($detections as $detection) {
                foreach ($hostProfile->acceptedRisks as $risk) {
                    if ($risk->code === $detection['code'] && $risk->expires !== null) {
                        return sprintf('acceptedRisk "%s" expires %s — the acceptance lapses on that date', $risk->code, $risk->expires);
                    }
                }
            }
        }

        $hasLegacy = false;
        foreach ($detections as $detection) {
            if ($detection['kind'] === self::KIND_LEGACY_CONTRACT || $detection['kind'] === self::KIND_LEGACY_CAPABILITY) {
                $hasLegacy = true;

                break;
            }
        }
        if (!$hasLegacy) {
            return 'no legacy allowance in play — no compatibility window applies';
        }
        if ($hostProfile === null) {
            return 'allowedLegacyContracts unknown — the host profile was not supplied to the advisor, so no compatibility window can be stated';
        }

        $allowed = $hostProfile->allowedLegacyContracts;
        if (in_array('*', $allowed, true)) {
            return 'allowedLegacyContracts: * — no deadline; declare an explicit list to set one';
        }
        if ($allowed === []) {
            return 'allowedLegacyContracts: [] — no legacy path is permitted; the legacy resolution blocks until migrated or explicitly allowed';
        }

        return sprintf('allowedLegacyContracts: ["%s"] — explicit allowance, no deadline declared', implode('", "', $allowed));
    }

    /**
     * The live bilingual Academy pairs of the package's diagnoses — each error's `learn.academy`
     * `{es, en}` map, live by construction (the catalog verifies its URLs against production),
     * deduplicated in first-appearance order. Nothing is ever hardcoded here.
     *
     * @param list<array{kind: string, id: string, code: string, detail: string, constraint: string|null, error: LearnableArchitectureError|null}> $detections
     *
     * @return list<array{es: string, en: string}>
     */
    private function academy(array $detections): array
    {
        $out = [];
        $seen = [];
        foreach ($detections as $detection) {
            $academy = $detection['error']?->links['academy'] ?? null;
            if (!is_array($academy)) {
                continue;
            }
            $es = is_string($academy['es'] ?? null) ? $academy['es'] : '';
            $en = is_string($academy['en'] ?? null) ? $academy['en'] : '';
            if ($es === '' || $en === '' || isset($seen[$es . "\0" . $en])) {
                continue;
            }
            $seen[$es . "\0" . $en] = true;
            $out[] = ['es' => $es, 'en' => $en];
        }

        return $out;
    }

    /**
     * The concrete migration target the report's `migrationHints[]` declare for an id — the first
     * hint with a non-empty `to` — or null when no hint names one.
     */
    private function hintTarget(ResolutionReport $report, string $id): ?string
    {
        foreach ($report->migrationHints as $hint) {
            if (($hint['id'] ?? null) === $id && is_string($hint['to'] ?? null) && $hint['to'] !== '') {
                return $hint['to'];
            }
        }

        return null;
    }

    /**
     * The canonical package a diagnosis's typed `recommendedActions` name — the first action
     * carrying a `package` parameter (`install-package` / `upgrade-package`) — or null when the
     * catalog knows no canonical provider for the id.
     */
    private function actionPackage(LearnableArchitectureError $error): ?string
    {
        $serialized = $error->toArray();
        $actions = is_array($serialized['recommendedActions'] ?? null) ? $serialized['recommendedActions'] : [];
        foreach ($actions as $action) {
            if (is_array($action) && is_string($action['package'] ?? null) && $action['package'] !== '') {
                return $action['package'];
            }
        }

        return null;
    }

    /**
     * The report error matching a detection — same catalog code, same `context.id` — or null. The
     * engine attaches one learnable error per blocking/legacy entry, so an engine-emitted report
     * always matches; the null branch only guards hand-rehydrated reports.
     */
    private function findError(ResolutionReport $report, string $code, string $id): ?LearnableArchitectureError
    {
        foreach ($report->errors as $error) {
            if ($error->code === $code && ($error->context['id'] ?? null) === $id) {
                return $error;
            }
        }

        return null;
    }

    /**
     * The grouping rule, applied: the package NAME of an attributing label — the `hostProfile:`
     * scheme prefix (a host-origin requirement groups under the host's own name) and the
     * `@version` suffix are stripped; a label with neither (a service FQCN, `surface:<name>`) is
     * used verbatim.
     */
    private function packageName(string $label): string
    {
        if (str_starts_with($label, 'hostProfile:')) {
            $label = substr($label, strlen('hostProfile:'));
        }
        $at = strrpos($label, '@');
        if ($at !== false && $at > 0) {
            $label = substr($label, 0, $at);
        }

        return $label !== '' ? $label : 'host';
    }

    /**
     * Read one string field of a report entry, defensively: a non-scalar reads as `''`.
     *
     * @param array<string, mixed> $entry
     */
    private function str(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
