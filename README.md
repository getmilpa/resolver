<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Resolver

> The **architecture resolver** of Milpa: pure logic that takes a `ResolutionInput` — a host
> profile plus the installed manifests, versioned capabilities, and active surfaces — and returns a
> `ResolutionReport` that classifies the whole graph as `valid`, `bootable_with_warnings`,
> `blocked`, or `legacy_compatible`. It resolves the architecture **before** anything boots.

[![CI](https://github.com/getmilpa/resolver/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/resolver/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/resolver.svg)](https://packagist.org/packages/milpa/resolver)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/resolver/)

`milpa/resolver` answers one question before the runtime starts: *does this set of installed
packages, contracts, versioned capabilities, and enabled surfaces actually close against the shape
the host asked for?* Its thesis, in one line: **Composer dice si los paquetes pueden instalarse;
Milpa Resolver dice si la arquitectura puede cooperar.** It is **pure**: it receives a
fully-materialized `ResolutionInput` and returns a serializable `ResolutionReport` — it never reads
the filesystem, opens a socket, or looks at the clock. Ingestion (reading `milpa.json` and
reflecting `#[PluginMetadata]`) and projection (`coa inspect`, `coa doctor`, the Admin) are separate
layers that sit on top of this engine.

It is the versioned, constraint-aware successor to `Milpa\Services\CapabilityGraphChecker` — whose
own DocBlock names itself "the narrower predecessor a resolver should supersede for
versioned/constraint cases." The full contract lives in
[`docs/library/spec-architecture-resolver.md`](https://github.com/getmilpa).

## Install

```bash
composer require milpa/resolver
```

## Quick example

```php
use Milpa\Resolver\Contracts\ArchitectureResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ResolutionStatus;

$input = new ResolutionInput(
    hostProfile: new HostProfile(
        name: 'agent-ready',
        version: '2026.07',
        requiredContracts: ['milpa.command@0.1'],
        enabledSurfaces: ['cli', 'mcp', 'http'],
        requiredCapabilities: ['command.provider', 'event.dispatcher'],
    ),
    versionManifests: [
        VersionManifest::fromArray([
            'package' => 'milpa/command',
            'version' => '0.1.0',
            'contracts' => ['implements' => ['milpa.command@0.1']],
            'capabilities' => ['provides' => ['command.provider'], 'requires' => ['event.dispatcher']],
        ]),
    ],
    contractManifests: [],
    capabilityProvisions: [],
    capabilityRequirements: [],
    activeSurfaces: ['cli', 'mcp', 'http'],
);

$report = $resolver->resolve($input);      // ArchitectureResolver

if ($report->status === ResolutionStatus::Blocked) {
    // $report->missing / ->conflicts carry structured, learnable errors.
}

$report->toArray();                        // deterministic, agent-readable shape
```

`resolve()` walks the graph the way the spec's §11 algorithm does — contracts, then capabilities,
then surfaces — and the final `ResolutionStatus` follows the exact §5 rules: a missing *required*
contract or capability is `blocked`; everything required closing but a *suggested* capability
missing is `bootable_with_warnings`; required closing through a permitted legacy adapter is
`legacy_compatible`; a clean graph is `valid`.

## The value objects

| Value object | Role |
|-------|------------|
| `Manifest\VersionManifest` | An installed piece — its package, version, declared contracts and capabilities, supported surfaces, and deprecations. |
| `Manifest\ContractManifest` | An architectural contract — its id, version, the capabilities it requires/provides/suggests, and its surface requirements. |
| `Manifest\HostProfile` | The architectural shape the app expects (not the same as `composer.json`): required contracts, enabled surfaces, required capabilities, allowed legacy contracts, accepted risks (each an `AcceptedRisk` with a mandatory reason and optional expiry), and free-form metadata. |
| `Manifest\AcceptedRisk` | A risk the host has explicitly acknowledged: the warning `code` it accepts, the mandatory `reason` that makes the acceptance honest (accepting without a reason silences), and an optional ISO-8601 `expires` after which the acceptance lapses. A date-only `expires` means 00:00 UTC of that day — the acceptance is void the moment the named day begins. |
| `Input\ResolutionInput` | Everything the engine needs, materialized: a host profile plus the manifests, provisions, requirements, active surfaces, and an optional `evaluatedAt` clock (ISO-8601) the caller owns. |
| `Report\ResolutionReport` | The verdict: a `ResolutionStatus`, the learnable `errors` attached to every block, the `loadOrder` the graph dictates for booting, and what resolved, what is missing, conflicts, warnings, legacy usage, migration hints, and learn links. Serializes deterministically via `toArray()`. |
| `Report\LearnableArchitectureError` | A teachable diagnosis (spec §12): code, message, `why`, context, human fixes, and Academy links — serializing to the agent shape (spec §20) with typed `recommendedActions` and a bilingual `learn` map. |
| `Report\ErrorCatalog` | The single source of learnable-error content: for every code, the cause, the fixes, and the LIVE Academy links. Enforces "no dead error" (spec §25 anti-pattern 4). |
| `Capability\RequirementLevel` | Whether a capability is `required`, `suggested`, or `optional`. |

> **Anti-decorative gate.** Slice 1 does not resolve adapters or env profiles yet, so
> `VersionManifest.{adapters, profiles}` and `ContractManifest.adapterRequirements` were pruned
> rather than declared and left unread — a field the resolver never consumes is decorative metadata
> (spec §25 anti-pattern 5). A reflection coupling test (`tests/Engine/ManifestFieldCouplingTest`)
> fails if any value-object field loses its consumer, so they return only when the resolver actually
> uses them.

The **versioned capability records** themselves — `provides`, `requires`, `suggests` — are the
canonical value objects from `milpa/core`
(`Milpa\ValueObjects\Capability\{CapabilityProvision, CapabilityRequirement, CapabilitySuggestion}`),
reused here rather than duplicated.

> **`allowedLegacyContracts` is a gate (0.2).** Each `legacy[]` entry still carries a `permitted`
> flag computed against the host profile's allowlist — but the flag is now **enforced**: a legacy path
> the profile does not permit **blocks**. `["*"]` permits every legacy path (the CRM's current
> posture), `[]` permits none, and an explicit list permits exactly the ids it names. Enforcement
> enters through a `missing[]` entry (`kind: legacy-contract`, code `MILPA_LEGACY_NOT_ALLOWED`), so the
> status rule is unchanged — an un-permitted legacy path is a missing requirement like any other —
> while the denial stays **visible** in `legacy[]` as `permitted: false` (both views carry it). A
> permitted legacy path is still `legacy_compatible`, named so it never decays into invisible
> archaeology.

> **Accepting a risk never hides it.** An `acceptedRisks` entry is an object, not a bare code:
> `{ "code": "…", "reason": "why it is acceptable", "expires": "2026-12-31" (optional) }`. The
> `reason` is mandatory — accepting a risk without one silences it, and a silenced warning is exactly
> what `acceptedRisks` exists to prevent (the old bare-string shape is rejected with a message that
> teaches the new one). An accepted warning stays **visible** in the report and, while it holds, does
> not degrade the status. Expiry is evaluated against the caller's clock, not the engine's: pass an
> ISO-8601 `evaluatedAt` in the `ResolutionInput` (the engine never reads the wall clock, so
> resolution stays pure and deterministic). If `evaluatedAt` is later than `expires`, the acceptance is
> **void** and the warning degrades again (`acceptanceExpired: true`); the comparison is strict, so the
> acceptance still holds at the exact expiry instant, and a date-only `expires` means 00:00 UTC of that
> day — void the moment the named day begins. If a risk has an `expires` but
> the input carries **no** `evaluatedAt`, the acceptance still applies, but the resolver refuses to
> trust an expiry it could not check: it emits a visible, unaccepted `MILPA_RISK_EXPIRY_UNEVALUATED`
> warning so the oversight is never silent.

## Report shapes

The `ResolutionReport` is deliberately **not** "a bag of opaque arrays": the **whole serialized
report is a frozen contract** — the top-level key order, the `status` domain, the `metadata` map,
every list's entry shape (an exact, ordered key set, a type per field, and a closed value domain
where one applies), and the nested shapes inside `errors[]`. The contract is enforced by
[`tests/Report/ReportShapeContractsTest`](tests/Report/ReportShapeContractsTest.php) against
engine-generated examples: a test fails if an entry loses a key, gains an undocumented one, changes
a field's type or domain, or reorders the serialized keys. **Optional keys are always present with
`null`** (never sometimes absent), so a consumer can read a key without guarding for its existence —
the one exception is `errors[].context`, whose keys are omitted when absent (see there).

Type notation: `non-empty-string` is a string that is never `""`; `?string` is a string or `null`;
`string[]` is a list of strings; `non-empty-string (catalog)` is one of the codes
`ErrorCatalog::codes()` knows; and a `|`-separated list of literal values is a closed domain —
exactly those values, nothing else. The Type column of every list table below is enforced
token-for-token against the harness schema, not just the field names.

#### `ResolutionReport`

`toArray()` emits exactly these keys, **in this order** — the order is part of the contract, so the
same report renders byte-for-byte identically on the CLI, in CI, for an agent, and in the Academy.
`errors[]` leads right after `status` so a reader sees the diagnosis first (spec §20); reordering the
keys of a serialized report is a contract violation even with every value intact.

| Key | Type | Semantics |
|-----|------|-----------|
| `status` | `string (status domain)` | The verdict — see the `status` domain below. |
| `errors` | `array[]` | The learnable diagnoses (spec §20 agent shape) — see `errors[]`. |
| `resolved` | `array[]` | What closed cleanly — see `resolved[]`. |
| `loadOrder` | `array[]` | The boot order the graph dictates — see `loadOrder[]`. |
| `missing` | `array[]` | What failed to close — see `missing[]`. |
| `conflicts` | `array[]` | Exclusive-capability conflicts and dependency cycles — see `conflicts[]`. |
| `warnings` | `array[]` | Non-blocking caveats — see `warnings[]`. |
| `legacy` | `array[]` | Dependencies closing through legacy shapes — see `legacy[]`. |
| `migrationHints` | `array[]` | How to migrate off each legacy path — see `migrationHints[]`. |
| `learnLinks` | `array[]` | Academy links carried by resolved contracts — see `learnLinks[]`. |
| `metadata` | `array` | The frozen host-identity map — see `metadata`. |

> **Round-trip.** `ResolutionReport::fromArray()` rehydrates a serialized report such that
> `toArray(fromArray(toArray($r)))` is **byte-identical** to `toArray($r)` for every engine-emitted
> report. Two deliberate behaviours at the boundary, frozen by tests: (1) `fromArray()` is
> *defensive* against malformed input — a non-record entry inside a list is dropped and a non-map
> `metadata` collapses to `{}`, because rehydration must never propagate a shape the engine could not
> have emitted; (2) `errors[].recommendedActions` is **derived state** — `fromArray()` discards the
> serialized list and `toArray()` re-derives it deterministically from `code` + `context`, so a
> hand-tampered action list never survives rehydration.

#### `status`

The closed verdict domain (spec §5) — exactly these four values, nothing else, ever:

| Value | Semantics |
|-------|-----------|
| `valid` | The whole graph closes with no warnings. |
| `bootable_with_warnings` | Everything required closes, but an unaccepted warning stands (a suggested capability missing, a surface caveat). |
| `blocked` | A required contract or capability is missing, or a conflict/cycle stands — boot must not proceed. |
| `legacy_compatible` | Required dependencies close, but through a *permitted* legacy adapter — allowed, named, never silent. |

#### `metadata`

The frozen host-identity map — exactly these keys, in this order. Nothing else may ride along: the
report's free-form space is `hostMetadata` (the host profile's own metadata, passed through
verbatim), never `metadata` itself.

| Field | Type | Semantics |
|-------|------|-----------|
| `hostProfile` | `string` | The `name@version` identity of the host profile that was resolved. |
| `hostMetadata` | `array` | The host profile's free-form metadata, passed through verbatim. |

#### `missing[]`

A required contract, capability, surface-requirement, or un-permitted legacy path that does not close
— every entry blocks.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` \| `surface-requirement` \| `legacy-contract` | What kind of requirement failed to close (`legacy-contract` = a legacy path `allowedLegacyContracts` does not permit). |
| `id` | `non-empty-string` | The contract/capability id that is missing. |
| `constraint` | `?string` | The version constraint asked for (`*` when unversioned); `null` for a `legacy-contract` block, which is a policy denial, not a version mismatch. |
| `level` | `required` \| `suggested` \| `optional` | Always `required` here — a missing required item is what blocks. |
| `requiredBy` | `non-empty-string` | Who asked for it: a host-profile label, a package label, or `surface:<name>`. |
| `surface` | `?string` | The surface name for a `surface-requirement`; `null` otherwise. |
| `code` | `non-empty-string (catalog)` | The learnable-error code (`MILPA_CONTRACT_MISSING`, `…_CONTRACT_VERSION_UNSUPPORTED`, `…_CAPABILITY_MISSING`, `…_CAPABILITY_VERSION_UNSUPPORTED` — a capability provided only outside the consumer's range carries its own code, not the contract one — `…_SURFACE_REQUIREMENT_UNMET`, `MILPA_LEGACY_NOT_ALLOWED`). |
| `reason` | `non-empty-string` | A one-line human explanation of why it did not close. |

#### `conflicts[]`

Two or more distinct providers claim the same id where at least one marks it exclusive, or a set of
packages requires each other in a dependency cycle — either way, blocks.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `capability` \| `dependency-cycle` | An exclusive-capability conflict, or a dependency cycle with no possible boot order. |
| `id` | `non-empty-string` | The exclusive capability id claimed by more than one provider; for a cycle, the member package names (lexicographic) joined with ` <-> `. |
| `code` | `non-empty-string (catalog)` | `MILPA_CAPABILITY_CONFLICT` for a capability conflict; `MILPA_DEPENDENCY_CYCLE` for a cycle. |
| `providedBy` | `string[]` | The conflicting provider labels, sorted — the candidates to choose between; for a cycle, each member's `name@version` identity (lexicographic). |
| `reason` | `non-empty-string` | A one-line human explanation of the conflict. |

#### `warnings[]`

A non-blocking caveat: a suggested capability with no provider, a declared deprecation, a surface
caveat, or a `risk-expiry` notice that an accepted risk's expiry could not be checked for want of a
clock.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `suggested-capability` \| `deprecation` \| `surface` \| `risk-expiry` | The kind of warning. |
| `id` | `non-empty-string` | The capability id, deprecated id, surface name, or accepted-risk code the warning is about. |
| `surface` | `?string` | The surface name for a `surface` warning; `null` otherwise. |
| `code` | `non-empty-string` | The warning code — a catalog code when the engine raises it, or an author-defined code carried by a surface definition (open domain). |
| `requiredBy` | `non-empty-string` | Who surfaced the warning: a package label, `surface:<name>`, or the host-profile label. |
| `fallback` | `?string` | For a `suggested-capability` warning, the degradation path the suggestion record declares (`suggests[].fallback`, e.g. `"noop"`) — named in the `message` too (`degrades to "noop"`); `null` when the suggestion declares none (legacy bare-FQCN suggestions never do) and for every other kind. |
| `accepted` | `bool` | Whether the host profile has accepted this risk **and** the acceptance still holds (an accepted, unexpired warning stays visible but does not degrade the status). |
| `acceptedReason` | `?string` | The reason the host gave for accepting the risk; `null` when the warning is not accepted. Carried even when the acceptance has expired, so the report explains itself. |
| `acceptanceExpired` | `bool` | `true` when the host accepted this risk but its `expires` date has passed against the caller's `evaluatedAt` clock — the acceptance is void and the warning degrades again. |
| `message` | `string` | A one-line human explanation. |

#### `legacy[]`

A dependency that closes through a legacy-shaped manifest — named so it stays visible, not silent.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` | Whether the legacy path served a contract or a capability. |
| `id` | `non-empty-string` | The contract/capability id served by the legacy manifest. |
| `constraint` | `non-empty-string` | The version constraint the legacy path satisfied (`*` for a capability). |
| `code` | `non-empty-string (catalog)` | Always `MILPA_LEGACY_CONTRACT_ACTIVE`. |
| `providedBy` | `non-empty-string` | The legacy manifest's package label (or provider service). |
| `permitted` | `bool` | Whether the host profile's `allowedLegacyContracts` permits this legacy path. When `false` the path also **blocks** — it appears in `missing[]` as a `legacy-contract` / `MILPA_LEGACY_NOT_ALLOWED` entry. |
| `reason` | `non-empty-string` | A one-line human explanation. |

#### `resolved[]`

A requirement that closed cleanly — the positive record of what the graph wired.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` \| `surface` | What kind of requirement closed. |
| `id` | `non-empty-string` | The contract/capability id or surface name that closed. |
| `constraint` | `non-empty-string` | The version constraint satisfied (`*` when unversioned). |
| `level` | `required` \| `suggested` \| `optional` | `required` or `suggested` (a satisfied suggestion is reported here too). |
| `requiredBy` | `non-empty-string` | Who asked for it: a host-profile label, a package label, `contract:<id>@<v>`, or `surface:<name>`. |
| `providedBy` | `non-empty-string` | The provider that closed it: a service, a package label, or `contract:<id>@<v>`. |
| `via` | `direct` \| `legacy` \| `oneOf` | How it closed: a direct provider, a legacy adapter, or a `oneOf` alternative. |

> **Provider selection is deterministic** — spec §3.1's "priority resolves deterministic ordering
> for multiple providers", verbatim. When several providers offer the same non-exclusive id, the
> winner of `providedBy` is chosen by **`priority` descending** (a provision's declared `priority`,
> absent = 0), then **non-legacy before legacy**, then the **lexicographically first label**. The
> `loadOrder[]` edge follows the **same winner**: when priority selects a provider, its dependents
> boot after *that* provider — you boot after whoever satisfies you. Priority never rescues an
> **exclusive** conflict: two providers claiming the same exclusive id still block with
> `MILPA_CAPABILITY_CONFLICT`, whatever their priorities.

#### `loadOrder[]`

The boot order the graph dictates: the topological sort of the version manifests (Kahn's algorithm,
absorbed from the legacy `Milpa\Plugin\ContractResolver`) over the exact-string capability/contract
ids they provide and require. **The order is semantic** — this is the ONE list `toArray()` does not
sort lexicographically, because its sequence IS the payload: dependencies come before their
dependents, and packages with no edges between them keep the exact order the host configured. It is
still fully deterministic (a pure function of the input order). The members of a dependency cycle
are excluded — they appear in `conflicts[]` as a `dependency-cycle` entry instead — while every
non-cyclic package keeps its place. When several packages provide the same id, the edge source is
the **highest-`priority` provider** — the same winner provider selection picks for `resolved[]` —
and on a priority tie (the no-priority case included) the **last provider in input order** wins,
byte-identical to the legacy `ContractResolver` semantics.

| Field | Type | Semantics |
|-------|------|-----------|
| `name` | `non-empty-string` | The package name, in boot position — the runtime maps it to its plugin record. |
| `version` | `string` | The package version, completing the `name@version` identity `coa inspect` and agents consume. |

#### `migrationHints[]`

Emitted alongside a legacy path — a contract or a capability — telling you how to migrate off it.

| Field | Type | Semantics |
|-------|------|-----------|
| `id` | `non-empty-string` | The contract or capability id being migrated. |
| `from` | `non-empty-string` | The legacy implementation's (or provider's) version. |
| `to` | `?string` | The migration target: the canonical contract version for a legacy contract, or `capabilities.*` for a legacy capability; `null` when a legacy contract has no contract manifest declaring one. |
| `migrationUrl` | `?string` | The Academy migration URL; `null` if none is declared (always `null` for a capability hint). |
| `message` | `non-empty-string` | A one-line migration instruction. |

#### `learnLinks[]`

The Academy links a resolved contract carries along (at least one of `academy`/`migration` is set).

| Field | Type | Semantics |
|-------|------|-----------|
| `id` | `non-empty-string` | The contract id the links belong to. |
| `academy` | `?string` | The Academy lesson URL, or `null`. |
| `migration` | `?string` | The Academy migration URL, or `null`. |

#### `errors[]`

The learnable error attached to every blocking entry, every catalog-coded warning, and every
permitted legacy path (its `MILPA_LEGACY_CONTRACT_ACTIVE` lesson — allowed, but never silent) — the
agent shape of spec §20. Leads the report (right after `status`) so a reader sees the diagnosis first.

**Errors attribute their requirer**: when a *package* (`vendor/package@x.y.z`) or a *contract*
(`contract:<id>@<v>`) — not the host profile — asked for a missing capability, the `message` names it
(`acme/crm@1.2.0 requires the capability "crm.mailer", …`), so the reader learns *who* opened the
graph, not just what is absent. The attribution covers **both version codes** too, as a consistent
pair (`acme/crm@1.2.0 requires the contract "milpa.persistence"; it is implemented, but …`).
Host-origin entries keep the `The host profile …` phrasing.
**A `oneOf` requirement that exhausts every candidate teaches its whole search space**: the error's
`context.oneOf` lists the alternatives tried (the frozen `missing[]` entry never carries them) and
the message enumerates every candidate — `… but none of ["log.sink", "log.file", "log.syslog"]
provides it.` When the only existing candidates sit **out of range**, the version-miss message names
them instead of implying the primary id is provided — `The capability "log.sink" is provided only
through ["log.syslog"], but no provider's contractVersion satisfies the constraint "^2.0".`
`MILPA_MANIFEST_DRIFT` is **caller-emitted** rather than engine-emitted: it is built by
`DriftDetector::toLearnableErrors()` when a package's `milpa.json` no longer matches its
`#[PluginMetadata]`. Consumers render any report's diagnosis with
`ResolutionReport::firstLearnableLine()` — the canonical
`{code}: {message} — {why} Fix: {fixes[0]} Learn: {learn.academy.en}` line, `null` when `errors[]`
is empty.

| Field | Type | Semantics |
|-------|------|-----------|
| `code` | `non-empty-string (catalog)` | The learnable-error code. |
| `message` | `non-empty-string` | The human-readable diagnosis for this occurrence. |
| `why` | `non-empty-string` | The concept the failure violated — what to learn from it. |
| `context` | `array` | The identifying fields that produced the error — engine-emitted contexts follow the endorsed key order frozen in `errors[].context` below. |
| `fixes` | `string[]` | Human-readable ways to resolve it. |
| `recommendedActions` | `array[]` | Typed, machine-actionable recommendations derived from the code and context — the entry shape and type domain are frozen in `errors[].recommendedActions[]` below. |
| `learn` | `array` | The bilingual Academy links map, frozen in `errors[].learn` below. |

#### `errors[].context`

Every **engine-emitted** context carries its keys in one endorsed order: the requirement identity
first (`id`, `constraint`, and the `oneOf` alternatives that widen it), then where (`surface`), then
the two parties (`requiredBy`, `providedBy`), then the degradation path (`fallback`), and the host
label last. Keys are **optional** — a field with nothing to say is *omitted*, never `null` — but when
present they appear in exactly this relative order. `id` and `hostProfile` are always present.

**Exemption**: `MILPA_MANIFEST_DRIFT` is caller-emitted (`DriftDetector::toLearnableErrors()`), never
built by the engine's context pass, and carries its own frozen shape — exactly `package` (the drifted
package label) and `fields` (the drifted field records) — outside this order on purpose: a drift
diagnosis identifies a manifest, not a graph requirement.

