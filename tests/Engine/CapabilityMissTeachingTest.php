<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use PHPUnit\Framework\TestCase;

/**
 * Capability misses teach precisely (Contrato slice T3), engine-emitted end to end:
 *
 *  1. a CAPABILITY whose providers exist but none satisfies the constraint carries its OWN code
 *     (`MILPA_CAPABILITY_VERSION_UNSUPPORTED`) instead of recycling the contract version code;
 *  2. a requirement with `oneOf` where no candidate resolves keeps the frozen missing[] shape but
 *     its learnable error carries `context.oneOf` and the message ENUMERATES the alternatives;
 *  3. a suggested capability that is absent AND declares a `fallback` makes the degradation path
 *     visible — `warnings[].fallback` plus the "degrades to" phrase in both messages.
 */
final class CapabilityMissTeachingTest extends TestCase
{
    /**
     * Path 1 — the constraint miss of a CAPABILITY earns its own catalog code. The provider for the
     * id EXISTS (at 1.0.0) but the consumer asked ^2.0: that is not a missing capability and not a
     * contract problem — it is a capability at the wrong version, and the full learnable error
     * (own why, upgrade/relax fixes naming both sides, live contratos-grafo links) is frozen here.
     */
    public function testCapabilityConstraintMissEmitsItsOwnVersionCode(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cache.store@^2.0']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('cache.store', 'Cache', '1.0.0', service: 'App\\ArrayCache')],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'cache.store');
        self::assertNotNull($missing);
        self::assertSame('capability', $missing['kind']);
        self::assertSame('MILPA_CAPABILITY_VERSION_UNSUPPORTED', $missing['code']);
        self::assertSame('^2.0', $missing['constraint']);
        self::assertSame('Providers for "cache.store" exist, but none satisfies the constraint "^2.0".', $missing['reason']);

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame(
            [
                'code' => 'MILPA_CAPABILITY_VERSION_UNSUPPORTED',
                'message' => 'The capability "cache.store" is provided, but no provider\'s contractVersion satisfies the constraint "^2.0".',
                'why' => 'A provider for the capability exists, but its contractVersion falls outside the range the consumer asked for. A version is a contract, not a label: the capability is present at the wrong version, so the requirement stays open until the provider upgrades or the constraint admits what is installed.',
                'context' => [
                    'id' => 'cache.store',
                    'constraint' => '^2.0',
                    'requiredBy' => 'hostProfile:agent-ready@2026.07',
                    'hostProfile' => 'agent-ready@2026.07',
                ],
                'fixes' => [
                    'Upgrade a provider of "cache.store" to a contractVersion that satisfies "^2.0".',
                    'Relax the "cache.store" constraint "^2.0" on the requirer if an installed provider version is acceptable.',
                ],
                'recommendedActions' => [
                    ['type' => 'adjust-constraint', 'capability' => 'cache.store', 'constraint' => '^2.0'],
                ],
                'learn' => [
                    'academy' => [
                        'es' => 'https://academy.milpa.lat/learn/fundamentos/contratos-grafo/',
                        'en' => 'https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/',
                    ],
                    'artifact' => [
                        'es' => 'https://academy.milpa.lat/artifacts/#siembra',
                        'en' => 'https://academy.milpa.lat/en/artifacts/#siembra',
                    ],
                    'llms' => [
                        'es' => 'https://academy.milpa.lat/llms.txt',
                        'en' => 'https://academy.milpa.lat/en/llms.txt',
                    ],
                ],
            ],
            $errors[0],
        );
    }

