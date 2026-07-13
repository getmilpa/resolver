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

namespace Milpa\Resolver\Report;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Support\ManifestData;

/**
 * The result of a resolution: a {@see ResolutionStatus} plus the learnable `errors` attached to the
 * blocking entries, what `resolved`, the `loadOrder` the graph dictates for booting, what is
 * `missing`, the `conflicts` and non-blocking `warnings`, the `legacy` dependencies in use,
 * `migrationHints`, `learnLinks` into the Academy, and free-form `metadata`. Designed to be read by
 * a human on the CLI, by CI, by an agent, and by the Academy alike, so {@see toArray()} serializes
 * with a fixed, deterministic key order — the same report renders byte-for-byte identically every
 * time. `errors[]` leads (right after `status`) so an agent reads the diagnosis first (spec §20).
 * `loadOrder[]` is the one list whose ORDER is the payload: each entry is a `{name, version}`
 * package identity, sequenced by the engine's topological pass — it is never re-sorted.
 */
final readonly class ResolutionReport
{
    /**
     * @param list<array<string, mixed>>       $resolved
     * @param list<array<string, mixed>>       $loadOrder
     * @param list<array<string, mixed>>       $missing
     * @param list<array<string, mixed>>       $conflicts
     * @param list<array<string, mixed>>       $warnings
     * @param list<array<string, mixed>>       $legacy
     * @param list<array<string, mixed>>       $migrationHints
     * @param list<array<string, mixed>>       $learnLinks
     * @param array<string, mixed>             $metadata
     * @param list<LearnableArchitectureError> $errors
     */
    public function __construct(
        public ResolutionStatus $status,
        public array $resolved = [],
        public array $loadOrder = [],
        public array $missing = [],
        public array $conflicts = [],
        public array $warnings = [],
        public array $legacy = [],
        public array $migrationHints = [],
        public array $learnLinks = [],
        public array $metadata = [],
        public array $errors = [],
    ) {
    }

    /**
     * Rehydrate a report from its serialized array form. Round-trip contract (frozen in
     * ReportShapeContractsTest): `toArray(fromArray(toArray($r)))` is byte-identical to
     * `toArray($r)` for every engine-emitted report. At the malformed boundary it is DEFENSIVE by
     * design — a non-record entry inside a list is dropped and a non-map `metadata` collapses to
     * `[]`, so rehydration never propagates a shape the engine could not have emitted — and each
     * error's `recommendedActions` is derived state, re-computed from its code + context rather
     * than read back.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When `status` is absent or is not a known status.
     */
    public static function fromArray(array $data): self
    {
        $raw = ManifestData::requireString($data, 'status', 'ResolutionReport');
        $status = ResolutionStatus::tryFrom($raw);
        if ($status === null) {
            throw InvalidManifestException::unexpectedValue('ResolutionReport', 'status', $raw);
        }

        $errors = [];
        foreach (ManifestData::recordList($data, 'errors') as $record) {
            $errors[] = LearnableArchitectureError::fromArray($record);
        }

        return new self(
            status: $status,
            resolved: ManifestData::recordList($data, 'resolved'),
            loadOrder: ManifestData::recordList($data, 'loadOrder'),
            missing: ManifestData::recordList($data, 'missing'),
            conflicts: ManifestData::recordList($data, 'conflicts'),
            warnings: ManifestData::recordList($data, 'warnings'),
            legacy: ManifestData::recordList($data, 'legacy'),
            migrationHints: ManifestData::recordList($data, 'migrationHints'),
            learnLinks: ManifestData::recordList($data, 'learnLinks'),
            metadata: ManifestData::optionalArray($data, 'metadata'),
            errors: $errors,
        );
    }

    /**
     * The canonical one-line teaching message of the report's FIRST learnable error, or `null` when
     * `errors[]` is empty: `{code}: {message} — {why} Fix: {fixes[0]} Learn: {learn.academy.en}`.
     *
     * This is THE line a blocked boot logs or throws — the exact composition the runtime kernel and
     * the host's Plugins loader each duplicated by hand until Orden T2; both now delegate here, so the
     * message can never drift between surfaces. Defensive by contract: an error with no fixes or no
     * English academy link still composes, with the segment left empty.
     */
    public function firstLearnableLine(): ?string
    {
        $first = $this->errors[0] ?? null;
        if ($first === null) {
            return null;
        }

        $fix = $first->fixes[0] ?? '';
        $academy = $first->links['academy'] ?? null;
        $learn = is_array($academy) && is_string($academy['en'] ?? null) ? $academy['en'] : '';

        return sprintf('%s: %s — %s Fix: %s Learn: %s', $first->code, $first->message, $first->why, $fix, $learn);
    }

    /**
     * Serialize to an array with a fixed, deterministic key order; `status` becomes its string value
     * and each attached error is expanded to its agent shape (spec §20).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'errors' => array_map(static fn (LearnableArchitectureError $e): array => $e->toArray(), $this->errors),
            'resolved' => $this->resolved,
            'loadOrder' => $this->loadOrder,
            'missing' => $this->missing,
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'legacy' => $this->legacy,
            'migrationHints' => $this->migrationHints,
            'learnLinks' => $this->learnLinks,
            'metadata' => $this->metadata,
        ];
    }
}
