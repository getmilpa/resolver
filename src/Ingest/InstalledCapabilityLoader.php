<?php

/**
 * This file is part of milpa/resolver — the architecture graph resolver of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/resolver
 */

declare(strict_types=1);

namespace Milpa\Resolver\Ingest;

use Milpa\ValueObjects\Capability\CapabilityProvision;

/**
 * What the INSTALLED DISTRIBUTIONS provide, as graph input — the crossing of the two graphs (P17.4).
 *
 * ── WHAT WAS DISCONNECTED ───────────────────────────────────────────────────────────────────────
 *
 * `ResolutionInput` has taken `capabilityProvisions` since it existed, and every caller passed `[]`.
 * With that, the architecture graph only ever saw PLUGINS. An app could depend on a distribution —in
 * the host this was written for, nine plugins import `Milpa\ToolRuntime\`— and the graph had no way
 * of knowing whether the package was installed. The capability→package table held the answer and
 * nobody asked it the question, so its `install-package` recommendation could never fire.
 *
 * Two graphs that never meet is also why the vocabularies drifted for months without anyone noticing:
 * nothing ever compared them.
 *
 * ── THE PACKAGE IS THE AUTHORITY, AND `installed.json` IS ITS RECORD ────────────────────────────
 *
 * A distribution declares its own capability in `extra.milpa.capability`, and Composer copies that
 * declaration verbatim into `vendor/composer/installed.json` at install time. Reading it there —and
 * not by probing for classes— means a package that is present but broken still reads as present, and
 * a package that is absent reads as absent, with no guessing in between.
 *
 * ── AN ID WITHOUT A DECLARED CONTRACT IS SKIPPED, NOT INVENTED ──────────────────────────────────
 *
 * A provision needs the contract it fulfils, and only the package can say which one: it comes from
 * `extra.milpa.capability.contracts`, an `id → FQCN` map. A package name is not an interface, so an
 * id with no declared contract is **left out**. The result is a graph that knows less — which is
 * honest — instead of one that asserts more than anybody declared.
 */
final class InstalledCapabilityLoader
{
    /**
     * What the installed distributions declare they provide, as graph input.
     *
     * @param string $vendor the vendor root to read `composer/installed.json` from
     *
     * @return list<CapabilityProvision> empty when there is no `installed.json`: "I could not know"
     *                                   is not the same as "nothing is installed", and the caller
     *                                   that needs the difference asks the filesystem, not this
     */
    public static function fromVendor(string $vendor): array
    {
        $archivo = rtrim($vendor, '/\\') . '/composer/installed.json';
        if (!is_file($archivo)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($archivo), true);
        if (!\is_array($json)) {
            return [];
        }

        // Composer 2 wraps the list in `packages`; Composer 1 wrote the bare array. Both are read
        // because a lock file older than the tool that reads it is the normal case, not the edge one.
        $paquetes = \is_array($json['packages'] ?? null) ? $json['packages'] : $json;

        $provisiones = [];
        foreach ($paquetes as $paquete) {
            if (!\is_array($paquete) || !\is_string($paquete['name'] ?? null)) {
                continue;
            }
            $cap = $paquete['extra']['milpa']['capability'] ?? null;
            if (!\is_array($cap)) {
                continue;
            }
            $contratos = \is_array($cap['contracts'] ?? null) ? $cap['contracts'] : [];
            foreach ($cap['provides'] ?? [] as $id) {
                if (!\is_string($id) || !\is_string($contratos[$id] ?? null) || $contratos[$id] === '') {
                    continue;
                }
                $provisiones[] = new CapabilityProvision(
                    id: $id,
                    interface: $contratos[$id],
                    // A distribution's contract version is the PACKAGE's, and Composer knows it —
                    // this file does not. `null` means nobody declared one, which is the true state.
                    contractVersion: null,
                    service: $paquete['name'],
                );
            }
        }

        return $provisiones;
    }
}
