<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Ingest\ManifestLoader;
use Milpa\Resolver\Manifest\VersionManifest;
use PHPUnit\Framework\TestCase;

/**
 * The ingestion layer's job is to read the two REAL metadata sources of the ecosystem — the legacy
 * `contracts.*` bare-FQCN milpa.json (the five CRM plugins) and the canonical `capabilities.*` 012
 * records — into one uniform VersionManifest, and to fail loudly (with path + field context) on
 * anything malformed. These tests pin both shapes and every failure mode.
 */
final class ManifestLoaderTest extends TestCase
{
    private const LEGACY = __DIR__ . '/../Fixtures/oauthplugin.milpa.json';
    private const CANONICAL = __DIR__ . '/../Fixtures/canonical.milpa.json';

    public function testLegacyManifestIsMarkedWithTheShapeTheEngineDetects(): void
    {
        $manifest = (new ManifestLoader())->load(self::LEGACY);

        self::assertInstanceOf(VersionManifest::class, $manifest);
        self::assertSame('legacy-contracts', $manifest->metadata['shape']);
        self::assertSame('milpa/oauthplugin', $manifest->package);
        self::assertSame('2.0.0', $manifest->version);
    }

    public function testLegacyContractsProvidesAreSynthesisedAsUnversionedRecords(): void
    {
        $manifest = (new ManifestLoader())->load(self::LEGACY);

        $provides = $manifest->capabilities['provides'];
        self::assertCount(7, $provides);

        // Synthesised record: id == interface == the bare FQCN, contractVersion pinned to '0.0.0'
        // (the same unversioned default core's CapabilityProvision::fromInterface() produces — the
        // constraint edge T2's engine range-checks with Semver::satisfies('0.0.0', '*')).
        $google = $provides[0];
        self::assertSame('Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface', $google['id']);
        self::assertSame('Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface', $google['interface']);
        self::assertSame('0.0.0', $google['contractVersion']);
    }

    public function testLegacyEmptyRequiresAndSuggestsSynthesiseEmptyLists(): void
    {
        $manifest = (new ManifestLoader())->load(self::LEGACY);

        self::assertSame([], $manifest->capabilities['requires']);
        self::assertSame([], $manifest->capabilities['suggests']);
    }

    public function testLegacyExtrasArePassedThroughMetadataWithoutNewValueObjectFields(): void
    {
        $manifest = (new ManifestLoader())->load(self::LEGACY);

        // milpa.min-version / milpa.php-version, env-vars and dependencies are honest passthrough —
        // the resolver does not resolve them yet, so they ride in metadata rather than in typed fields.
        self::assertSame('2.0.0', $manifest->metadata['milpa']['min-version']);
        self::assertSame('>=8.2', $manifest->metadata['milpa']['php-version']);
        self::assertSame([], $manifest->metadata['env-vars']);
        self::assertSame(['plugins' => [], 'composer' => []], $manifest->metadata['dependencies']);
        self::assertSame('Service', $manifest->metadata['pluginType']);
    }

    public function testCanonicalManifestParsesEveryRecordShapeCompletely(): void
    {
        $manifest = (new ManifestLoader())->load(self::CANONICAL);

        self::assertSame('canonical', $manifest->metadata['shape']);
        self::assertSame('milpa/oauth', $manifest->package);
        self::assertSame('1.0.0', $manifest->version);

        // provides: priority + exclusive preserved through the round-trip.
        $store = $manifest->capabilities['provides'][1];
        self::assertSame('milpa.persistence.store', $store['id']);
        self::assertSame('2.1.0', $store['contractVersion']);
        self::assertSame(50, $store['priority']);
        self::assertTrue($store['exclusive']);

        // requires: oneOf preserved.
        $require = $manifest->capabilities['requires'][0];
        self::assertSame('milpa.auth.oauth', $require['id']);
        self::assertSame('^1.0', $require['constraint']);
        self::assertSame(['milpa.auth.oauth.google', 'milpa.auth.oauth.apple'], $require['oneOf']);

        // suggests: fallback preserved.
        $suggest = $manifest->capabilities['suggests'][0];
        self::assertSame('milpa.audit.logger', $suggest['id']);
        self::assertSame('noop', $suggest['fallback']);
    }

    public function testMissingFileThrowsWithThePathInTheMessage(): void
    {
        $path = __DIR__ . '/../Fixtures/does-not-exist.json';

        try {
            (new ManifestLoader())->load($path);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testInvalidJsonThrowsWithThePathInTheMessage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'milpa-resolver-') ?: '';
        file_put_contents($path, '{ this is not valid json ');

        try {
            (new ManifestLoader())->load($path);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testShapelessManifestThrows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'milpa-resolver-') ?: '';
        file_put_contents($path, json_encode(['name' => 'milpa/x', 'version' => '1.0.0']));

        try {
            (new ManifestLoader())->load($path);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testCorruptFieldThrowsWithBothPathAndFieldContext(): void
    {
        // Mutation: take the real legacy fixture and corrupt its version to a non-version. The message
        // must name BOTH the file path (where) and the offending field (what).
        $decoded = json_decode((string) file_get_contents(self::LEGACY), true);
        self::assertIsArray($decoded);
        $decoded['version'] = 'not-a-semver';

        $path = tempnam(sys_get_temp_dir(), 'milpa-resolver-') ?: '';
        file_put_contents($path, (string) json_encode($decoded));

        try {
            (new ManifestLoader())->load($path);
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString($path, $e->getMessage());
            self::assertStringContainsString('version', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
