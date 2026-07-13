<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Report;

use Milpa\Resolver\Report\ErrorCatalog;
use Milpa\Resolver\Report\LearnableArchitectureError;
use PHPUnit\Framework\TestCase;

/**
 * The catalog is the single source of learnable-error content. This suite enforces spec §25
 * anti-pattern 4 ("error muerto"): no code may exist without a cause (`why`), a fix, and a real
 * Academy learn link. The whole catalog is walked; any code missing why/fixes/links.academy or
 * links.llms fails the build.
 */
final class ErrorCatalogTest extends TestCase
{
    public function testCatalogCoversTheEighteenCodes(): void
    {
        $codes = ErrorCatalog::codes();

        // The 11 initial codes of spec §13, the two ambiguity-splitting codes (T2 carry), the
        // acceptedRisks-expiry-without-a-clock code (invariantes slice T2), the
        // allowedLegacyContracts enforcement code (invariantes slice T3), the
        // dependency-cycle code (Orden slice T1 — the boot order absorbed from Kahn), the
        // manifest-drift code (Orden slice T2 — caller-emitted by DriftDetector::toLearnableErrors,
        // never by the engine, like MILPA_ADAPTER_MISSING and MILPA_HOST_PROFILE_OUTDATED), plus the
        // capability version code (Contrato slice T3 — a capability whose providers exist but none
        // satisfies the constraint no longer recycles the CONTRACT version code).
        self::assertContains('MILPA_CONTRACT_MISSING', $codes);
        self::assertContains('MILPA_CONTRACT_VERSION_UNSUPPORTED', $codes);
        self::assertContains('MILPA_CAPABILITY_MISSING', $codes);
        self::assertContains('MILPA_CAPABILITY_VERSION_UNSUPPORTED', $codes);
        self::assertContains('MILPA_CAPABILITY_CONFLICT', $codes);
        self::assertContains('MILPA_SURFACE_REQUIREMENT_UNMET', $codes);
        self::assertContains('MILPA_ADAPTER_MISSING', $codes);
        self::assertContains('MILPA_HOST_PROFILE_OUTDATED', $codes);
        self::assertContains('MILPA_LEGACY_CONTRACT_ACTIVE', $codes);
        self::assertContains('MILPA_LEGACY_NOT_ALLOWED', $codes);
        self::assertContains('MILPA_DEPRECATED_CONTRACT_USED', $codes);
        self::assertContains('MILPA_ARCHITECTURE_GRAPH_BLOCKED', $codes);
        self::assertContains('MILPA_BOOTABLE_WITH_WARNINGS', $codes);
        self::assertContains('MILPA_SURFACE_NOT_ENABLED', $codes);
        self::assertContains('MILPA_SUGGESTED_CAPABILITY_MISSING', $codes);
        self::assertContains('MILPA_RISK_EXPIRY_UNEVALUATED', $codes);
        self::assertContains('MILPA_DEPENDENCY_CYCLE', $codes);
        self::assertContains('MILPA_MANIFEST_DRIFT', $codes);

        self::assertCount(18, $codes);
        self::assertSame(array_values(array_unique($codes)), $codes, 'codes are unique');
    }

