<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Fixtures;

use Milpa\Attributes\PluginMetadata;

/**
 * A stand-in plugin class carrying `#[PluginMetadata]`, used to exercise `AttributeLoader::fromClass()`
 * against a real reflected attribute. Its `provides` list deliberately diverges from the OAuthPlugin
 * legacy manifest fixture (drops five interfaces, adds one the manifest never declared) so the drift
 * detector has real added-and-removed drift to find between the declared manifest and the actual code.
 */
#[PluginMetadata(
    version: '2.0.0',
    author: 'Milpa Team',
    site: 'https://example.com',
    name: 'milpa/oauthplugin',
    type: 'Service',
    provides: [
        'Milpa\\OAuth\\Contracts\\GoogleOAuthServiceInterface',
        'Milpa\\OAuth\\Contracts\\TelegramAuthServiceInterface',
        'Milpa\\OAuth\\Contracts\\LinkedInOAuthServiceInterface',
    ],
    requires: [],
    suggests: [],
)]
final class SampleAnnotatedPlugin
{
}
