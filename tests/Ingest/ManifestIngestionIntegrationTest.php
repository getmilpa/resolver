<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\ManifestLoader;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

/**
 * The loader → engine seam, end to end. Proves the ingestion layer produces a VersionManifest the pure
 * engine actually consumes: a real legacy milpa.json, loaded and marked `legacy-contracts`, resolves
 * against a permissive host profile to `legacy_compatible` — the honest first verdict for the CRM's
 * five legacy-shaped plugins (spec §26.10, the dogfood target of slice 1).
 */
final class ManifestIngestionIntegrationTest extends TestCase
{
    public function testLegacyManifestResolvesToLegacyCompatibleThroughTheEngine(): void
    {
        $manifest = (new ManifestLoader())->load(__DIR__ . '/../Fixtures/oauthplugin.milpa.json');

        $host = new HostProfile(
            name: 'acme-crm',
            version: '2026.07',
            requiredCapabilities: ['Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface'],
            allowedLegacyContracts: ['*'],
        );

        $input = new ResolutionInput(
            hostProfile: $host,
            versionManifests: [$manifest],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        );

        $report = (new GraphResolver())->resolve($input);

        self::assertSame(ResolutionStatus::LegacyCompatible, $report->status);

        // The required capability is resolved, via the legacy-shaped provider, and the legacy use is
        // visible and permitted in the report.
        $resolved = $this->entryBy($report->resolved, 'id', 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface');
        self::assertNotNull($resolved);
        self::assertSame('legacy', $resolved['via']);

        $legacy = $this->entryBy($report->legacy, 'id', 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface');
        self::assertNotNull($legacy);
        self::assertTrue($legacy['permitted']);
        self::assertSame('MILPA_LEGACY_CONTRACT_ACTIVE', $legacy['code']);
    }

    /**
     * The gate on the CRM's own posture (T3): flip `allowedLegacyContracts` from `["*"]` to `[]` and the
     * SAME real legacy plugin manifest that resolved to legacy_compatible now BLOCKS — proof the allowlist
     * is a real gate, not a note. The real root `milpa.json` is untouched; this is a test-only host profile.
     */
    public function testCrmWouldBlockIfItForbadeAllLegacy(): void
    {
        $manifest = (new ManifestLoader())->load(__DIR__ . '/../Fixtures/oauthplugin.milpa.json');

        $host = new HostProfile(
            name: 'acme-crm',
            version: '2026.07',
            requiredCapabilities: ['Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface'],
            allowedLegacyContracts: [],
        );

        $report = (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: $host,
            versionManifests: [$manifest],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        self::assertSame(ResolutionStatus::Blocked, $report->status);

        $missing = $this->entryBy($report->missing, 'id', 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface');
        self::assertNotNull($missing);
        self::assertSame('legacy-contract', $missing['kind']);
        self::assertSame('MILPA_LEGACY_NOT_ALLOWED', $missing['code']);

        // Still visible in legacy[] as not-permitted — both views carry the denial.
        $legacy = $this->entryBy($report->legacy, 'id', 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface');
        self::assertNotNull($legacy);
        self::assertFalse($legacy['permitted']);
    }

    /**
     * @param list<array<string, mixed>> $list
     *
     * @return array<string, mixed>|null
     */
    private function entryBy(array $list, string $key, string $value): ?array
    {
        foreach ($list as $entry) {
            if (($entry[$key] ?? null) === $value) {
                return $entry;
            }
        }

        return null;
    }
}