    /**
     * The capability version code is fully teachable (Contrato slice T3): its own why (the provider
     * exists, but its contractVersion misses the consumer's range), fixes that name BOTH sides of the
     * mismatch (upgrade the provider / relax the constraint, templated with id + constraint), and the
     * LIVE version-contrato unit plus the #siembra artifact — since the superficies wave, both
     * version codes point at their own lesson, never an invented URL. Contrato T4 attribution parity: this context's
     * requiredBy is a PACKAGE, so the message now names it (the expected string was redefined by that
     * decision — host-origin phrasing is frozen separately below).
     */
    public function testCapabilityVersionUnsupportedIsAFullyTeachableCode(): void
    {
        $error = ErrorCatalog::for('MILPA_CAPABILITY_VERSION_UNSUPPORTED', [
            'id' => 'cache.store',
            'constraint' => '^2.0',
            'requiredBy' => 'acme/crm@1.2.0',
            'hostProfile' => 'agent-ready@2026.07',
        ]);

        self::assertSame(
            'acme/crm@1.2.0 requires the capability "cache.store"; it is provided, but no provider\'s contractVersion satisfies the constraint "^2.0".',
            $error->message,
        );
        self::assertStringContainsString('contractVersion', $error->why);

        self::assertSame(
            [
                'Upgrade a provider of "cache.store" to a contractVersion that satisfies "^2.0".',
                'Relax the "cache.store" constraint "^2.0" on the requirer if an installed provider version is acceptable.',
            ],
            $error->fixes,
        );

        self::assertSame('https://academy.milpa.lat/learn/fundamentos/version-contrato/', $error->links['academy']['es']);
        self::assertSame('https://academy.milpa.lat/en/learn/fundamentos/version-contrato/', $error->links['academy']['en']);
        self::assertSame('https://academy.milpa.lat/artifacts/#siembra', $error->links['artifact']['es']);
        self::assertArrayHasKey('llms', $error->links);
    }

    /**
     * Attribution PARITY on the two version codes (Contrato slice T4, the Orden-slice precedent):
     * when `context.requiredBy` is package- or contract-origin, `MILPA_CONTRACT_VERSION_UNSUPPORTED`
     * and `MILPA_CAPABILITY_VERSION_UNSUPPORTED` both name the requirer — a consistent pair, changed
     * together — so a version miss teaches WHO asked for the range, exactly as the missing codes do.
     */
    public function testVersionMissMessagesNameThePackageRequirerOnBothCodes(): void
    {
        $contract = ErrorCatalog::for('MILPA_CONTRACT_VERSION_UNSUPPORTED', [
            'id' => 'milpa.persistence',
            'constraint' => '^2.0',
            'requiredBy' => 'acme/crm@1.2.0',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'acme/crm@1.2.0 requires the contract "milpa.persistence"; it is implemented, but no implementation satisfies the constraint "^2.0".',
            $contract->message,
        );

        $capability = ErrorCatalog::for('MILPA_CAPABILITY_VERSION_UNSUPPORTED', [
            'id' => 'cache.store',
            'constraint' => '^2.0',
            'requiredBy' => 'contract:milpa.command@0.1',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'contract:milpa.command@0.1 requires the capability "cache.store"; it is provided, but no provider\'s contractVersion satisfies the constraint "^2.0".',
            $capability->message,
        );
    }

    /**
     * Host-origin INTACT on both version codes: a `hostProfile:`-prefixed requiredBy, an absent one,
     * and the owner-less `input` sentinel keep the original phrasing byte for byte — the parity
     * decision touches plugin-origin messages ONLY.
     */
    public function testVersionMissMessagesKeepHostPhrasingForHostInputAndAbsentOrigins(): void
    {
        $expectedContract = 'The contract "milpa.persistence" is implemented, but no implementation satisfies the constraint "^2.0".';
        $expectedCapability = 'The capability "cache.store" is provided, but no provider\'s contractVersion satisfies the constraint "^2.0".';

        foreach (['hostProfile:agent-ready@2026.07', 'input', null] as $origin) {
            $context = ['hostProfile' => 'agent-ready@2026.07', 'constraint' => '^2.0'];
            if ($origin !== null) {
                $context['requiredBy'] = $origin;
            }

            $label = $origin ?? '(absent)';
            self::assertSame(
                $expectedContract,
                ErrorCatalog::for('MILPA_CONTRACT_VERSION_UNSUPPORTED', ['id' => 'milpa.persistence'] + $context)->message,
                "contract phrasing drifted for origin {$label}",
            );
            self::assertSame(
                $expectedCapability,
                ErrorCatalog::for('MILPA_CAPABILITY_VERSION_UNSUPPORTED', ['id' => 'cache.store'] + $context)->message,
                "capability phrasing drifted for origin {$label}",
            );
        }
    }

    /**
     * The oneOf version-miss wording fix (Contrato slice T4, inherited from the T3 review): when the
     * context carries `providedBy` — the candidates that exist only OUT of range — the message says
     * the capability `is provided only through [...]`, naming WHICH candidate fell out of range. The
     * bare "is provided" would mislead when the primary id has no provider at all and only a oneOf
     * alternative is out of range.
     */
    public function testCapabilityVersionMissNamesTheOutOfRangeOneOfCandidate(): void
    {
        $error = ErrorCatalog::for('MILPA_CAPABILITY_VERSION_UNSUPPORTED', [
            'id' => 'log.sink',
            'constraint' => '^2.0',
            'oneOf' => ['log.syslog'],
            'requiredBy' => 'input',
            'providedBy' => ['log.syslog'],
            'hostProfile' => 'agent-ready@2026.07',
        ]);

        self::assertSame(
            'The capability "log.sink" is provided only through ["log.syslog"], but no provider\'s contractVersion satisfies the constraint "^2.0".',
            $error->message,
        );
    }

    /**
     * oneOf exhausted (Contrato slice T3): when the context carries `oneOf`, the capability-missing
     * message enumerates every candidate tried — the primary id plus each alternative — instead of
     * pretending the search space was a single id. Attribution rules are unchanged: a package or
     * contract requirer is named; a host/input origin keeps the host phrasing.
     */
    public function testCapabilityMissingMessageEnumeratesExhaustedOneOfAlternatives(): void
    {
        $hostOrigin = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'log.sink',
            'oneOf' => ['log.file', 'log.syslog'],
            'requiredBy' => 'input',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'The host profile agent-ready@2026.07 requires the capability "log.sink", but none of ["log.sink", "log.file", "log.syslog"] provides it.',
            $hostOrigin->message,
        );

        $fromPackage = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'log.sink',
            'oneOf' => ['log.file', 'log.syslog'],
            'requiredBy' => 'acme/crm@1.2.0',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'acme/crm@1.2.0 requires the capability "log.sink", but none of ["log.sink", "log.file", "log.syslog"] provides it.',
            $fromPackage->message,
        );
    }

