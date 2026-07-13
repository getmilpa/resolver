<?php

declare(strict_types=1);

namespace Milpa\Resolver\Tests\Advisor;

use Milpa\Resolver\Advisor\MigrationAdvisor;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\DriftDetector;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\ContractManifest;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Manifest\VersionManifest;
use Milpa\Resolver\Report\ErrorCatalog;
use Milpa\Resolver\Report\LearnableArchitectureError;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use PHPUnit\Framework\TestCase;

/**
 * Freezes the WHOLE serialized {@see \Milpa\Resolver\Advisor\MigrationPlan} as a public contract —
 * the drift-lock of the plan, three ways, exactly like the report's own
 * {@see \Milpa\Resolver\Tests\Report\ReportShapeContractsTest}:
 *
 *  1. SCHEMA — every list has one closed schema (exact ordered key set, a type per field, closed
 *     value domains for `detected[].kind` and the catalog-bound `code`), the top-level key ORDER is
 *     frozen ({@see topLevelKeys()}), `status` is the report's closed four-value domain, and
 *     `summary` is a frozen two-int map that must AGREE with the packages it summarizes.
 *  2. CORPUS — every example is GENERATED end to end (real inputs → GraphResolver->resolve() →
 *     MigrationAdvisor->advise()), never a hand-written plan fixture, and together the cases
 *     exercise every detected kind, the optional `constraint` both present and absent, and every
 *     status.
 *  3. MUTATION PROBES — an extra undocumented key, a `kind` outside its domain, a step without its
 *     `action`, an `academy` pair without `en`, a reordered shell, and an out-of-domain status all
 *     fail — and further tests assert the README "Migration plan shape" tables list exactly these
 *     fields, types, and domains (the same two-way parser parity the report tables carry).
 */
final class MigrationPlanShapeContractsTest extends TestCase
{
    /**
     * Fields that may be ABSENT from their entry (the plan's one exception, mirroring the report's
     * `errors[].context` precedent): `recommended[].constraint` is omitted — never null — when the
     * detection carried no version constraint to recommend against.
     *
     * @var array<string, list<string>>
     */
    private const OPTIONAL = ['packages[].recommended' => ['constraint']];

    /**
     * The frozen schema per plan list: `list => [field => [type, domain?]]`, in emission order.
     * Type tokens are the report harness's plus `int`; the optional second element closes the value
     * domain (a literal list, or the sentinel `catalog` for {@see ErrorCatalog} codes).
     *
     * @return array<string, array<string, array{0: string, 1?: list<string>|string}>>
     */
    private static function schema(): array
    {
        return [
            'packages' => [
                'package' => ['non-empty-string'],
                'detected' => ['array[]'],
                'recommended' => ['array[]'],
                'steps' => ['array[]'],
                'compatibility' => ['non-empty-string'],
                'academy' => ['array[]'],
            ],
            'packages[].detected' => [
                'kind' => ['non-empty-string', ['legacy-contract', 'legacy-capability', 'manifest-drift', 'missing', 'conflict']],
                'id' => ['non-empty-string'],
                'code' => ['non-empty-string', 'catalog'],
                'detail' => ['non-empty-string'],
            ],
            'packages[].recommended' => [
                'id' => ['non-empty-string'],
                'to' => ['non-empty-string'],
                'constraint' => ['non-empty-string'],
            ],
            'packages[].steps' => [
                'n' => ['int'],
                'action' => ['non-empty-string'],
            ],
            'packages[].academy' => [
                'es' => ['non-empty-string'],
                'en' => ['non-empty-string'],
            ],
        ];
    }

    /**
     * The frozen top-level key ORDER of a serialized plan — exactly what
     * {@see \Milpa\Resolver\Advisor\MigrationPlan::toArray()} emits: the verdict first, the
     * per-package work in the middle, the census last.
     *
     * @return list<string>
     */
    private static function topLevelKeys(): array
    {
        return ['status', 'packages', 'summary'];
    }

    /**
     * The plan's `status` domain is the REPORT's, verbatim — the four verdicts of spec §5. The plan
     * never invents a fifth state; it carries the report's word.
     *
     * @return list<string>
     */
    private static function statusDomain(): array
    {
        return ['valid', 'bootable_with_warnings', 'blocked', 'legacy_compatible'];
    }