| Key | Type | Semantics |
|-----|------|-----------|
| `id` | `string` | The contract/capability/surface/risk id the error is about (always present). |
| `constraint` | `string` | The version constraint in play, when the entry carried one. |
| `oneOf` | `string[]` | The exhausted alternatives of a missed `oneOf` capability requirement. |
| `surface` | `string` | The surface name, for surface-scoped errors. |
| `requiredBy` | `string` | Who asked: a host-profile label, a package label, `contract:<id>@<v>`, `surface:<name>`, or `input`. |
| `providedBy` | `string` \| `string[]` | The entry's provider(s) — a legacy path's provider label, a conflict's candidate list — or, on a `oneOf` version miss, the candidate ids that exist only out of range. |
| `fallback` | `string` | The degradation path a missed suggestion declares. |
| `hostProfile` | `string` | The host profile's `name@version` label (always present, always last). |

#### `errors[].recommendedActions[]`

Every entry is a typed, machine-actionable recommendation: the `type` key leads (always first), its
value belongs to the **closed domain below**, and every other key is a code-specific string parameter
(`candidates` is the one list of strings). The list is **derived state**, re-computed from `code` +
`context` on every serialization — see the round-trip note above.

| Action type | Semantics |
|-------------|-----------|
| `install-package` | Install the named canonical provider package. |
| `enable-plugin` | Enable a plugin that supplies the named contract/capability/adapter. |
| `disable-feature` | Disable the feature that needs the missing capability. |
| `accept-risk` | Record the named warning code as an accepted risk in the host profile. |
| `upgrade-package` | Upgrade the named package until it satisfies the constraint. |
| `adjust-constraint` | Relax the requirer's constraint to admit what is installed. |
| `choose-provider` | Pick exactly one of the `candidates` claiming an exclusive capability. |
| `disable-surface` | Disable the named surface until its requirements have providers. |
| `enable-surface` | Enable the named surface the contract expects. |
| `migrate-contract` | Migrate the named contract off its legacy/deprecated shape. |
| `allow-legacy-contract` | Add the named contract to `allowedLegacyContracts` explicitly. |
| `update-host-profile` | Regenerate the stale host profile from the installed package set. |
| `review-blocking-errors` | Walk the report's blocking entries — the graph must close first. |
| `review-warnings` | Review the report's warnings and provide or accept each one. |
| `set-evaluated-at` | Pass an `evaluatedAt` clock so accepted-risk expiries can be checked. |
| `remove-risk-expiry` | Drop the `expires` field from the named accepted risk. |