    /**
     * Fallback degradation (Contrato slice T3): when the context carries the suggestion's declared
     * `fallback`, the suggested-capability message appends the degradation path; without one it stays
     * byte-identical to the pre-fallback phrasing.
     */
    public function testSuggestedCapabilityMissingMessageNamesTheFallbackDegradation(): void
    {
        $withFallback = ErrorCatalog::for('MILPA_SUGGESTED_CAPABILITY_MISSING', [
            'id' => 'audit.sink',
            'fallback' => 'noop',
        ]);
        self::assertSame(
            'The suggested capability "audit.sink" has no provider; its fallback path applies: degrades to "noop".',
            $withFallback->message,
        );

        $withoutFallback = ErrorCatalog::for('MILPA_SUGGESTED_CAPABILITY_MISSING', ['id' => 'audit.sink']);
        self::assertSame(
            'The suggested capability "audit.sink" has no provider; its fallback path applies.',
            $withoutFallback->message,
        );
    }

    /**
     * The enforcement code is fully teachable: it names the contract and host, teaches the three honest
     * ways out (permit explicitly, permit all consciously, or migrate), and points at LIVE links only —
     * the legacy-y-migracion unit, the #frontera artifact, and the llms resource. No invented URL.
     */
    public function testLegacyNotAllowedIsAFullyTeachableGateCode(): void
    {
        $error = ErrorCatalog::for('MILPA_LEGACY_NOT_ALLOWED', [
            'id' => 'command.host',
            'hostProfile' => 'acme-crm@2026.07',
        ]);

        self::assertStringContainsString('command.host', $error->message);
        self::assertStringContainsString('acme-crm@2026.07', $error->message);

        $fixes = implode("\n", $error->fixes);
        self::assertStringContainsString('allowedLegacyContracts', $fixes);
        self::assertStringContainsStringIgnoringCase('migrate', $fixes);

        self::assertSame('https://academy.milpa.lat/learn/arquitectura/legacy-y-migracion/', $error->links['academy']['es']);
        self::assertSame('https://academy.milpa.lat/artifacts/#frontera', $error->links['artifact']['es']);
        self::assertArrayHasKey('llms', $error->links);
    }

