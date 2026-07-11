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
| `Report\ResolutionReport` | The verdict: a `ResolutionStatus`, the learnable `errors` attached to every block, and what resolved, what is missing, conflicts, warnings, legacy usage, migration hints, and learn links. Serializes deterministically via `toArray()`. |
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

The `ResolutionReport` is deliberately **not** "a bag of opaque arrays": every list below has a
**frozen entry shape** — an exact, ordered key set, a type per field, and a closed value domain where
one applies. The shape is a public contract, enforced by
[`tests/Report/ReportShapeContractsTest`](tests/Report/ReportShapeContractsTest.php) against
engine-generated examples: a test fails if an entry loses a key, gains an undocumented one, or
changes a field's type or domain. **Optional keys are always present with `null`** (never sometimes
absent), so a consumer can read a key without guarding for its existence.

Type notation: `?string` is a string or `null`; `string[]` is a list of strings; `code (catalog)` is
one of the codes `ErrorCatalog::codes()` knows.

#### `missing[]`

A required contract, capability, surface-requirement, or un-permitted legacy path that does not close
— every entry blocks.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` \| `surface-requirement` \| `legacy-contract` | What kind of requirement failed to close (`legacy-contract` = a legacy path `allowedLegacyContracts` does not permit). |
| `id` | `string` | The contract/capability id that is missing. |
| `constraint` | `?string` | The version constraint asked for (`*` when unversioned); `null` for a `legacy-contract` block, which is a policy denial, not a version mismatch. |
| `level` | `RequirementLevel` | Always `required` here — a missing required item is what blocks. |
| `requiredBy` | `string` | Who asked for it: a host-profile label, a package label, or `surface:<name>`. |
| `surface` | `?string` | The surface name for a `surface-requirement`; `null` otherwise. |
| `code` | `string (catalog)` | The learnable-error code (`MILPA_CONTRACT_MISSING`, `…_VERSION_UNSUPPORTED`, `…_CAPABILITY_MISSING`, `…_SURFACE_REQUIREMENT_UNMET`, `MILPA_LEGACY_NOT_ALLOWED`). |
| `reason` | `string` | A one-line human explanation of why it did not close. |

#### `conflicts[]`

Two or more distinct providers claim the same id where at least one marks it exclusive — blocks.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `capability` | Conflicts are exclusive-capability conflicts. |
| `id` | `string` | The exclusive capability id claimed by more than one provider. |
| `code` | `string (catalog)` | Always `MILPA_CAPABILITY_CONFLICT`. |
| `providedBy` | `string[]` | The conflicting provider labels, sorted — the candidates to choose between. |
| `reason` | `string` | A one-line human explanation of the conflict. |

#### `warnings[]`

A non-blocking caveat: a suggested capability with no provider, a declared deprecation, a surface
caveat, or a `risk-expiry` notice that an accepted risk's expiry could not be checked for want of a
clock.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `suggested-capability` \| `deprecation` \| `surface` \| `risk-expiry` | The kind of warning. |
| `id` | `string` | The capability id, deprecated id, surface name, or accepted-risk code the warning is about. |
| `surface` | `?string` | The surface name for a `surface` warning; `null` otherwise. |
| `code` | `string` | The warning code — a catalog code when the engine raises it, or an author-defined code carried by a surface definition (open domain). |
| `requiredBy` | `string` | Who surfaced the warning: a package label, `surface:<name>`, or the host-profile label. |
| `accepted` | `bool` | Whether the host profile has accepted this risk **and** the acceptance still holds (an accepted, unexpired warning stays visible but does not degrade the status). |
| `acceptedReason` | `?string` | The reason the host gave for accepting the risk; `null` when the warning is not accepted. Carried even when the acceptance has expired, so the report explains itself. |
| `acceptanceExpired` | `bool` | `true` when the host accepted this risk but its `expires` date has passed against the caller's `evaluatedAt` clock — the acceptance is void and the warning degrades again. |
| `message` | `string` | A one-line human explanation. |

#### `legacy[]`

A dependency that closes through a legacy-shaped manifest — named so it stays visible, not silent.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` | Whether the legacy path served a contract or a capability. |
| `id` | `string` | The contract/capability id served by the legacy manifest. |
| `constraint` | `string` | The version constraint the legacy path satisfied (`*` for a capability). |
| `code` | `string (catalog)` | Always `MILPA_LEGACY_CONTRACT_ACTIVE`. |
| `providedBy` | `string` | The legacy manifest's package label (or provider service). |
| `permitted` | `bool` | Whether the host profile's `allowedLegacyContracts` permits this legacy path. When `false` the path also **blocks** — it appears in `missing[]` as a `legacy-contract` / `MILPA_LEGACY_NOT_ALLOWED` entry. |
| `reason` | `string` | A one-line human explanation. |

#### `resolved[]`

A requirement that closed cleanly — the positive record of what the graph wired.

| Field | Type | Semantics |
|-------|------|-----------|
| `kind` | `contract` \| `capability` \| `surface` | What kind of requirement closed. |
| `id` | `string` | The contract/capability id or surface name that closed. |
| `constraint` | `string` | The version constraint satisfied (`*` when unversioned). |
| `level` | `RequirementLevel` | `required` or `suggested` (a satisfied suggestion is reported here too). |
| `requiredBy` | `string` | Who asked for it: a host-profile label, a package label, `contract:<id>@<v>`, or `surface:<name>`. |
| `providedBy` | `string` | The provider that closed it: a service, a package label, or `contract:<id>@<v>`. |
| `via` | `direct` \| `legacy` \| `oneOf` | How it closed: a direct provider, a legacy adapter, or a `oneOf` alternative. |

#### `migrationHints[]`

Emitted alongside a legacy contract — how to migrate off it.

| Field | Type | Semantics |
|-------|------|-----------|
| `id` | `string` | The contract id being migrated. |
| `from` | `string` | The legacy implementation's version. |
| `to` | `?string` | The canonical contract version to migrate to; `null` if no contract manifest declares one. |
| `migrationUrl` | `?string` | The Academy migration URL; `null` if none is declared. |
| `message` | `string` | A one-line migration instruction. |

#### `learnLinks[]`

The Academy links a resolved contract carries along (at least one of `academy`/`migration` is set).

| Field | Type | Semantics |
|-------|------|-----------|
| `id` | `string` | The contract id the links belong to. |
| `academy` | `?string` | The Academy lesson URL, or `null`. |
| `migration` | `?string` | The Academy migration URL, or `null`. |

#### `errors[]`

The learnable error attached to every blocking entry and every catalog-coded warning — the agent
shape of spec §20. Leads the report (right after `status`) so a reader sees the diagnosis first.

| Field | Type | Semantics |
|-------|------|-----------|
| `code` | `string (catalog)` | The learnable-error code. |
| `message` | `string` | The human-readable diagnosis for this occurrence. |
| `why` | `string` | The concept the failure violated — what to learn from it. |
| `context` | `array` | The identifying fields that produced the error (id, constraint, surface, requiredBy, providedBy, hostProfile), free-form by design. |
| `fixes` | `string[]` | Human-readable ways to resolve it. |
| `recommendedActions` | `array[]` | Typed, machine-actionable recommendations derived from the code and context. |
| `learn` | `array` | The bilingual Academy links map (`academy`/`artifact`/`llms`, each `{es, en}`). |

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