#### `errors[].learn`

The bilingual Academy links map. `academy` and `llms` are **required** on every catalog-built error —
the "no dead error" rule: every code teaches a human *and* is consumable by an agent — while
`artifact` is optional (not every lesson has one). Every present value is exactly the bilingual pair
`{es, en}` of live, non-empty URLs — never invented, verified against production.

| Key | Type | Semantics |
|-----|------|-----------|
| `academy` | `{es, en}` | The Academy lesson URLs (required). |
| `artifact` | `{es, en}` | The Academy artifact URLs (optional). |
| `llms` | `{es, en}` | The machine-readable `llms.txt` resources (required). |

## Migration plan shape

`Advisor\MigrationAdvisor` turns a report into an actionable `Advisor\MigrationPlan` — the
conceptual pipeline of spec §8 (`… → ArchitectureResolver → ResolutionReport → MigrationAdvisor →
LearnableError / Academy link`) and the separation of duties of spec §6: the resolver **detects and
explains**, the Advisor **proposes**, a command executes only if the human accepts, and the Academy
teaches the concept. The advisor is pure — no filesystem, no clock, no network, and it never writes;
the same inputs yield the same plan byte for byte.

```php
use Milpa\Resolver\Advisor\MigrationAdvisor;

$plan = (new MigrationAdvisor())->advise($report, $driftErrors, $hostProfile);
$plan->toArray();   // the frozen plan shape below
```