    public function testRiskExpiryUnevaluatedIsAFullyTeachableCode(): void
    {
        $error = ErrorCatalog::for('MILPA_RISK_EXPIRY_UNEVALUATED', ['id' => 'HTTP_SCOPES_NOT_ENFORCED']);

        self::assertStringContainsString('HTTP_SCOPES_NOT_ENFORCED', $error->message);
        self::assertNotSame([], $error->fixes);
        // The fixes teach the two honest ways out: supply a clock, or drop the expiry.
        $fixes = implode("\n", $error->fixes);
        self::assertStringContainsStringIgnoringCase('evaluatedAt', $fixes);
        self::assertStringContainsStringIgnoringCase('expires', $fixes);
        // The LIVE riesgos-aceptados lesson plus the llms resource (no invented lesson URL).
        self::assertSame('https://academy.milpa.lat/learn/arquitectura/riesgos-aceptados/', $error->links['academy']['es']);
        self::assertSame('https://academy.milpa.lat/en/learn/arquitectura/riesgos-aceptados/', $error->links['academy']['en']);
        self::assertArrayHasKey('llms', $error->links);
    }

    /**
     * The anti-dead-error assert: EVERY catalog code carries a non-empty why, at least one fix, and
     * a real Academy learn link (academy + llms). No code is decorative.
     */
    public function testNoCodeIsADeadError(): void
    {
        foreach (ErrorCatalog::codes() as $code) {
            $error = ErrorCatalog::for($code);

            self::assertInstanceOf(LearnableArchitectureError::class, $error, $code);
            self::assertNotSame('', trim($error->why), "{$code} has no why");
            self::assertNotSame('', trim($error->message), "{$code} has no message");
            self::assertNotSame([], $error->fixes, "{$code} has no fixes");

            self::assertArrayHasKey('academy', $error->links, "{$code} has no academy link");
            self::assertArrayHasKey('llms', $error->links, "{$code} has no llms link");
            self::assertArrayHasKey('es', $error->links['academy'], "{$code} academy link is not bilingual");
            self::assertArrayHasKey('en', $error->links['academy'], "{$code} academy link is not bilingual");
            self::assertStringStartsWith('https://academy.milpa.lat/', $error->links['academy']['es'], $code);
            self::assertStringStartsWith('https://academy.milpa.lat/', $error->links['llms']['es'], $code);
        }
    }

