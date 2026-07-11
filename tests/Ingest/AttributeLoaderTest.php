<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Attributes\PluginMetadata;
use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Ingest\AttributeLoader;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Tests\Fixtures\SampleAnnotatedPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The second real metadata source: `#[PluginMetadata]` reflected off a plugin class (or handed in as
 * a record directly — the metadataOf pattern from CapabilityGraphChecker). Its bare-FQCN lists become
 * the same synthesised, unversioned capability records the legacy manifest loader produces, and its
 * `type` rides through as `metadata['pluginType']` (the only surface declaration the attribute carries).
 */
final class AttributeLoaderTest extends TestCase
{
    public function testFromClassReflectsThePluginMetadataAttribute(): void
    {
        $manifest = (new AttributeLoader())->fromClass(SampleAnnotatedPlugin::class);

        self::assertInstanceOf(VersionManifest::class, $manifest);
        self::assertSame('milpa/oauthplugin', $manifest->package);
        self::assertSame('2.0.0', $manifest->version);
        self::assertSame('attribute', $manifest->metadata['shape']);
        self::assertSame('Service', $manifest->metadata['pluginType']);
    }

    public function testBareFqcnsBecomeUnversionedProvisionRecords(): void
    {
        $manifest = (new AttributeLoader())->fromClass(SampleAnnotatedPlugin::class);

        $provides = $manifest->capabilities['provides'];
        self::assertCount(3, $provides);
        self::assertSame('Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface', $provides[0]['id']);
        self::assertSame('0.0.0', $provides[0]['contractVersion']);
        self::assertSame([], $manifest->capabilities['requires']);
        self::assertSame([], $manifest->capabilities['suggests']);
    }

    public function testAcceptsAPluginMetadataInstanceDirectly(): void
    {
        $metadata = new PluginMetadata(
            version: '1.2.3',
            author: 'Dev',
            site: 'https://example.com',
            name: 'milpa/direct',
            type: 'CLI',
            provides: ['Foo\\BarInterface'],
        );

        $manifest = (new AttributeLoader())->fromMetadata($metadata);

        self::assertSame('milpa/direct', $manifest->package);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame('CLI', $manifest->metadata['pluginType']);
        self::assertSame('Foo\\BarInterface', $manifest->capabilities['provides'][0]['id']);
    }

    public function testFromClassWithoutTheAttributeThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        (new AttributeLoader())->fromClass(\stdClass::class);
    }

    public function testFromClassWithUnknownClassThrows(): void
    {
        $this->expectException(InvalidManifestException::class);
        (new AttributeLoader())->fromClass('Milpa\\Resolver\\Tests\\Fixtures\\NoSuchPlugin');
    }
}