Two optional, **caller-supplied** inputs complete the report (the same pattern as the engine's
caller-owned `evaluatedAt` clock): `$driftErrors` is the exact list of `LearnableArchitectureError`
objects `DriftDetector::toLearnableErrors()` returns (the host's inspect surface already holds them
verbatim); `$hostProfile` is the profile the caller resolved against — `allowedLegacyContracts` and
`acceptedRisks` live **only** there (the report's frozen `metadata` carries just the host label and
the verbatim `hostMetadata`), so the `compatibility` line can only be honest when the caller passes
the profile it already built. Without it, the plan says so; **no deadline is ever invented**.

Detections group per package **name**: the attributing label (`legacy[].providedBy`,
`missing[].requiredBy`, a drift error's `context.package`; conflicts attach to the host via
`metadata.hostProfile`) with its `hostProfile:` scheme prefix and `@version` suffix stripped — so
`legacy/command-host@0.0.1` and a drift on `legacy/command-host` land in the **same** package entry.
A package with nothing actionable never appears; a report with nothing actionable yields the
**visible** empty plan (`packages: []`, summary `0/0`), never a null. The whole serialized plan is a
frozen contract, enforced by
[`tests/Advisor/MigrationPlanShapeContractsTest`](tests/Advisor/MigrationPlanShapeContractsTest.php)
against engine-generated examples (real inputs → `resolve()` → `advise()`), with the same type
notation as the report tables above plus `int`; `non-empty-string (optional)` marks the plan's one
optional key — **omitted** when absent, never `null`, mirroring the `errors[].context` precedent.

