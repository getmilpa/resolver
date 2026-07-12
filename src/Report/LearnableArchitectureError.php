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

namespace Milpa\Resolver\Report;

/**
 * A structured, teachable diagnosis (spec §12): a machine `code`, a human `message`, the `why`
 * (the concept the failure violated), the `context` that produced it, human-readable `fixes`, and
 * `links` into the Academy. It is built by {@see ErrorCatalog} (the single source of that content)
 * and attached to the report so every block teaches instead of merely failing.
 *
 * Its {@see toArray()} emits the agent shape of spec §20: the `links` become the bilingual `learn`
 * map, and typed `recommendedActions` are derived from the code and context — the same diagnosis an
 * agent can act on without inventing anything. Anti-pattern 4 ("error muerto") forbids a code
 * without why + fix + learn link; the catalog test proves every code carries all three.
 */
final readonly class LearnableArchitectureError
{
    /**
     * Capability/contract/surface ids whose canonical provider package is known, so a missing one can
     * recommend a concrete `install-package` action (grounded in spec §22 and §15.4).
     *
     * @var array<string, string>
     */
    public const KNOWN_PACKAGES = [
        'command.provider' => 'milpa/command',
        'operation.projector' => 'milpa/command',
        'mcp.transport' => 'milpa/mcp-server',
        'tool.registry' => 'milpa/tool-runtime',
        'event.dispatcher' => 'milpa/core',
    ];

    /**
     * @param array<string, mixed> $context
     * @param list<string>         $fixes
     * @param array<string, mixed> $links
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $why,
        public array $context = [],
        public array $fixes = [],
        public array $links = [],
    ) {
    }

    /**
     * Rehydrate an error from its serialized agent shape; `learn` maps back to `links` and the typed
     * `recommendedActions` are dropped (they are re-derived deterministically on the next toArray()).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: is_string($data['code'] ?? null) ? $data['code'] : '',
            message: is_string($data['message'] ?? null) ? $data['message'] : '',
            why: is_string($data['why'] ?? null) ? $data['why'] : '',
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
            fixes: self::stringList($data['fixes'] ?? null),
            links: is_array($data['learn'] ?? null) ? $data['learn'] : [],
        );
    }

    /**
     * Serialize to the agent shape of spec §20: code, message, why, context, human fixes, typed
     * recommendedActions derived from code + context, and the bilingual `learn` links.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'why' => $this->why,
            'context' => $this->context,
            'fixes' => $this->fixes,
            'recommendedActions' => $this->recommendedActions(),
            'learn' => $this->links,
        ];
    }

    /**
     * Derive the typed, machine-actionable recommendations for this code from its context (spec §20):
     * install-package / enable-plugin / disable-feature and the code-specific variants an agent can
     * apply directly.
     *
     * @return list<array<string, mixed>>
     */
    private function recommendedActions(): array
    {
        $id = $this->contextString('id');
        $surface = $this->contextString('surface');
        $constraint = $this->contextString('constraint');
        $package = $id !== '' ? (self::KNOWN_PACKAGES[$id] ?? null) : null;

        $actions = [];
        $install = static function () use (&$actions, $package): void {
            if ($package !== null) {
                $actions[] = ['type' => 'install-package', 'package' => $package];
            }
        };

        switch ($this->code) {
            case 'MILPA_CAPABILITY_MISSING':
            case 'MILPA_SUGGESTED_CAPABILITY_MISSING':
                $install();
                if ($this->code === 'MILPA_CAPABILITY_MISSING') {
                    $actions[] = ['type' => 'enable-plugin', 'capability' => $id];
                    $actions[] = ['type' => 'disable-feature', 'feature' => $id];
                } else {
                    $actions[] = ['type' => 'accept-risk', 'code' => 'MILPA_SUGGESTED_CAPABILITY_MISSING'];
                }
                break;

            case 'MILPA_CONTRACT_MISSING':
                $install();
                $actions[] = ['type' => 'enable-plugin', 'contract' => $id];
                break;

            case 'MILPA_CONTRACT_VERSION_UNSUPPORTED':
                if ($package !== null) {
                    $actions[] = ['type' => 'upgrade-package', 'package' => $package, 'constraint' => $constraint];
                }
                $actions[] = ['type' => 'adjust-constraint', 'contract' => $id, 'constraint' => $constraint];
                break;

            case 'MILPA_CAPABILITY_VERSION_UNSUPPORTED':
                if ($package !== null) {
                    $actions[] = ['type' => 'upgrade-package', 'package' => $package, 'constraint' => $constraint];
                }
                $actions[] = ['type' => 'adjust-constraint', 'capability' => $id, 'constraint' => $constraint];
                break;

            case 'MILPA_CAPABILITY_CONFLICT':
                $actions[] = ['type' => 'choose-provider', 'capability' => $id, 'candidates' => $this->contextList('providedBy')];
                break;

            case 'MILPA_SURFACE_REQUIREMENT_UNMET':
                $install();
                $actions[] = ['type' => 'disable-surface', 'surface' => $surface];
                break;

            case 'MILPA_SURFACE_NOT_ENABLED':
                $actions[] = ['type' => 'enable-surface', 'surface' => $surface];
                break;

            case 'MILPA_ADAPTER_MISSING':
                $install();
                $actions[] = ['type' => 'enable-plugin', 'adapter' => $id];
                break;

            case 'MILPA_LEGACY_CONTRACT_ACTIVE':
            case 'MILPA_DEPRECATED_CONTRACT_USED':
                $actions[] = ['type' => 'migrate-contract', 'contract' => $id];
                break;

            case 'MILPA_LEGACY_NOT_ALLOWED':
                $actions[] = ['type' => 'allow-legacy-contract', 'contract' => $id];
                $actions[] = ['type' => 'migrate-contract', 'contract' => $id];
                break;

            case 'MILPA_HOST_PROFILE_OUTDATED':
                $host = $this->contextString('hostProfile');
                $actions[] = $host !== ''
                    ? ['type' => 'update-host-profile', 'hostProfile' => $host]
                    : ['type' => 'update-host-profile'];
                break;

            case 'MILPA_ARCHITECTURE_GRAPH_BLOCKED':
                $actions[] = ['type' => 'review-blocking-errors'];
                break;

            case 'MILPA_BOOTABLE_WITH_WARNINGS':
                $actions[] = ['type' => 'review-warnings'];
                break;

            case 'MILPA_RISK_EXPIRY_UNEVALUATED':
                $actions[] = ['type' => 'set-evaluated-at'];
                $actions[] = ['type' => 'remove-risk-expiry', 'code' => $id];
                break;
        }

        return $actions;
    }

    private function contextString(string $key): string
    {
        $value = $this->context[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<string>
     */
    private function contextList(string $key): array
    {
        return self::stringList($this->context[$key] ?? null);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
