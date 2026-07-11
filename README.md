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
the host asked for?* It is **pure**: it receives a fully-materialized `ResolutionInput` and returns
a serializable `ResolutionReport` — it never reads the filesystem, opens a socket, or looks at the
clock. Ingestion (reading `milpa.json` and reflecting `#[PluginMetadata]`) and projection (`coa
inspect`, `coa doctor`, the Admin) are separate layers that sit on top of this engine.

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
| `Manifest\HostProfile` | The architectural shape the app expects (not the same as `composer.json`): required contracts, enabled surfaces, required capabilities, allowed legacy contracts, accepted risks, and free-form metadata. |
| `Input\ResolutionInput` | Everything the engine needs, materialized: a host profile plus the manifests, provisions, requirements, and active surfaces. |
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

> **`allowedLegacyContracts` is advisory in 0.1.** Each `legacy[]` entry carries a `permitted`
> flag computed against the host profile's allowlist, but the resolution status does not (yet)
> degrade on un-allowed legacy — 0.1 detects and explains; enforcement lands with the kernel
> boot-gate in the next slice.

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