#### `MigrationPlan`

`toArray()` emits exactly these keys, **in this order**. `summary` is derived from the packages on
every serialization, so it can never disagree with them.

| Key | Type | Semantics |
|-----|------|-----------|
| `status` | `string (status domain)` | The originating report's status, verbatim — the plan never invents a fifth verdict. |
| `packages` | `array[]` | One entry per package with actionable work, sorted by package name — see `packages[]`. |
| `summary` | `array` | The derived census — see `summary`. |

#### `packages[]`

One package's migration work: what was detected, the recommended targets, the numbered steps, the
honest compatibility line, and the live Academy links.

| Field | Type | Semantics |
|-------|------|-----------|
| `package` | `non-empty-string` | The package name (grouping rule above): attribution label minus `hostProfile:` prefix and `@version` suffix. |
| `detected` | `array[]` | What the report detected on this package — see `packages[].detected[]`. |
| `recommended` | `array[]` | The concrete migration targets — see `packages[].recommended[]`. |
| `steps` | `array[]` | The numbered actions, 1..n; the LAST is always the re-inspect — see `packages[].steps[]`. |
| `compatibility` | `non-empty-string` | The honest window: an accepted risk's real expiry date, the profile's real `allowedLegacyContracts` posture (`*` → "no deadline; declare an explicit list to set one"; an explicit list → named verbatim), "unknown" when the profile was not supplied, or "no legacy allowance in play". Never an invented deadline. |
| `academy` | `array[]` | The live bilingual Academy pairs the package's diagnoses carry — see `packages[].academy[]`. |