    /**
     * The frozen `summary` map: exactly these keys, in this order, both ints.
     *
     * @return array<string, string>
     */
    private static function summarySchema(): array
    {
        return ['packages' => 'int', 'actions' => 'int'];
    }

    /**
     * Every list carries at least one engine-generated entry, so no schema is asserted over thin air.
     */
    public function testTheCorpusExercisesEveryList(): void
    {
        foreach (array_keys(self::schema()) as $list) {
            self::assertNotSame([], $this->entriesFor($list), "corpus produced no {$list}[] entries");
        }
    }

    /**
     * The corpus exercises every `detected[].kind` of the closed domain, so the schema is frozen
     * against the full branch space — every raw material the advisor consumes.
     */
    public function testTheCorpusCoversEveryDetectedKind(): void
    {
        $seen = array_column($this->entriesFor('packages[].detected'), 'kind');
        foreach (['legacy-contract', 'legacy-capability', 'manifest-drift', 'missing', 'conflict'] as $kind) {
            self::assertContains($kind, $seen, "corpus never produced detected[].kind = {$kind}");
        }
    }

    /**
     * The optional `constraint` is proven genuinely optional: the corpus exercises a recommended
     * entry WITH it (a missing requirement's constraint) and one WITHOUT it (a migration-hint
     * target).
     */
    public function testTheCorpusExercisesTheOptionalConstraintBothWays(): void
    {
        $with = 0;
        $without = 0;
        foreach ($this->entriesFor('packages[].recommended') as $entry) {
            array_key_exists('constraint', $entry) ? ++$with : ++$without;
        }

        self::assertGreaterThan(0, $with, 'corpus never produced a recommended[] WITH a constraint');
        self::assertGreaterThan(0, $without, 'corpus never produced a recommended[] WITHOUT a constraint');
    }

    /**
     * Headline contract: every entry of every list has exactly the frozen key set, in order —
     * optional fields may be absent, but a present key set always reads as the frozen subsequence.
     */
    public function testEveryEntryHasExactlyTheFrozenKeySetInOrder(): void
    {
        foreach (array_keys(self::schema()) as $list) {
            foreach ($this->entriesFor($list) as $i => $entry) {
                self::assertSame([], $this->schemaViolations($list, $entry), "{$list}[{$i}] violated its schema");
            }
        }
    }

    /**
     * Mutation check, direction 1 — an EXTRA undocumented key makes the entry fail its schema, on
     * every list.
     */
    public function testSchemaRejectsAnExtraUndocumentedKey(): void
    {
        foreach (array_keys(self::schema()) as $list) {
            $entry = $this->entriesFor($list)[0];
            $mutated = $entry + ['undocumentedField' => 'sneaky'];

            self::assertNotSame([], $this->schemaViolations($list, $mutated), "{$list}[] accepted an extra key");
        }
    }

    /**
     * Mutation check, direction 2 — a MISSING required key makes the entry fail its schema.
     */
    public function testSchemaRejectsAMissingRequiredKey(): void
    {
        foreach (array_keys(self::schema()) as $list) {
            $entry = $this->entriesFor($list)[0];
            $firstKey = array_key_first($entry);
            $mutated = $entry;
            unset($mutated[$firstKey]);

            self::assertNotSame([], $this->schemaViolations($list, $mutated), "{$list}[] accepted a missing key");
        }
    }

    /**
     * The named mutation probes of the brief, all four: a `kind` outside its closed domain, a step
     * without its `action`, an `academy` pair without `en`, and (above) the extra undocumented key.
     */
    public function testTheNamedMutationProbesAllFail(): void
    {
        $detected = $this->entriesFor('packages[].detected')[0];
        $detected['kind'] = 'not-a-real-kind';
        self::assertNotSame([], $this->schemaViolations('packages[].detected', $detected), 'detected[] accepted an out-of-domain kind');

        $step = $this->entriesFor('packages[].steps')[0];
        unset($step['action']);
        self::assertNotSame([], $this->schemaViolations('packages[].steps', $step), 'steps[] accepted a step without its action');

        $academy = $this->entriesFor('packages[].academy')[0];
        unset($academy['en']);
        self::assertNotSame([], $this->schemaViolations('packages[].academy', $academy), 'academy[] accepted a monolingual pair');
    }

