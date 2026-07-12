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
    public function testCatalogCoversTheFifteenCodes(): void
    {
        $codes = ErrorCatalog::codes();

        // The 11 initial codes of spec §13, the two ambiguity-splitting codes (T2 carry), the
        // acceptedRisks-expiry-without-a-clock code (invariantes slice T2), plus the
        // allowedLegacyContracts enforcement code (invariantes slice T3).
        self::assertContains('MILPA_CONTRACT_MISSING', $codes);
        self::assertContains('MILPA_CONTRACT_VERSION_UNSUPPORTED', $codes);
        self::assertContains('MILPA_CAPABILITY_MISSING', $codes);
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

        self::assertCount(15, $codes);
        self::assertSame(array_values(array_unique($codes)), $codes, 'codes are unique');
    }

    /**
     * The enforcement code is fully teachable: it names the contract and host, teaches the three honest
     * ways out (permit explicitly, permit all consciously, or migrate), and points at LIVE links only —
     * the contratos-grafo unit, the #frontera artifact, and the llms resource. No invented URL.
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

        self::assertSame('https://academy.milpa.lat/learn/fundamentos/contratos-grafo/', $error->links['academy']['es']);
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
        // Honest links only — the Academy root plus the llms resource (no invented lesson URL).
        self::assertStringStartsWith('https://academy.milpa.lat/', $error->links['academy']['es']);
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
