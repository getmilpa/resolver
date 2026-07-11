<?php

/**
 * This file is part of Milpa Resolver — the architecture resolver for the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/resolver
 */

declare(strict_types=1);

/**
 * Generates the static API reference site for milpa/resolver.
 *
 * Thin entry over the family docs generator (`Milpa\Docs\SiteGenerator`,
 * shipped inside the milpa/core dist and pulled in here as a dev-only tool):
 * reflects over `src/`, renders one `mui-api`-styled page per public type
 * wrapped in the `mui-docs` shell, a nav, a per-page table of contents, and
 * `index.html`.
 *
 * Usage: php tools/gen-docs.php --out <dir> [--css-base <url>] [--version <v>]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Required-value long options (`name:`, not `name::`) so `--css-base /ds` with a
// space is captured; optional (`::`) only binds `--css-base=/ds`. getopt yields
// `false` for a flag it can't bind a value to, so guard with is_string, not `??`
// (which only rescues null) before falling back to the default.
$opts = getopt('', ['out:', 'css-base:', 'version:']);
$out = is_string($opts['out'] ?? null) ? $opts['out'] : 'build/docs';
$cssBase = is_string($opts['css-base'] ?? null) ? $opts['css-base'] : 'https://cdn.jsdelivr.net/npm/@milpa/design@0.8.0';

// Version shown in the docs chrome (topbar badge, title, footer). Prefer an
// explicit --version; otherwise read the release-please manifest (present in
// the published repo); fall back to "dev" for local builds.
$version = is_string($opts['version'] ?? null) ? $opts['version'] : null;
if ($version === null) {
    $manifest = dirname(__DIR__) . '/.github/.release-please-manifest.json';
    $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $version = is_array($data) && is_string($data['.'] ?? null) ? $data['.'] : 'dev';
}

// Branding for this package's docs site — see Milpa\Docs\SiteConfig (milpa/core).
$config = new Milpa\Docs\SiteConfig(
    brand: 'Milpa Resolver',
    nsPrefix: 'Milpa\\Resolver\\',
    installCommand: 'composer require milpa/resolver',
    repoUrl: 'https://github.com/getmilpa/resolver',
    pagesUrl: 'https://getmilpa.github.io/resolver/',
    heroParagraph: 'The <strong>architecture resolver</strong> of Milpa: pure logic that resolves '
        . 'contracts, versioned capabilities, and surfaces against a host profile and returns a '
        . '<code>ResolutionReport</code> classifying the graph as <code>valid</code>, '
        . '<code>bootable_with_warnings</code>, <code>blocked</code>, or <code>legacy_compatible</code>.',
    utmContent: 'resolver',
);

$count = (new Milpa\Docs\SiteGenerator(dirname(__DIR__) . '/src', $out, $cssBase, $version, $config))->generate();

echo "generated {$count} page(s) to {$out} (v{$version}, css-base: {$cssBase})\n";
exit(0);
