<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Capability;

use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;
use PHPUnit\Framework\TestCase;

/**
 * The resolver REUSES the canonical 012 capability records from milpa/core rather than
 * redefining them. These tests pin that the reused symbols still parse the full 012 record
 * shapes the resolver relies on — if core drifts, they break here, not silently downstream.
 */
final class CoreCapabilityReuseTest extends TestCase
{
    public function testCanonicalProvidesRecordParsesWithPriorityAndExclusive(): void
    {
        $p = CapabilityProvision::fromArray([
            'id' => 'milpa.auth.oauth.google',
            'interface' => 'Milpa\\Contracts\\OAuthProviderInterface',
            'contractVersion' => '1.0.0',
            'service' => 'Milpa\\Providers\\GoogleOAuthProvider',
            'priority' => 100,
            'exclusive' => false,
        ]);

        self::assertSame('milpa.auth.oauth.google', $p->id);
        self::assertSame('Milpa\\Contracts\\OAuthProviderInterface', $p->interface);
        self::assertSame('1.0.0', $p->contractVersion);
        self::assertSame('Milpa\\Providers\\GoogleOAuthProvider', $p->service);
        self::assertSame(100, $p->priority);
        self::assertFalse($p->exclusive);
    }

    public function testCanonicalRequiresRecordParsesWithOneOf(): void
    {
        $r = CapabilityRequirement::fromArray([
            'id' => 'milpa.auth.oauth',
            'interface' => 'Milpa\\Contracts\\OAuthProviderInterface',
            'constraint' => '^1.0',
            'oneOf' => ['milpa.auth.oauth.google', 'milpa.auth.oauth.apple'],
        ]);

        self::assertSame('milpa.auth.oauth', $r->id);
        self::assertSame('^1.0', $r->constraint);
        self::assertSame(['milpa.auth.oauth.google', 'milpa.auth.oauth.apple'], $r->oneOf);
    }

    public function testCanonicalSuggestsRecordParsesWithFallback(): void
    {
        $s = CapabilitySuggestion::fromArray([
            'id' => 'milpa.audit.logger',
            'interface' => 'Milpa\\Contracts\\AuditLoggerInterface',
            'constraint' => '^1.0',
            'fallback' => 'noop',
        ]);

        self::assertSame('milpa.audit.logger', $s->id);
        self::assertSame('^1.0', $s->constraint);
        self::assertSame('noop', $s->fallback);
    }
}