    /**
     * Path 1, known-package variant: when the missed capability id maps to a canonical package, the
     * first fix and the typed actions name it — upgrade THAT package, not "a provider".
     */
    public function testCapabilityVersionUnsupportedNamesTheKnownProviderPackage(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['tool.registry@^9.0']),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('tool.registry', 'Tool\\Registry', '0.1.0')],
            capabilityRequirements: [],
        );

        $errors = $this->resolve($input)->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame('MILPA_CAPABILITY_VERSION_UNSUPPORTED', $errors[0]['code']);
        self::assertSame(
            'Upgrade milpa/tool-runtime so its "tool.registry" provision satisfies "^9.0".',
            $errors[0]['fixes'][0],
        );
        self::assertContains(
            ['type' => 'upgrade-package', 'package' => 'milpa/tool-runtime', 'constraint' => '^9.0'],
            $errors[0]['recommendedActions'],
        );
    }

    /**
     * Path 2 — oneOf exhausted: neither the primary id nor any listed alternative has a provider.
     * The missing[] entry keeps its FROZEN key set (oneOf never leaks into the report entry); the
     * attached error carries `context.oneOf` (in its fixed position, right after `constraint`) and
     * the message enumerates every candidate tried, so the reader sees the whole search space.
     */
    public function testOneOfExhaustedTeachesTheAlternatives(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [
                new CapabilityRequirement('log.sink', 'Log', '*', ['log.file', 'log.syslog']),
            ],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'log.sink');
        self::assertNotNull($missing);
        self::assertSame('MILPA_CAPABILITY_MISSING', $missing['code']);
        // The frozen missing[] shape is untouched — the alternatives ride on the ERROR, not the entry.
        self::assertSame(
            ['kind', 'id', 'constraint', 'level', 'requiredBy', 'surface', 'code', 'reason'],
            array_keys($missing),
        );

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame('MILPA_CAPABILITY_MISSING', $errors[0]['code']);
        self::assertSame(['log.file', 'log.syslog'], $errors[0]['context']['oneOf']);
        // The fixed context key order: oneOf sits right after the requirement identity it belongs to.
        self::assertSame(
            ['id', 'constraint', 'oneOf', 'requiredBy', 'hostProfile'],
            array_keys($errors[0]['context']),
        );
        self::assertSame(
            'The host profile agent-ready@2026.07 requires the capability "log.sink", but none of ["log.sink", "log.file", "log.syslog"] provides it.',
            $errors[0]['message'],
        );
    }

    /**
     * Path 2 x path 1 — a oneOf requirement whose only candidate exists OUT OF RANGE: the miss is a
     * version miss (own code), and the error still carries the alternatives tried in context.oneOf.
     * Contrato T4 (inherited from the T3 review): the context also names WHICH candidate exists out
     * of range (`providedBy`, in its endorsed position right after requiredBy), and the message says
     * `is provided only through [...]` — the bare "is provided" would mislead here, because the
     * primary id log.sink has NO provider at all; only the alternative log.syslog does, out of range.
     */
    public function testOneOfConstraintMissCarriesAlternativesWithTheVersionCode(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('log.syslog', 'Log', '1.0.0', service: 'App\\SyslogLogger')],
            capabilityRequirements: [
                new CapabilityRequirement('log.sink', 'Log', '^2.0', ['log.syslog']),
            ],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'log.sink');
        self::assertNotNull($missing);
        self::assertSame('MILPA_CAPABILITY_VERSION_UNSUPPORTED', $missing['code']);

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame('MILPA_CAPABILITY_VERSION_UNSUPPORTED', $errors[0]['code']);
        self::assertSame(['log.syslog'], $errors[0]['context']['oneOf']);
        self::assertSame(['log.syslog'], $errors[0]['context']['providedBy']);
        self::assertSame(
            ['id', 'constraint', 'oneOf', 'requiredBy', 'providedBy', 'hostProfile'],
            array_keys($errors[0]['context']),
        );
        self::assertSame(
            'The capability "log.sink" is provided only through ["log.syslog"], but no provider\'s contractVersion satisfies the constraint "^2.0".',
            $errors[0]['message'],
        );
    }

    /**
     * Path 3 — fallback degradation visible: an absent suggested capability whose suggestion record
     * declares `fallback` carries it on the warnings[] entry, and BOTH messages (the entry's and the
     * learnable error's) name the degradation path instead of the vague "fallback path applies".
     */
    public function testSuggestedFallbackDegradationIsVisible(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [
                new VersionManifest(
                    package: 'milpa/audit',
                    version: '0.1.0',
                    contracts: ['implements' => []],
                    capabilities: [
                        'provides' => [],
                        'suggests' => [['id' => 'audit.sink', 'interface' => 'Audit\\Sink', 'constraint' => '*', 'fallback' => 'noop']],
                    ],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        self::assertSame(ResolutionStatus::BootableWithWarnings, $report->status);

        $warning = $this->entryBy($report->warnings, 'id', 'audit.sink');
        self::assertNotNull($warning);
        self::assertSame('suggested-capability', $warning['kind']);
        self::assertSame('noop', $warning['fallback']);
        self::assertSame(
            'Suggested capability "audit.sink" has no provider; its fallback path applies: degrades to "noop".',
            $warning['message'],
        );

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertSame('MILPA_SUGGESTED_CAPABILITY_MISSING', $errors[0]['code']);
        self::assertSame('noop', $errors[0]['context']['fallback']);
        self::assertSame(
            'The suggested capability "audit.sink" has no provider; its fallback path applies: degrades to "noop".',
            $errors[0]['message'],
        );
    }

    /**
     * Path 3, null side — the "Optional keys are always present with null" rule: a legacy bare-FQCN
     * suggestion declares no fallback, so the entry carries `fallback: null` and both messages stay
     * byte-identical to their pre-fallback phrasing. Nothing degrades silently, nothing is invented.
     */
    public function testSuggestedWithoutFallbackKeepsNullFallbackAndTheBaseMessage(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [
                new VersionManifest(
                    package: 'milpa/audit',
                    version: '0.1.0',
                    contracts: ['implements' => []],
                    capabilities: ['provides' => [], 'suggests' => ['audit.sink']],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = $this->resolve($input);

        $warning = $this->entryBy($report->warnings, 'id', 'audit.sink');
        self::assertNotNull($warning);
        self::assertArrayHasKey('fallback', $warning);
        self::assertNull($warning['fallback']);
        self::assertSame(
            'Suggested capability "audit.sink" has no provider; its fallback path applies.',
            $warning['message'],
        );

        $errors = $report->toArray()['errors'];
        self::assertCount(1, $errors);
        self::assertArrayNotHasKey('fallback', $errors[0]['context']);
        self::assertSame(
            'The suggested capability "audit.sink" has no provider; its fallback path applies.',
            $errors[0]['message'],
        );
    }

    /**
     * Path 3, trim pin — the engine TRIMS a suggestion record's declared fallback, deliberately
     * stricter than CapabilitySuggestion::fromArray() (which keeps the string verbatim): a
     * whitespace-only fallback is no fallback at all, so it normalizes to null and the base message
     * applies — never a blank `degrades to ""` path. This is the behaviour the entryFallback()
     * docblock documents; pinning it here keeps that docblock honest.
     */
    public function testAWhitespaceOnlyFallbackIsTrimmedToNone(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07'),
            versionManifests: [
                new VersionManifest(
                    package: 'milpa/audit',
                    version: '0.1.0',
                    contracts: ['implements' => []],
                    capabilities: [
                        'provides' => [],
                        'suggests' => [['id' => 'audit.sink', 'interface' => 'Audit\\Sink', 'constraint' => '*', 'fallback' => '   ']],
                    ],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $warning = $this->entryBy($this->resolve($input)->warnings, 'id', 'audit.sink');
        self::assertNotNull($warning);
        self::assertNull($warning['fallback']);
        self::assertSame(
            'Suggested capability "audit.sink" has no provider; its fallback path applies.',
            $warning['message'],
        );
    }

    /**
     * Determinism guard over the three new paths together: the same input twice yields byte-identical
     * reports — the new code, the oneOf context, and the fallback field are pure functions of input.
     */
    public function testTheThreeTeachingPathsAreByteDeterministic(): void
    {
        $input = new ResolutionInput(
            hostProfile: new HostProfile('agent-ready', '2026.07', requiredCapabilities: ['cache.store@^2.0']),
            versionManifests: [
                new VersionManifest(
                    package: 'milpa/audit',
                    version: '0.1.0',
                    contracts: ['implements' => []],
                    capabilities: [
                        'provides' => [],
                        'suggests' => [['id' => 'audit.sink', 'interface' => 'Audit\\Sink', 'constraint' => '*', 'fallback' => 'noop']],
                    ],
                ),
            ],
            contractManifests: [],
            capabilityProvisions: [new CapabilityProvision('cache.store', 'Cache', '1.0.0', service: 'App\\ArrayCache')],
            capabilityRequirements: [new CapabilityRequirement('log.sink', 'Log', '*', ['log.file', 'log.syslog'])],
        );

        self::assertSame(
            json_encode($this->resolve($input)->toArray()),
            json_encode($this->resolve($input)->toArray()),
        );
    }

    private function resolve(ResolutionInput $input): ResolutionReport
    {
        return (new GraphResolver())->resolve($input);
    }

    /**
     * Find the first entry in a report list whose $key equals $value.
     *
     * @param array<string, mixed> $list
     *
     * @return array<string, mixed>|null
     */
    private function entryBy(array $list, string $key, string $value): ?array
    {
        foreach ($list as $entry) {
            if (is_array($entry) && ($entry[$key] ?? null) === $value) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        return null;
    }
}