    public function testForTemplatesTheMessageWithContext(): void
    {
        $error = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'command.provider',
            'requiredBy' => 'hostProfile:agent-ready@2026.07',
            'hostProfile' => 'agent-ready@2026.07',
        ]);

        self::assertStringContainsString('command.provider', $error->message);
        self::assertStringContainsString('agent-ready@2026.07', $error->message);
        // The known package appears in the human fixes.
        self::assertStringContainsString('milpa/command', implode("\n", $error->fixes));
    }

    /**
     * The message attributes its requirer (Orden slice T2): when a PACKAGE or a CONTRACT — not the
     * host profile — asked for the missing capability, the message names it, so the reader learns WHO
     * opened the graph, not just what is absent.
     */
    public function testCapabilityMissingMessageNamesAPackageOrContractRequirer(): void
    {
        $fromPackage = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'crm.mailer',
            'requiredBy' => 'acme/crm@1.2.0',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'acme/crm@1.2.0 requires the capability "crm.mailer", but no active package or plugin provides it.',
            $fromPackage->message,
        );

        $fromContract = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'event.dispatcher',
            'requiredBy' => 'contract:milpa.command@0.1',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame(
            'contract:milpa.command@0.1 requires the capability "event.dispatcher", but no active package or plugin provides it.',
            $fromContract->message,
        );
    }

    /**
     * Host-origin INTACT: a `hostProfile:`-prefixed requiredBy, an absent requiredBy, and the
     * owner-less `input` sentinel (a caller-supplied typed requirement no installed manifest
     * declares — not a package) all keep the original host phrasing, byte for byte.
     */
    public function testCapabilityMissingMessageKeepsHostPhrasingForHostInputAndAbsentOrigins(): void
    {
        $expected = 'The host profile agent-ready@2026.07 requires the capability "command.provider", but no active package or plugin provides it.';

        $hostOrigin = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'command.provider',
            'requiredBy' => 'hostProfile:agent-ready@2026.07',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame($expected, $hostOrigin->message);

        $absentOrigin = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'command.provider',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame($expected, $absentOrigin->message);

        $inputOrigin = ErrorCatalog::for('MILPA_CAPABILITY_MISSING', [
            'id' => 'command.provider',
            'requiredBy' => 'input',
            'hostProfile' => 'agent-ready@2026.07',
        ]);
        self::assertSame($expected, $inputOrigin->message);
    }

    /**
     * The drift code is fully teachable: the message names the drifted package, the fixes teach the
     * two honest ways out (regenerate the manifest from the code, or fix the attribute), and the
     * learn links point at the LIVE boundary lesson (atlas-limites) plus the #compuerta-arranque
     * artifact — URLs verified live, never invented.
     */
    public function testManifestDriftIsAFullyTeachableCode(): void
    {
        $error = ErrorCatalog::for('MILPA_MANIFEST_DRIFT', [
            'package' => 'OAuthPlugin',
            'fields' => [
                ['field' => 'provides', 'declared' => 'Foo\\Gone', 'actual' => null],
                ['field' => 'version', 'declared' => '1.0.0', 'actual' => '2.0.0'],
            ],
        ]);

        self::assertStringContainsString('OAuthPlugin', $error->message);
        self::assertStringContainsString('2', $error->message);

        $fixes = implode("\n", $error->fixes);
        self::assertStringContainsString('coa:plugins manifest OAuthPlugin', $fixes);
        self::assertStringContainsString('#[PluginMetadata]', $fixes);

        self::assertSame('https://academy.milpa.lat/learn/arquitectura/atlas-limites/', $error->links['academy']['es']);
        self::assertSame('https://academy.milpa.lat/en/learn/arquitectura/atlas-limites/', $error->links['academy']['en']);
        self::assertSame('https://academy.milpa.lat/artifacts/#compuerta-arranque', $error->links['artifact']['es']);
        self::assertArrayHasKey('llms', $error->links);
    }

    public function testCapabilityMissingPointsAtTheContractsGraphUnitAndSiembra(): void
    {
        $links = ErrorCatalog::for('MILPA_CAPABILITY_MISSING')->links;

        self::assertSame('https://academy.milpa.lat/learn/fundamentos/contratos-grafo/', $links['academy']['es']);
        self::assertSame('https://academy.milpa.lat/en/learn/fundamentos/contratos-grafo/', $links['academy']['en']);
        self::assertSame('https://academy.milpa.lat/artifacts/#siembra', $links['artifact']['es']);
    }

    public function testSurfaceRequirementUnmetPointsAtAtomo(): void
    {
        $links = ErrorCatalog::for('MILPA_SURFACE_REQUIREMENT_UNMET')->links;

        self::assertSame('https://academy.milpa.lat/artifacts/#atomo', $links['artifact']['es']);
        self::assertSame('https://academy.milpa.lat/en/artifacts/#atomo', $links['artifact']['en']);
    }

    public function testCapabilityConflictPointsAtFrontera(): void
    {
        $links = ErrorCatalog::for('MILPA_CAPABILITY_CONFLICT')->links;

        self::assertSame('https://academy.milpa.lat/artifacts/#frontera', $links['artifact']['es']);
    }

    public function testHasReportsCatalogMembership(): void
    {
        self::assertTrue(ErrorCatalog::has('MILPA_CAPABILITY_MISSING'));
        self::assertFalse(ErrorCatalog::has('HTTP_SCOPES_NOT_ENFORCED'));
    }

    public function testForRejectsAnUnknownCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ErrorCatalog::for('MILPA_NOT_A_REAL_CODE');
    }
}
