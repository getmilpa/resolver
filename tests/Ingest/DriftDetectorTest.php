<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Resolver\Ingest\AttributeLoader;
use Milpa\Resolver\Ingest\DriftDetector;
use Milpa\Resolver\Ingest\ManifestLoader;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\Resolver\Tests\Fixtures\SampleAnnotatedPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Drift is the gap between what a package DECLARES in its milpa.json and what its code ACTUALLY carries
 * in `#[PluginMetadata]`. This exercises the field-level diff over provides/requires/suggests (identity
 * normalised by `ltrim('\\')`), version and name — plus `toLearnableErrors()`, which lifts a non-empty
 * diff into the one MILPA_MANIFEST_DRIFT learnable error the host's inspect surface attaches (Orden T2;
 * the deferral the slice-1 docblock recorded).
 */
final class DriftDetectorTest extends TestCase
{
    public function testDetectsProvidesAddedAndRemovedOnBothSides(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A', 'Foo\\Gone']]);
        $actual = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A', 'Foo\\New']]);

        $drift = (new DriftDetector())->diff($declared, $actual);

        self::assertContains(['field' => 'provides', 'declared' => 'Foo\\Gone', 'actual' => null], $drift);
        self::assertContains(['field' => 'provides', 'declared' => null, 'actual' => 'Foo\\New'], $drift);
        // Foo\A is on both sides — no drift for it.
        self::assertNotContains(['field' => 'provides', 'declared' => 'Foo\\A', 'actual' => null], $drift);
    }

    public function testLeadingBackslashIsNormalisedAwayBeforeComparing(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\Bar']]);
        $actual = $this->manifest('milpa/x', '1.0.0', ['provides' => ['\\Foo\\Bar']]);

        self::assertSame([], (new DriftDetector())->diff($declared, $actual));
    }

    public function testDetectsVersionAndNameDrift(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', []);
        $actual = $this->manifest('milpa/y', '2.0.0', []);

        $drift = (new DriftDetector())->diff($declared, $actual);

        self::assertContains(['field' => 'name', 'declared' => 'milpa/x', 'actual' => 'milpa/y'], $drift);
        self::assertContains(['field' => 'version', 'declared' => '1.0.0', 'actual' => '2.0.0'], $drift);
    }

    public function testDetectsRequiresAndSuggestsDrift(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', ['requires' => ['Foo\\Req'], 'suggests' => ['Foo\\Sug']]);
        $actual = $this->manifest('milpa/x', '1.0.0', ['requires' => [], 'suggests' => []]);

        $drift = (new DriftDetector())->diff($declared, $actual);

        self::assertContains(['field' => 'requires', 'declared' => 'Foo\\Req', 'actual' => null], $drift);
        self::assertContains(['field' => 'suggests', 'declared' => 'Foo\\Sug', 'actual' => null], $drift);
    }

    public function testIdenticalManifestsHaveNoDrift(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A'], 'requires' => ['Foo\\B']]);
        $actual = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A'], 'requires' => ['Foo\\B']]);

        self::assertSame([], (new DriftDetector())->diff($declared, $actual));
    }

    public function testDriftBetweenTheTwoRealLoadersEndToEnd(): void
    {
        // declared = the OAuthPlugin legacy manifest (7 provides); actual = the code's #[PluginMetadata]
        // (3 provides, one of which the manifest never declared). Same name + version → drift is purely
        // in the provides set: five declared-not-real + one real-not-declared.
        $declared = (new ManifestLoader())->load(__DIR__ . '/../Fixtures/oauthplugin.milpa.json');
        $actual = (new AttributeLoader())->fromClass(SampleAnnotatedPlugin::class);

        $drift = (new DriftDetector())->diff($declared, $actual);

        $removed = array_filter($drift, static fn (array $d): bool => $d['field'] === 'provides' && $d['actual'] === null);
        $added = array_filter($drift, static fn (array $d): bool => $d['field'] === 'provides' && $d['declared'] === null);

        self::assertCount(5, $removed, 'five interfaces declared in milpa.json but absent from the attribute');
        self::assertContains(
            ['field' => 'provides', 'declared' => null, 'actual' => 'Milpa\\OAuth\\Contracts\\LinkedInOAuthServiceInterface'],
            $added,
        );
        self::assertNotContains('name', array_column($drift, 'field'));
        self::assertNotContains('version', array_column($drift, 'field'));
    }

    public function testEmptyDiffYieldsNoLearnableErrors(): void
    {
        self::assertSame([], (new DriftDetector())->toLearnableErrors([], 'milpa/x'));
    }

    /**
     * A non-empty diff becomes exactly ONE learnable error per package: code MILPA_MANIFEST_DRIFT,
     * context `{package, fields: the diff rows}` verbatim, a message that names the package, catalog
     * fixes (regenerate the manifest / fix the attribute), and the live boundary lesson to learn from.
     */
    public function testNonEmptyDiffBecomesOneManifestDriftLearnableError(): void
    {
        $declared = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A', 'Foo\\Gone']]);
        $actual = $this->manifest('milpa/x', '1.0.0', ['provides' => ['Foo\\A', 'Foo\\New']]);

        $detector = new DriftDetector();
        $diff = $detector->diff($declared, $actual);
        $errors = $detector->toLearnableErrors($diff, 'milpa/x');

        self::assertCount(1, $errors);
        $error = $errors[0];
        self::assertInstanceOf(LearnableArchitectureError::class, $error);
        self::assertSame('MILPA_MANIFEST_DRIFT', $error->code);
        self::assertSame(['package' => 'milpa/x', 'fields' => $diff], $error->context);
        self::assertStringContainsString('milpa/x', $error->message);
        self::assertNotSame('', trim($error->why));
        self::assertStringContainsString('coa:plugins manifest milpa/x', implode("\n", $error->fixes));
        self::assertSame('https://academy.milpa.lat/learn/arquitectura/atlas-limites/', $error->links['academy']['es']);
        self::assertArrayHasKey('llms', $error->links);
    }

    /**
     * @param array<string, list<string>> $capabilities
     */
    private function manifest(string $package, string $version, array $capabilities): VersionManifest
    {
        return new VersionManifest(
            package: $package,
            version: $version,
            contracts: [],
            capabilities: [
                'provides' => $this->records($capabilities['provides'] ?? []),
                'requires' => $this->records($capabilities['requires'] ?? []),
                'suggests' => $this->records($capabilities['suggests'] ?? []),
            ],
        );
    }

    /**
     * @param list<string> $fqcns
     *
     * @return list<array<string, string>>
     */
    private function records(array $fqcns): array
    {
        return array_map(static fn (string $fqcn): array => ['id' => $fqcn, 'interface' => $fqcn], $fqcns);
    }
}
