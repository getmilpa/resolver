<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Ingest;

use Milpa\Attributes\PluginMetadata;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Exceptions\InvalidManifestException;
use Milpa\Resolver\Ingest\AttributeLoader;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\ResolutionStatus;
use PHPUnit\Framework\TestCase;

/**
 * T087 — the rich capability record flows from `#[PluginMetadata]` through ingestion into the engine
 * as a CANONICAL provision. A `provides`/`requires`/`suggests` entry that is an ARRAY routes through
 * the canonical `fromArray()` of core's capability records (real contractVersion/service, `exclusive`
 * defaulting TRUE per capability-spec §3.1), while a bare-FQCN string keeps the byte-identical legacy
 * synthesis (`contractVersion 0.0.0`, `exclusive: false` pinned by `fromInterface()`). Mixing both in
 * one plugin is valid — that is the incremental migration path.
 */
final class AttributeLoaderRichRecordsTest extends TestCase
{
    private const CONTACT_FORM_INTERFACE = 'Milpa\\Plugins\\Crm\\Contracts\\ContactFormServiceInterface';
    private const CONTACT_FORM_SERVICE = 'Milpa\\Plugins\\Crm\\Services\\ContactFormService';

    public function testRichProvidesRecordBecomesACanonicalProvision(): void
    {
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [[
            'id' => 'crm.contact-form.v1',
            'interface' => self::CONTACT_FORM_INTERFACE,
            'contractVersion' => '1.0.0',
            'service' => self::CONTACT_FORM_SERVICE,
        ]]));

        // The full canonical shape: real contractVersion and service, priority absent = 0, and
        // exclusive TRUE — the §3.1 canon default fromArray() applies, NOT the exclusive:false the
        // legacy fromInterface() pins. The manifest shape stays 'attribute' (never 'legacy-contracts').
        self::assertSame([
            'id' => 'crm.contact-form.v1',
            'interface' => self::CONTACT_FORM_INTERFACE,
            'contractVersion' => '1.0.0',
            'service' => self::CONTACT_FORM_SERVICE,
            'priority' => 0,
            'exclusive' => true,
        ], $manifest->capabilities['provides'][0]);
        self::assertSame('attribute', $manifest->metadata['shape']);
    }

    public function testRichOnlyPluginResolvesThroughTheCapabilityPathWithZeroLegacyEntries(): void
    {
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [[
            'id' => 'crm.contact-form.v1',
            'interface' => self::CONTACT_FORM_INTERFACE,
            'contractVersion' => '1.0.0',
            'service' => self::CONTACT_FORM_SERVICE,
        ]]));

        $report = (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: new HostProfile(
                name: 'crm-host',
                version: '1.0.0',
                requiredCapabilities: ['crm.contact-form.v1@^1.0'],
            ),
            versionManifests: [$manifest],
            contractManifests: [],
            capabilityProvisions: [],
            capabilityRequirements: [],
        ));

        // End-to-end: the attribute-declared rich record satisfies the host's versioned requirement
        // through the capability path — kind 'capability', via 'direct' (the engine's non-legacy
        // vocabulary), provider labelled by the record's real service — with ZERO legacy[] entries.
        self::assertSame(ResolutionStatus::Valid, $report->status);
        self::assertSame([], $report->legacy);
        $capability = $report->resolved[0];
        self::assertSame('capability', $capability['kind']);
        self::assertSame('crm.contact-form.v1', $capability['id']);
        self::assertSame('direct', $capability['via']);
        self::assertSame(self::CONTACT_FORM_SERVICE, $capability['providedBy']);
    }

    public function testRichRequiresRecordCarriesConstraintAndOneOf(): void
    {
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(requires: [[
            'id' => 'milpa.auth.oauth',
            'interface' => 'Milpa\\Contracts\\OAuthProviderInterface',
            'constraint' => '^1.0',
            'oneOf' => ['milpa.auth.oauth.google', 'milpa.auth.oauth.apple'],
        ]]));

        self::assertSame([
            'id' => 'milpa.auth.oauth',
            'interface' => 'Milpa\\Contracts\\OAuthProviderInterface',
            'constraint' => '^1.0',
            'oneOf' => ['milpa.auth.oauth.google', 'milpa.auth.oauth.apple'],
        ], $manifest->capabilities['requires'][0]);
    }

    public function testRichSuggestsRecordCarriesFallback(): void
    {
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(suggests: [[
            'id' => 'milpa.audit.logger',
            'interface' => 'Milpa\\Contracts\\AuditLoggerInterface',
            'constraint' => '^1.0',
            'fallback' => 'noop',
        ]]));

        self::assertSame([
            'id' => 'milpa.audit.logger',
            'interface' => 'Milpa\\Contracts\\AuditLoggerInterface',
            'constraint' => '^1.0',
            'fallback' => 'noop',
        ], $manifest->capabilities['suggests'][0]);
    }

    public function testMixedPluginKeepsBareStringsLegacyShapedAndRichRecordsCanonical(): void
    {
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [
            [
                'id' => 'crm.contact-form.v1',
                'interface' => self::CONTACT_FORM_INTERFACE,
                'contractVersion' => '1.0.0',
                'service' => self::CONTACT_FORM_SERVICE,
            ],
            'Milpa\\Plugins\\Crm\\Contracts\\LeadCaptureServiceInterface',
        ]));

        // The rich record is canonical (exclusive TRUE default) ...
        $rich = $manifest->capabilities['provides'][0];
        self::assertSame('crm.contact-form.v1', $rich['id']);
        self::assertSame('1.0.0', $rich['contractVersion']);
        self::assertTrue($rich['exclusive']);

        // ... while its bare-string neighbour keeps the byte-identical legacy synthesis: id ==
        // interface == the FQCN, contractVersion 0.0.0, no service, and exclusive FALSE — the pin
        // fromInterface() applies because legacy declarations predate the exclusive field.
        self::assertSame([
            'id' => 'Milpa\\Plugins\\Crm\\Contracts\\LeadCaptureServiceInterface',
            'interface' => 'Milpa\\Plugins\\Crm\\Contracts\\LeadCaptureServiceInterface',
            'contractVersion' => '0.0.0',
            'service' => null,
            'priority' => 0,
            'exclusive' => false,
        ], $manifest->capabilities['provides'][1]);
    }

    public function testBareOnlyPluginStaysByteIdenticalToTheLegacySynthesis(): void
    {
        // Clones the expectations the pre-rich suite pins (AttributeLoaderTest) and widens them to the
        // FULL record: a bare-strings-only plugin must produce exactly what it produced before rich
        // records existed — the legacy-shaped unversioned records, in a manifest marked 'attribute'.
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [
            'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface',
        ]));

        self::assertSame([
            'id' => 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface',
            'interface' => 'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface',
            'contractVersion' => '0.0.0',
            'service' => null,
            'priority' => 0,
            'exclusive' => false,
        ], $manifest->capabilities['provides'][0]);
        self::assertSame([], $manifest->capabilities['requires']);
        self::assertSame([], $manifest->capabilities['suggests']);
        self::assertSame('attribute', $manifest->metadata['shape']);
    }

    public function testMalformedRichRecordSurfacesTheRecordsOwnTeachingMessage(): void
    {
        // Validation is DELEGATED to the core record's fromArray() — never duplicated here. A record
        // without an interface fails with the value object's own message naming the offending record,
        // wrapped so the manifest subject (the plugin) is named too.
        try {
            (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [[
                'id' => 'crm.contact-form.v1',
                'contractVersion' => '1.0.0',
                'service' => self::CONTACT_FORM_SERVICE,
            ]]));
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString('milpa/crm-contact', $e->getMessage());
            self::assertStringContainsString(
                'Capability `provides` record "crm.contact-form.v1" requires a non-empty "interface".',
                $e->getMessage(),
            );
        }
    }

    public function testRichRecordWithInvalidContractVersionSurfacesTheSemverTeachingMessage(): void
    {
        try {
            (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [[
                'id' => 'crm.contact-form.v1',
                'interface' => self::CONTACT_FORM_INTERFACE,
                'service' => self::CONTACT_FORM_SERVICE,
            ]]));
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString('milpa/crm-contact', $e->getMessage());
            self::assertStringContainsString('Invalid semantic version', $e->getMessage());
        }
    }

    public function testRichProvidesWithoutServiceStaysValidPerTheRecordsOwnValidation(): void
    {
        // Honest divergence, pinned so it is visible: milpa-plugin.schema.json requires `service` on a
        // provides record, but core's CapabilityProvision deliberately keeps it nullable — and BOTH
        // ingestion paths (ManifestLoader and this loader) delegate to that same fromArray(). The
        // engine then falls back to the package label for the provider. Tightening this belongs to the
        // value object, not to a loader growing its own divergent validation.
        $manifest = (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [[
            'id' => 'crm.contact-form.v1',
            'interface' => self::CONTACT_FORM_INTERFACE,
            'contractVersion' => '1.0.0',
        ]]));

        self::assertNull($manifest->capabilities['provides'][0]['service']);
        self::assertTrue($manifest->capabilities['provides'][0]['exclusive']);
    }

    public function testAnEntryThatIsNeitherStringNorRecordFailsWithTheSharedTeachingMessage(): void
    {
        try {
            (new AttributeLoader())->fromMetadata($this->metadataWith(provides: [42]));
            self::fail('expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            self::assertStringContainsString('milpa/crm-contact', $e->getMessage());
            self::assertStringContainsString('provides entry #0 must be an FQCN string or a record object', $e->getMessage());
        }
    }

    /**
     * A `#[PluginMetadata]` record for the CRM contact plugin with the given capability lists.
     *
     * @param array<int, mixed> $provides
     * @param array<int, mixed> $requires
     * @param array<int, mixed> $suggests
     */
    private function metadataWith(array $provides = [], array $requires = [], array $suggests = []): PluginMetadata
    {
        return new PluginMetadata(
            version: '1.0.0',
            author: 'Dev',
            site: 'https://example.com',
            name: 'milpa/crm-contact',
            type: 'Service',
            provides: $provides,
            requires: $requires,
            suggests: $suggests,
        );
    }
}