#### `packages[].detected[]`

One detection: a legacy path, a caller-diagnosed manifest drift, a missing requirement, or a
provider conflict.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `legacy-contract` \| `legacy-capability` \| `manifest-drift` \| `missing` \| `conflict` | Which raw material of the report produced the detection. |
| `id` | `non-empty-string` | The contract/capability id (for drift, the drifted package's name). |
| `code` | `non-empty-string (catalog)` | The learnable-error code of the underlying diagnosis. |
| `detail` | `non-empty-string` | The report's one-line reason (or the drift error's message), verbatim. |

#### `packages[].recommended[]`

A concrete migration target: a legacy path's migration-hint `to` (omitted entirely when the hint
declares none — nothing concrete is recommended over an invented target), or a missing
requirement's canonical provider package.

| Field | Type | Semantics |
|-------|------|-----------|
| `id` | `non-empty-string` | The contract/capability id being migrated or satisfied. |
| `to` | `non-empty-string` | The target: the canonical contract version, `capabilities.*`, or the canonical package to install. |
| `constraint` | `non-empty-string (optional)` | The requirement's version constraint, when the detection carried one — the plan's ONE optional key, omitted (never null) otherwise. |

#### `packages[].steps[]`

The plan of action, numbered from 1: one step per detection — its diagnosis's `fixes[0]`, the human
fix the catalog already wrote — deduplicated, then the verification step, ALWAYS last: `Run php coa
coa:inspect architecture again.` A plan never trusts itself.

| Field | Type | Semantics |
|-------|------|-----------|
| `n` | `int` | The step number, 1..n with no gaps. |
| `action` | `non-empty-string` | The actionable instruction, straight from the diagnosis. |

#### `packages[].academy[]`

The bilingual Academy lessons behind the package's diagnoses — each error's `learn.academy` pair,
live by construction, deduplicated in first-appearance order.

| Field | Type | Semantics |
|-------|------|-----------|
| `es` | `non-empty-string` | The Spanish Academy lesson URL. |
| `en` | `non-empty-string` | The English Academy lesson URL. |

#### `summary`

The derived census — recomputed from the packages on every serialization.

| Field | Type | Semantics |
|-------|------|-----------|
| `packages` | `int` | How many packages carry actionable work. |
| `actions` | `int` | The total step count across all packages. |

## Requirements

- PHP **≥ 8.3**
- [`composer/semver`](https://github.com/composer/semver) `^3` — version and constraint matching
- [`milpa/core`](https://github.com/getmilpa/core) — the canonical capability records and the
  `MilpaExceptionInterface` marker

## Documentation

**Full API reference: [getmilpa.github.io/resolver](https://getmilpa.github.io/resolver/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=resolver)**.
