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

namespace Milpa\Resolver\Manifest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Support\ManifestData;

/**
 * A risk the host has explicitly acknowledged: a warning `code` it accepts, the `reason` that makes
 * the acceptance honest (mandatory — accepting a risk without saying why silences it, and a silenced
 * warning is exactly what `acceptedRisks` exists to prevent), and an optional `expires` date after
 * which the acceptance no longer applies. The engine keeps an accepted warning visible in the report
 * and, when it has not expired, does not let it degrade the status; the caller supplies the clock
 * (`ResolutionInput::$evaluatedAt`) so expiry is evaluated deterministically, never against an
 * ambient wall clock. A date-only `expires` means 00:00 UTC of that day — the acceptance is void the
 * moment the named day begins (failing toward visibility); the acceptance still holds at the exact
 * expiry instant (the comparison is strict). A pure value object: {@see fromArray()} validates,
 * {@see toArray()} serializes.
 */
final readonly class AcceptedRisk
{
    /**
     * @throws InvalidManifestException When `expires` is present but not a valid ISO-8601 date
     *                                  (validated on EVERY construction path — a relative expression
     *                                  like "now" reaching the engine would read the wall clock and
     *                                  break purity).
     */
    public function __construct(
        public string $code,
        public string $reason,
        public ?string $expires = null,
    ) {
        if ($this->expires !== null && !ManifestData::isIsoDate($this->expires)) {
            throw InvalidManifestException::invalidIsoDate('AcceptedRisk', 'expires', $this->expires, $this->code);
        }
    }

    /**
     * Build an accepted risk from a decoded array. `reason` is required — its absence is a hard error
     * that teaches why (acceptance without a reason is silencing); `expires`, when present, must be a
     * parseable ISO-8601 date.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidManifestException When `code` or `reason` is missing/empty, or `expires` is not ISO-8601.
     */
    public static function fromArray(array $data): self
    {
        $code = ManifestData::requireString($data, 'code', 'AcceptedRisk');

        $rawReason = $data['reason'] ?? null;
        $reason = is_scalar($rawReason) ? trim((string) $rawReason) : '';
        if ($reason === '') {
            throw InvalidManifestException::acceptedRiskWithoutReason($code);
        }

        return new self(
            code: $code,
            reason: $reason,
            expires: ManifestData::optionalIsoDate($data, 'expires', 'AcceptedRisk', $code),
        );
    }

    /**
     * Serialize to an array with a fixed, deterministic key order; `expires` is always present (null
     * when the acceptance does not lapse) so consumers never guard for its existence.
     *
     * @return array{code: string, reason: string, expires: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'reason' => $this->reason,
            'expires' => $this->expires,
        ];
    }
}
