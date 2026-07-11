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

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Support\ManifestData;

/**
 * The result of a resolution: a {@see ResolutionStatus} plus the learnable `errors` attached to the
 * blocking entries, what `resolved`, what is `missing`, the `conflicts` and non-blocking `warnings`,
 * the `legacy` dependencies in use, `migrationHints`, `learnLinks` into the Academy, and free-form
 * `metadata`. Designed to be read by a human on the CLI, by CI, by an agent, and by the Academy
 * alike, so {@see toArray()} serializes with a fixed, deterministic key order — the same report
 * renders byte-for-byte identically every time. `errors[]` leads (right after `status`) so an agent
 * reads the diagnosis first (spec §20).
 */
final readonly class ResolutionReport
{
    /**
     * @param list<array<string, mixed>>       $resolved
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
     * Rehydrate a report from its serialized array form.
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