    /**
     * Mutation check, type direction — a wrongly-typed value fails: a string `n` on a step and a
     * nested array where a scalar belongs.
     */
    public function testSchemaRejectsAWronglyTypedValue(): void
    {
        $step = $this->entriesFor('packages[].steps')[0];
        $step['n'] = '1';
        self::assertNotSame([], $this->schemaViolations('packages[].steps', $step), 'steps[] accepted a string n');

        $package = $this->entriesFor('packages')[0];
        $package['package'] = ['unexpected' => 'array'];
        self::assertNotSame([], $this->schemaViolations('packages', $package), 'packages[] accepted a non-string package');
    }

    // --- the plan shell: top-level order, status domain, summary agreement --------------------------

    /**
     * Every corpus plan carries EXACTLY the frozen top-level keys, in order, with a status inside
     * the closed domain and a summary that AGREES with the packages it summarizes (`packages` =
     * the package count, `actions` = the total step count).
     */
    public function testEveryPlanHasTheFrozenShell(): void
    {
        foreach ($this->corpus() as $i => $plan) {
            self::assertSame([], $this->shellViolations($plan), "plan[{$i}] shell drifted");
        }
    }

    /**
     * The corpus exercises every status of the closed domain — the plan carries all four report
     * verdicts, including the two that yield an EMPTY (but visible) plan.
     */
    public function testTheCorpusExercisesEveryStatus(): void
    {
        $seen = array_column($this->corpus(), 'status');
        foreach (self::statusDomain() as $status) {
            self::assertContains($status, $seen, "corpus never produced status = {$status}");
        }
    }

    /**
     * Mutation checks on the shell: an out-of-domain status, REORDERED top-level keys (content
     * intact), a summary whose counts disagree with the packages, and an extra summary key all fail.
     */
    public function testShellRejectsAMutatedShell(): void
    {
        $plan = $this->firstNonEmptyPlan();

        $status = $plan;
        $status['status'] = 'exploded';
        self::assertNotSame([], $this->shellViolations($status), 'shell accepted an out-of-domain status');

        $reordered = ['summary' => $plan['summary']] + $plan;
        self::assertNotSame([], $this->shellViolations($reordered), 'shell accepted reordered top-level keys');

        $disagreeing = $plan;
        self::assertIsArray($disagreeing['summary']);
        $disagreeing['summary']['actions'] = 999;
        self::assertNotSame([], $this->shellViolations($disagreeing), 'shell accepted a summary that disagrees with its packages');

        $extra = $plan;
        self::assertIsArray($extra['summary']);
        $extra['summary']['sneaky'] = 1;
        self::assertNotSame([], $this->shellViolations($extra), 'shell accepted an extra summary key');
    }

    /**
     * The advisor's two structural promises, proven over the whole corpus: steps are numbered 1..n
     * with no gaps and the LAST step of every package is always the re-inspect; and an empty plan is
     * VISIBLE — `{packages: [], summary: {packages: 0, actions: 0}}` — never a null.
     */
    public function testStepNumberingReInspectAndTheVisibleEmptyPlanHoldCorpusWide(): void
    {
        $emptyPlans = 0;
        foreach ($this->corpus() as $plan) {
            self::assertIsArray($plan['packages']);
            if ($plan['packages'] === []) {
                ++$emptyPlans;
                self::assertSame(['packages' => 0, 'actions' => 0], $plan['summary']);

                continue;
            }
            foreach ($plan['packages'] as $package) {
                self::assertIsArray($package);
                self::assertIsArray($package['steps']);
                self::assertNotSame([], $package['steps'], 'an actionable package always has steps');
                foreach (array_values($package['steps']) as $i => $step) {
                    self::assertIsArray($step);
                    self::assertSame($i + 1, $step['n'], 'steps are numbered 1..n with no gaps');
                }
                $last = $package['steps'][count($package['steps']) - 1];
                self::assertIsArray($last);
                self::assertSame('Run php coa coa:inspect architecture again.', $last['action']);
            }
        }

        self::assertGreaterThan(0, $emptyPlans, 'corpus never produced the visible empty plan');
    }

    /**
     * The whole corpus serializes deterministically: advising the same materialized inputs twice
     * yields byte-identical plans.
     */
    public function testTheWholeCorpusIsByteDeterministic(): void
    {
        self::assertSame(
            json_encode($this->corpus()),
            json_encode($this->corpus()),
            'the corpus did not serialize byte-identically across two advise() passes',
        );
    }

