<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Engine;

use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The anti-decorative GATE (spec §25 anti-pattern 5, "manifest decorativo").
 *
 * Every public property a manifest value object declares MUST have a consumer in the resolver's
 * `Engine/` (or, once it lands, `Ingest/`) source. A field nobody reads is decorative metadata —
 * it lies to the plugin author about what the resolver actually uses. This gate walks the public
 * properties of VersionManifest/ContractManifest/HostProfile by reflection and asserts each is
 * referenced as a `->property` access in the concatenated source. If a field loses its consumer,
 * this test goes red and forces the choice: wire it or prune it from the value object.
 *
 * Mutation evidence: deleting the `$manifest->deprecations` read from GraphResolver makes THIS test
 * fail on `VersionManifest::$deprecations` (verified during T3; see task-3-report.md) — the gate
 * bites, it does not rubber-stamp. It was also the forcing function for pruning `adapters`,
 * `profiles`, and `adapterRequirements`: all uniquely-named, so their absence of a consumer would
 * have failed here.
 *
 * Known limit (honest): matching is by property NAME, so a name shared across value objects (e.g.
 * `metadata`, consumed as both `$manifest->metadata` and `$host->metadata`; `version`) is satisfied
 * as long as ONE consuming value object reads it. The gate guarantees no orphan field name, not
 * per-class attribution — enough to keep decorative metadata out of the value objects.
 *
 * This is the coupling-test pattern graduated in the Academy (artifact `frontera`).
 */
final class ManifestFieldCouplingTest extends TestCase
{
    /**
     * @return list<array{0: class-string, 1: string}>
     */
    public static function manifestProperties(): array
    {
        $out = [];
        foreach ([VersionManifest::class, ContractManifest::class, HostProfile::class] as $class) {
            $rc = new \ReflectionClass($class);
            foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $out[] = [$class, $property->getName()];
            }
        }

        return $out;
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('manifestProperties')]
    public function testEveryManifestFieldHasAConsumer(string $class, string $property): void
    {
        $source = self::consumerSource();

        self::assertMatchesRegularExpression(
            '/->' . preg_quote($property, '/') . '\b/',
            $source,
            sprintf(
                'Public property %s::$%s is declared but never consumed by Engine/ or Ingest/. '
                . 'Wire it into the resolver or prune it from the value object (spec §25 anti-pattern 5).',
                $class,
                $property,
            ),
        );
    }

    /**
     * The concatenated source of every consuming layer (`Engine/` and, when present, `Ingest/`).
     */
    private static function consumerSource(): string
    {
        $src = dirname(__DIR__, 2) . '/src';
        $source = '';
        foreach (['Engine', 'Ingest'] as $dir) {
            $path = $src . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                    $source .= (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $source;
    }
}