    /**
     * The README "Migration plan shape" tables list EXACTLY the frozen fields of each list, in
     * order, with each Type cell normalizing to the exact `[type, domain?]` spec this harness
     * enforces AND the exact optional-field set — the public contract cannot drift from this test's
     * schema in name, type, or optionality.
     */
    public function testReadmeTablesMatchTheFrozenSchemaExactly(): void
    {
        foreach (self::schema() as $list => $fields) {
            $documented = $this->readmeTable($list . '[]');
            self::assertSame(
                array_keys($fields),
                array_keys($documented),
                "README {$list}[] table fields drifted from the schema",
            );

            $optional = [];
            foreach ($fields as $field => $spec) {
                [$cellSpec, $cellOptional] = self::readmeTypeSpec($documented[$field]);
                self::assertSame($spec, $cellSpec, "README {$list}[].{$field} type drifted from the schema");
                if ($cellOptional) {
                    $optional[] = $field;
                }
            }
            self::assertSame(self::OPTIONAL[$list] ?? [], $optional, "README {$list}[] optional fields drifted");
        }
    }

    /**
     * The README documents the non-list contracts exactly as this harness freezes them: the
     * top-level key order and the summary map — the third leg of the drift-lock.
     */
    public function testReadmeDocumentsTheNonListContractsExactly(): void
    {
        self::assertSame(self::topLevelKeys(), array_keys($this->readmeTable('MigrationPlan')), 'README top-level key order drifted');
        self::assertSame(array_keys(self::summarySchema()), array_keys($this->readmeTable('summary')), 'README summary fields drifted');
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * Validate one entry against its list schema: no unknown keys, every non-optional key present,
     * present keys in the frozen relative order, and every value matching its type and (where
     * closed) its domain.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private function schemaViolations(string $list, array $entry): array
    {
        $schema = self::schema()[$list];
        $expected = array_keys($schema);
        $optional = self::OPTIONAL[$list] ?? [];
        $actual = array_keys($entry);

        foreach (array_diff($actual, $expected) as $unknown) {
            return [sprintf('key `%s` is not in the frozen set', (string) $unknown)];
        }
        foreach (array_diff($expected, $actual, $optional) as $missing) {
            return [sprintf('required key `%s` is missing', $missing)];
        }
        if ($actual !== array_values(array_intersect($expected, $actual))) {
            return [sprintf('keys [%s] are out of the frozen order', implode(',', $actual))];
        }

        $violations = [];
        foreach ($entry as $field => $value) {
            $type = $schema[$field][0];
            $domain = $schema[$field][1] ?? null;

            if (!$this->typeOk($type, $value)) {
                $violations[] = sprintf('%s: expected type %s, got %s', $field, $type, get_debug_type($value));

                continue;
            }
            if ($domain !== null && is_string($value)) {
                $allowed = $domain === 'catalog' ? ErrorCatalog::codes() : $domain;
                if (!in_array($value, $allowed, true)) {
                    $violations[] = sprintf('%s: value "%s" is out of domain', $field, $value);
                }
            }
        }

        return $violations;
    }

    /**
     * Validate a serialized plan's SHELL: exact top-level key order, closed status domain, and the
     * frozen summary map whose counts AGREE with the packages they summarize.
     *
     * @param array<string, mixed> $plan
     *
     * @return list<string>
     */
    private function shellViolations(array $plan): array
    {
        if (array_keys($plan) !== self::topLevelKeys()) {
            return [sprintf('top-level keys [%s] != frozen order [%s]', implode(',', array_keys($plan)), implode(',', self::topLevelKeys()))];
        }

        $violations = [];
        if (!is_string($plan['status']) || !in_array($plan['status'], self::statusDomain(), true)) {
            $violations[] = sprintf('status: value "%s" is out of domain', is_scalar($plan['status']) ? (string) $plan['status'] : get_debug_type($plan['status']));
        }

        if (!is_array($plan['packages']) || !is_array($plan['summary'])) {
            $violations[] = 'packages/summary: expected arrays';

            return $violations;
        }
        if (array_keys($plan['summary']) !== array_keys(self::summarySchema())) {
            $violations[] = sprintf('summary keys [%s] != frozen [%s]', implode(',', array_keys($plan['summary'])), implode(',', array_keys(self::summarySchema())));

            return $violations;
        }

        $steps = 0;
        foreach ($plan['packages'] as $package) {
            $list = is_array($package) && is_array($package['steps'] ?? null) ? $package['steps'] : [];
            $steps += count($list);
        }
        if ($plan['summary']['packages'] !== count($plan['packages'])) {
            $violations[] = 'summary.packages disagrees with the package count';
        }
        if ($plan['summary']['actions'] !== $steps) {
            $violations[] = 'summary.actions disagrees with the total step count';
        }

        return $violations;
    }

    private function typeOk(string $type, mixed $value): bool
    {
        return match ($type) {
            'non-empty-string' => is_string($value) && $value !== '',
            'int' => is_int($value),
            'array[]' => $this->isListOfArrays($value),
            default => false,
        };
    }

    private function isListOfArrays(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collect every engine-generated entry for one plan list across the whole corpus — nested lists
     * (`packages[].detected` etc.) are gathered from inside each package entry.
     *
     * @return list<array<string, mixed>>
     */
    private function entriesFor(string $list): array
    {
        $out = [];
        foreach ($this->corpus() as $plan) {
            self::assertIsArray($plan['packages']);
            foreach ($plan['packages'] as $package) {
                self::assertIsArray($package);
                if ($list === 'packages') {
                    /** @var array<string, mixed> $package */
                    $out[] = $package;

                    continue;
                }
                $nested = substr($list, strlen('packages[].'));
                self::assertIsArray($package[$nested]);
                foreach ($package[$nested] as $entry) {
                    self::assertIsArray($entry);
                    /** @var array<string, mixed> $entry */
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * The first corpus plan that carries at least one package — the mutation probes' raw material.
     *
     * @return array<string, mixed>
     */
    private function firstNonEmptyPlan(): array
    {
        foreach ($this->corpus() as $plan) {
            if ($plan['packages'] !== []) {
                return $plan;
            }
        }

        self::fail('corpus produced no plan with packages');
    }

    /**
     * The engine-generated corpus: each case is resolved by the REAL engine and advised by the REAL
     * advisor — inputs → resolve() → advise() — never a hand-written plan fixture. Together the
     * cases exercise every detected kind, the optional constraint both ways, and all four statuses.
     *
     * @return list<array<string, mixed>>
     */
    private function corpus(): array
    {
        $resolver = new GraphResolver();
        $advisor = new MigrationAdvisor();

        $plans = [];
        foreach ($this->cases() as $case) {
            $report = $resolver->resolve($case['input']);
            $plans[] = $advisor->advise($report, $case['drift'], $case['input']->hostProfile)->toArray();
        }

        return $plans;
    }

    /**
     * The materialized inputs behind the corpus, each with its caller-built drift errors where the
     * case needs them.
     *
     * @return list<array{input: ResolutionInput, drift: list<LearnableArchitectureError>}>
     */
    private function cases(): array
    {
        $legacyManifest = new VersionManifest(
            package: 'legacy/command-host',
            version: '0.0.1',
            contracts: ['implements' => ['command.host@0.0'], 'requires' => []],
            capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
            metadata: ['shape' => 'legacy-contracts'],
        );

        $detector = new DriftDetector();
        $driftedActual = new VersionManifest(
            package: 'legacy/command-host',
            version: '0.0.2',
            contracts: ['implements' => ['command.host@0.0']],
            capabilities: ['provides' => [], 'requires' => [], 'suggests' => []],
        );
        $drift = $detector->toLearnableErrors($detector->diff($legacyManifest, $driftedActual), 'legacy/command-host');

        return [
            // legacy contract, wildcard allowance, canonical target declared → legacy-contract kind,
            // recommended WITHOUT constraint (a hint target), status legacy_compatible.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredContracts: ['command.host@0.0'], allowedLegacyContracts: ['*']),
                    versionManifests: [$legacyManifest],
                    contractManifests: [new ContractManifest(id: 'command.host', version: '0.1')],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
            // The same legacy path PLUS caller-built drift on the same package → manifest-drift kind
            // merged into the same package entry.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredContracts: ['command.host@0.0'], allowedLegacyContracts: ['*']),
                    versionManifests: [$legacyManifest],
                    contractManifests: [new ContractManifest(id: 'command.host', version: '0.1')],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => $drift,
            ],
            // A legacy-shaped manifest providing a required capability → legacy-capability kind.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['legacy.cap'], allowedLegacyContracts: ['*']),
                    versionManifests: [
                        new VersionManifest(
                            package: 'legacy/cap-host',
                            version: '0.0.1',
                            contracts: ['implements' => []],
                            capabilities: ['provides' => [['id' => 'legacy.cap', 'interface' => 'L', 'contractVersion' => '0.0.1']]],
                            metadata: ['shape' => 'legacy-contracts'],
                        ),
                    ],
                    contractManifests: [],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
            // A required capability nobody provides → missing kind, recommended WITH the constraint,
            // status blocked.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['command.provider']),
                    versionManifests: [],
                    contractManifests: [],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
            // Two exclusive providers of the same capability → conflict kind, grouped under the host.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['persistence.store']),
                    versionManifests: [],
                    contractManifests: [],
                    capabilityProvisions: [
                        new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\MysqlStore', exclusive: true),
                        new CapabilityProvision('persistence.store', 'Store', '0.1.0', service: 'App\\SqliteStore', exclusive: true),
                    ],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
            // A clean graph → status valid, the visible empty plan.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07', requiredCapabilities: ['command.provider']),
                    versionManifests: [
                        new VersionManifest(
                            package: 'milpa/command',
                            version: '0.1.0',
                            contracts: ['implements' => []],
                            capabilities: ['provides' => [['id' => 'command.provider', 'interface' => 'Cmd', 'contractVersion' => '0.1.0']]],
                        ),
                    ],
                    contractManifests: [],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
            // A suggested capability with no provider → bootable_with_warnings, still an empty plan:
            // warnings are not migration work.
            [
                'input' => new ResolutionInput(
                    hostProfile: new HostProfile('crm-host', '2026.07'),
                    versionManifests: [
                        new VersionManifest(
                            package: 'milpa/audit',
                            version: '0.1.0',
                            contracts: ['implements' => []],
                            capabilities: ['provides' => [], 'suggests' => ['audit.sink']],
                        ),
                    ],
                    contractManifests: [],
                    capabilityProvisions: [],
                    capabilityRequirements: [],
                ),
                'drift' => [],
            ],
        ];
    }

    /**
     * Parse one table of the README "Migration plan shape" section: the `#### `heading`` subsection
     * whose table's first two columns carry the frozen field names and Type cells, in order — the
     * same parser conventions the report harness family uses, scoped to this section's own H2.
     *
     * @return array<string, string>
     */
    private function readmeTable(string $heading): array
    {
        $md = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($md, 'README.md is unreadable');

        $start = strpos($md, '## Migration plan shape');
        self::assertNotFalse($start, 'README has no "## Migration plan shape" section');
        $section = substr($md, $start);
        $nextH2 = strpos($section, "\n## ", 4);
        if ($nextH2 !== false) {
            $section = substr($section, 0, $nextH2);
        }

        $matched = preg_match(
            '/####\s+`' . preg_quote($heading, '/') . '`(.*?)(?=\n####|\z)/s',
            $section,
            $block,
        );
        self::assertSame(1, $matched, "README has no `{$heading}` plan section");

        preg_match_all('/^\|\s*`([^`]+)`\s*\|\s*((?:[^|\\\\]|\\\\.)*?)\s*\|/m', $block[1], $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $row) {
            $out[$row[1]] = $row[2];
        }

        return $out;
    }

    /**
     * Translate one README Type cell into the schema's `[type, domain?]` spec plus its optionality:
     * a lone backticked token is the type itself; `<type> (catalog)` marks the catalog-bound
     * domain; `<type> (optional)` marks a field that may be absent; and two or more literal values
     * separated by `\|` are the closed-domain notation.
     *
     * @return array{0: array{0: string, 1?: list<string>|string}, 1: bool}
     */
    private static function readmeTypeSpec(string $cell): array
    {
        $tokens = array_map(
            static fn (string $token): string => trim($token, '` '),
            preg_split('/\s*\\\\\|\s*/', trim($cell)) ?: [],
        );

        if (count($tokens) > 1) {
            return [['non-empty-string', $tokens], false];
        }

        $token = $tokens[0];
        $optional = false;
        if (str_ends_with($token, ' (optional)')) {
            $token = substr($token, 0, -strlen(' (optional)'));
            $optional = true;
        }
        if (str_ends_with($token, ' (catalog)')) {
            return [[substr($token, 0, -strlen(' (catalog)')), 'catalog'], $optional];
        }

        return [[$token], $optional];
    }
}
