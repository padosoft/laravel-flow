# Macro B — Graph Definition & Persistence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make flow definitions first-class, serializable, versioned data: graph value objects with structural+semantic validation, a canonical JSON schema v1 with stable checksums, a versioned `flow_definitions` store with draft/published/archived lifecycle, optional HMAC signing, import/export (including the ModelsGenerator Flow-v2 shape), an additive v1→graph compilation path, and run→definition-version pinning. Plus the Macro-A review follow-ups (property-type compatibility, annotation sweep, duplicate-root diagnostics).

**Architecture:** New `Padosoft\LaravelFlow\Graph\` namespace (pure PHP where possible, standalone-agnostic like `Node\`): `GraphDefinition`/`GraphNode`/`Connection` VOs enforce STRUCTURAL invariants in their constructors (unique/non-empty ids, endpoints exist, no duplicate wires, acyclic via Kahn); `GraphValidator` (needs the `NodeRegistry`) enforces SEMANTIC rules (known types, known ports, `PortType::accepts` compatibility). `GraphSerializer` owns the canonical JSON envelope and sha-256 checksum. Persistence follows the existing repo split: `Contracts\DefinitionRepository` (`@api`) + `Persistence\EloquentDefinitionRepository` (`@internal`) + read DTO. v1 execution is NOT rewired: compilation is an additive `FlowDefinition::toGraphDefinition()` export.

**Tech Stack:** PHP ^8.3, Laravel 13, PHPUnit (suites Unit/Architecture/Contract), Orchestra Testbench (`tests/Unit/Persistence/PersistenceTestCase.php` gives the sqlite `:memory:` convention), PHPStan 2, Pint.

## Global Constraints

- Style learned in Macro A (MANDATORY): `declare(strict_types=1);`, `final` classes, readonly promoted props, snake_case test methods, parens-less `new Foo`, **never** copy `// src/...` path-comment header lines from this plan's code fences into files, run filtered tests with EXPLICIT FILE PATHS (`vendor/bin/phpunit tests/Unit/Graph/FooTest.php`) never `--filter 'A|B'` (PowerShell wrapper mangles it), fix Pint failures with `vendor/bin/pint <files>` then re-run.
- Local runtime Herd PHP 8.5 via PowerShell; gate after every task: `composer quality` (iterate to green; 3 failed attempts → systematic-debugging).
- `src/Graph/` and `src/Node/` stay Illuminate-free (architecture tests enforce `src/Node`; Task 2 extends the sweep to `src/Graph`). Persistence/Console/SP may use Illuminate.
- **Incremental `@api` pinning in the same PR** that introduces `@api` classes: create `tests/Contract/GraphApiContractTest.php` in B-PR1 and EXTEND it in every later PR (annotation check `@api` present / `@internal` absent, plus signature pins where stated).
- Every PR boundary: (1) `composer quality` green; (2) **local Copilot CLI review loop** per `.claude/skills/local-copilot-review/SKILL.md` — prompt MUST open with "STRICTLY READ-ONLY ANALYSIS: you MUST NOT modify, create, or delete ANY file", tee output to a temp file, `git status --short` MUST be clean after every run (revert any CLI edit), iterate to NO_FINDINGS (clean narration across two runs counts if the verdict token is dropped — known CLI quirk); (3) push; (4) PR to the macro branch with Copilot reviewer via the GraphQL `requestReviewsByLogin` fallback (`copilot-pull-request-reviewer[bot]`); (5) wait CI green + review, fix/reply/resolve threads, merge.
- Commits end with blank line + `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`. Do not push mid-task; push only at PR boundaries.
- Steps marked **VERIFY IN CODE FIRST** mean: read the named file/symbol before writing that code; if reality differs from this plan, follow reality and note the delta in your report.

## Branch & PR map

Macro branch: `task/v2b-graph-definition` (from `main`). Subtask branches off it, PRs target it:

| PR | Branch | Tasks |
|---|---|---|
| B-PR1 | `task/v2b-01-graph-vos` | 1, 2, 3 |
| B-PR2 | `task/v2b-02-json-schema` | 4 |
| B-PR3 | `task/v2b-03-definition-repository` | 5, 6 |
| B-PR4 | `task/v2b-04-signing` | 7 |
| B-PR5 | `task/v2b-05-import-export` | 8, 9 |
| B-PR6 | `task/v2b-06-builder-compilation` | 10 |
| B-PR7 | `task/v2b-07-run-version-pinning` | 11, 12 |

**Setup:**

```bash
git checkout main && git pull
git checkout -b task/v2b-graph-definition && git push -u origin task/v2b-graph-definition
git checkout -b task/v2b-01-graph-vos
```

---

### Task 1: Macro-A follow-ups in the Node layer

**Files:**
- Modify: `src/Node/NodeDefinitionFactory.php` (property-type compatibility check; sanctioned-path docblock), `src/Node/NodeDefinition.php` (docblock note), `src/LaravelFlowServiceProvider.php` (duplicate-root dedupe)
- Create: `tests/Architecture/NodeAnnotationSweepTest.php`
- Test: extend `tests/Unit/Node/NodeDefinitionFactoryTest.php`, `tests/Unit/Node/NodeRegistryWiringTest.php`

**Interfaces:**
- Produces: factory rejects `#[Input]`/`#[Output]` whose PHP property type cannot hold the values the declared `PortType` validates. Compatibility matrix (property type → allowed PortTypes): untyped or `mixed` → all; `string` → Text; `int` → Int; `float` → Float, Int (widening: validator lets `int` through on Float ports); `bool` → Bool; `array` → Json; nullable (`?T`) and union types are compatible when ANY branch is compatible; `Any` ports require untyped or `mixed` (a typed property cannot hold "anything"). Violations throw `InvalidNodeDefinitionException` with message `"...property [{class}::\${prop}] type [{type}] cannot hold values of port type [{portType->value}]."`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Node/NodeDefinitionFactoryTest.php`:

```php
    public function test_incompatible_property_type_for_port_is_rejected(): void
    {
        $handler = new #[FlowNode(type: 'compat.bad')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public string $count;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/cannot hold values of port type \[int\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_any_port_requires_untyped_or_mixed_property(): void
    {
        $handler = new #[FlowNode(type: 'compat.any')] class
        {
            #[Input(type: PortType::Any, required: true)]
            public string $data;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/cannot hold values of port type \[any\]/i');
        $this->factory->fromClass($handler::class);
    }

    public function test_compatible_property_types_pass(): void
    {
        $handler = new #[FlowNode(type: 'compat.ok')] class
        {
            #[Input(type: PortType::Float, required: true)]
            public float $ratio;

            #[Input(type: PortType::Json, key: 'meta')]
            public ?array $metadata = null;

            #[Input(type: PortType::Any, key: 'blob')]
            public mixed $blob = null;

            #[Input(type: PortType::Text, key: 'label')]
            public string|int $labelish = '';

            #[Output(type: PortType::Int)]
            public int $total;
        };

        $definition = $this->factory->fromClass($handler::class);

        $this->assertCount(4, $definition->inputs);
        $this->assertCount(1, $definition->outputs);
    }
```

Note: `string|int $labelish` is compatible with Text because the `string` branch matches.

Create `tests/Architecture/NodeAnnotationSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every class under src/Node and src/Graph must carry exactly one of
 * @api / @internal, so a new class cannot slip past the explicit pin
 * lists in the Contract suite unannotated.
 */
final class NodeAnnotationSweepTest extends TestCase
{
    public function test_every_node_and_graph_class_is_annotated(): void
    {
        foreach (['/../../src/Node', '/../../src/Graph'] as $relative) {
            $root = realpath(__DIR__.$relative);

            if ($root === false) {
                continue; // src/Graph appears later in this macro
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                $hasApi = str_contains($source, '@api');
                $hasInternal = str_contains($source, '@internal');

                $this->assertTrue(
                    $hasApi xor $hasInternal,
                    "{$file->getPathname()} must carry exactly one of @api / @internal.",
                );
            }
        }
    }
}
```

Append to `tests/Unit/Node/NodeRegistryWiringTest.php` (duplicate-root dedupe — listing the SAME root twice must not fail boot with a bare duplicate-type error):

```php
    public function test_duplicate_discovery_roots_are_deduplicated(): void
    {
        $root = ['path' => __DIR__.'/../../Fixtures/Nodes', 'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes'];
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [$root, $root]);
        $this->app->forgetInstance(NodeRegistry::class);

        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertTrue($registry->has('test.upper'));
        $this->assertCount(2, $registry->all());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Node/NodeDefinitionFactoryTest.php tests/Unit/Node/NodeRegistryWiringTest.php`
Expected: the three new factory tests FAIL (no exception thrown / definition built), the dedupe test FAILS with `DuplicateNodeTypeException`. Then `vendor/bin/phpunit --testsuite Architecture` — sweep test PASSES already (all Node classes are annotated); that is fine, it is a pin.

- [ ] **Step 3: Implement**

In `src/Node/NodeDefinitionFactory.php`, inside `collectPorts()`, insert AFTER the public/readonly guards and BEFORE the `PortDefinition` construction (for inputs) and after the `isPublic` guard (for outputs; outputs get the same check — the executor reads typed values back):

```php
                $this->assertPropertyTypeCompatible($property, $input->type, $class, 'Input');
```

```php
                $this->assertPropertyTypeCompatible($property, $output->type, $class, 'Output');
```

Add the private method + docblock note. **VERIFY IN CODE FIRST:** exact guard ordering inside `collectPorts()` on main (it evolved through Macro A review rounds); place the call right before each `try { ... new PortDefinition` block.

```php
    /**
     * Definition-time hydratability type check: the PHP property must be
     * able to hold every value its PortType validates at run time, so a
     * mismatch fails fast at boot instead of a TypeError mid-hydration.
     */
    private function assertPropertyTypeCompatible(\ReflectionProperty $property, PortType $portType, string $class, string $attribute): void
    {
        $type = $property->getType();

        if ($type === null) {
            return; // untyped holds anything
        }

        $branches = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($branches as $branch) {
            if (! $branch instanceof \ReflectionNamedType) {
                continue; // intersection types cannot match scalar ports
            }

            $name = $branch->getName();

            if ($name === 'mixed') {
                return;
            }

            $compatible = match ($portType) {
                PortType::Text => $name === 'string',
                PortType::Int => $name === 'int' || $name === 'float',
                PortType::Float => $name === 'float',
                PortType::Bool => $name === 'bool',
                PortType::Json => $name === 'array',
                PortType::Any => false, // only untyped/mixed can hold anything
            };

            if ($compatible) {
                return;
            }
        }

        throw new InvalidNodeDefinitionException(sprintf(
            '%s property [%s::$%s] type [%s] cannot hold values of port type [%s].',
            $attribute,
            $class,
            $property->getName(),
            (string) $type,
            $portType->value,
        ));
    }
```

Nullability note: `?array` produces a `ReflectionNamedType` with `allowsNull()`; the branch name is still `array` → compatible. Explicit `null` inputs never reach hydration (validator strips them), so null-branch compatibility is not required.

In `src/LaravelFlowServiceProvider.php::sanitizeDiscoveryRoots()`, deduplicate identical roots after trimming (before returning): 

```php
        $unique = [];

        foreach ($roots as $root) {
            $unique[$root['path'].'|'.$root['namespace']] = $root;
        }

        return array_values($unique);
```

In `src/Node/NodeDefinition.php` class docblock, append one line: `NodeDefinitionFactory is the sanctioned construction path: this constructor performs no invariant checks of its own.`

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Node/NodeDefinitionFactoryTest.php tests/Unit/Node/NodeRegistryWiringTest.php tests/Architecture/NodeAnnotationSweepTest.php`
Expected: PASS. CAUTION: the full Unit suite must also stay green — existing fixtures (GreetNode `string $name` on Text ✓, UpperNode ✓, BrokenNode Text/string ✓, validator-test handlers `int $count` on Int ✓, `?string $comment` on Text ✓, `mixed $data` on Any ✓, adapter has no attributed props ✓) were checked against the matrix while writing this plan; if any anonymous test class violates the new rule, fix the TEST class property type, never weaken the matrix.

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — green.

```bash
git add src tests
git commit -m "feat(node): definition-time property-type compatibility and annotation sweep

Macro-A review follow-ups: hydratability type check in the factory,
exactly-one-annotation sweep test, duplicate discovery roots deduped,
sanctioned construction path documented."
```

---

### Task 2: GraphNode + Connection VOs

**Files:**
- Create: `src/Graph/GraphNode.php`, `src/Graph/Connection.php`, `src/Graph/Exceptions/InvalidGraphException.php`
- Test: `tests/Unit/Graph/GraphNodeTest.php`, `tests/Unit/Graph/ConnectionTest.php`

**Interfaces:**
- Produces: `GraphNode(string $id, string $type, array $config = [], ?array $position = null)` readonly `@api` — rejects blank id/type; `$position` when non-null must have numeric `x` and `y` keys. `Connection(string $sourceNodeId, string $sourcePortKey, string $targetNodeId, string $targetPortKey)` readonly `@api` — rejects any blank field and self-loops (`sourceNodeId === targetNodeId`); exposes `identity(): string` = `"{$sourceNodeId}.{$sourcePortKey}>{$targetNodeId}.{$targetPortKey}"`. `InvalidGraphException extends \InvalidArgumentException` (`@api`) with `violations(): array<int, string>` list + ctor `(array $violations)` message-joining like `NodeInputValidationException`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class GraphNodeTest extends TestCase
{
    public function test_holds_identity_config_and_position(): void
    {
        $node = new GraphNode('n1', 'test.greet', ['prompt' => 'hi'], ['x' => 10, 'y' => 20.5]);

        $this->assertSame('n1', $node->id);
        $this->assertSame('test.greet', $node->type);
        $this->assertSame(['prompt' => 'hi'], $node->config);
        $this->assertSame(['x' => 10, 'y' => 20.5], $node->position);
    }

    public function test_rejects_blank_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/node id/i');

        new GraphNode('  ', 'test.greet');
    }

    public function test_rejects_blank_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/node type/i');

        new GraphNode('n1', '');
    }

    public function test_rejects_malformed_position(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/position/i');

        new GraphNode('n1', 'test.greet', [], ['x' => 'left']);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function test_holds_endpoints_and_identity(): void
    {
        $wire = new Connection('a', 'output', 'b', 'input');

        $this->assertSame('a', $wire->sourceNodeId);
        $this->assertSame('output', $wire->sourcePortKey);
        $this->assertSame('b', $wire->targetNodeId);
        $this->assertSame('input', $wire->targetPortKey);
        $this->assertSame('a.output>b.input', $wire->identity());
    }

    public function test_rejects_blank_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Connection('a', '', 'b', 'input');
    }

    public function test_rejects_self_loop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/itself/i');

        new Connection('a', 'output', 'a', 'input');
    }
}
```

- [ ] **Step 2: RED** — `vendor/bin/phpunit tests/Unit/Graph/GraphNodeTest.php tests/Unit/Graph/ConnectionTest.php` → class-not-found errors.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a graph violates structural or semantic invariants.
 * Carries the full violation list so tooling (Studio, importers) can
 * surface every problem at once instead of fix-one-rerun loops.
 *
 * @api
 */
final class InvalidGraphException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Invalid graph: '.implode(' | ', $violations));
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;

/**
 * Immutable node instance inside a {@see GraphDefinition}: which node
 * type runs, its per-instance config, and (for Studio) its canvas
 * position. `$position` is presentation metadata only — the engine
 * never reads it.
 *
 * @api
 */
final class GraphNode
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array{x: int|float, y: int|float}|null  $position
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $config = [],
        public readonly ?array $position = null,
    ) {
        if (trim($this->id) === '') {
            throw new InvalidArgumentException('Graph node id must not be empty.');
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException('Graph node type must not be empty.');
        }

        if ($this->position !== null && (! is_numeric($this->position['x'] ?? null) || ! is_numeric($this->position['y'] ?? null))) {
            throw new InvalidArgumentException("Graph node [{$this->id}] position must carry numeric x and y.");
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use InvalidArgumentException;

/**
 * Immutable wire between an output port and an input port of two
 * distinct nodes in a {@see GraphDefinition}.
 *
 * @api
 */
final class Connection
{
    public function __construct(
        public readonly string $sourceNodeId,
        public readonly string $sourcePortKey,
        public readonly string $targetNodeId,
        public readonly string $targetPortKey,
    ) {
        foreach ([$this->sourceNodeId, $this->sourcePortKey, $this->targetNodeId, $this->targetPortKey] as $field) {
            if (trim($field) === '') {
                throw new InvalidArgumentException('Connection fields must not be empty.');
            }
        }

        if ($this->sourceNodeId === $this->targetNodeId) {
            throw new InvalidArgumentException("Connection on [{$this->sourceNodeId}] cannot wire a node to itself.");
        }
    }

    public function identity(): string
    {
        return "{$this->sourceNodeId}.{$this->sourcePortKey}>{$this->targetNodeId}.{$this->targetPortKey}";
    }
}
```

- [ ] **Step 4: GREEN** — same explicit-path run. 
- [ ] **Step 5: `composer quality` green, then commit** `feat(graph): GraphNode and Connection value objects`.

---

### Task 3: GraphDefinition (structural) + GraphValidator (semantic) + pins

**Files:**
- Create: `src/Graph/GraphDefinition.php`, `src/Graph/GraphValidator.php`, `tests/Contract/GraphApiContractTest.php`
- Test: `tests/Unit/Graph/GraphDefinitionTest.php`, `tests/Unit/Graph/GraphValidatorTest.php`

**Interfaces:**
- Produces: `GraphDefinition(list<GraphNode> $nodes, list<Connection> $connections, array $metadata = [])` readonly `@api`. Constructor enforces STRUCTURE, collecting ALL violations into one `InvalidGraphException`: at least one node; unique node ids; every connection endpoint references an existing node id; no duplicate connection `identity()`; acyclic (Kahn — reused later by the executor: expose `public function topologicalOrder(): list<string>` computed once, throwing on cycle). Helpers: `node(string $id): ?GraphNode`, `nodeIds(): list<string>`.
- `GraphValidator` `@api`: `__construct(NodeRegistry $registry)`, `validate(GraphDefinition $graph): void` — SEMANTIC violations collected into `InvalidGraphException`: unknown node type (not in registry); connection references a port key that does not exist on the source's outputs / target's inputs; `PortType::accepts` incompatibility (`target->type->accepts(source->type)` must be true); required input ports left completely unwired AND absent from the node's `config` (config values count as satisfied inputs — Studio sets literals in config).
- Contract file starts pinning: PortTypes untouched; all `@api` Graph classes annotation-checked; `GraphDefinition::topologicalOrder` method existence.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Graph/GraphDefinitionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use PHPUnit\Framework\TestCase;

final class GraphDefinitionTest extends TestCase
{
    public function test_diamond_graph_is_accepted_with_topological_order(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('a', 't'), new GraphNode('b', 't'), new GraphNode('c', 't'), new GraphNode('d', 't')],
            [
                new Connection('a', 'out', 'b', 'in'),
                new Connection('a', 'out', 'c', 'in'),
                new Connection('b', 'out', 'd', 'in'),
                new Connection('c', 'out', 'd', 'in'),
            ],
        );

        $order = $graph->topologicalOrder();

        $this->assertSame('a', $order[0]);
        $this->assertSame('d', $order[3]);
        $this->assertEqualsCanonicalizing(['b', 'c'], [$order[1], $order[2]]);
        $this->assertSame(['a', 'b', 'c', 'd'], $graph->nodeIds());
        $this->assertNotNull($graph->node('b'));
        $this->assertNull($graph->node('zz'));
    }

    public function test_empty_graph_is_rejected(): void
    {
        $this->expectException(InvalidGraphException::class);

        new GraphDefinition([], []);
    }

    public function test_structural_violations_are_collected_together(): void
    {
        try {
            new GraphDefinition(
                [new GraphNode('a', 't'), new GraphNode('a', 't'), new GraphNode('b', 't')],
                [
                    new Connection('a', 'out', 'ghost', 'in'),
                    new Connection('a', 'out', 'b', 'in'),
                    new Connection('a', 'out', 'b', 'in'),
                ],
            );
            $this->fail('Expected InvalidGraphException');
        } catch (InvalidGraphException $e) {
            $joined = implode(' | ', $e->violations());
            $this->assertStringContainsString('Duplicate node id [a]', $joined);
            $this->assertStringContainsString('unknown node [ghost]', $joined);
            $this->assertStringContainsString('Duplicate connection [a.out>b.in]', $joined);
        }
    }

    public function test_cycle_is_rejected(): void
    {
        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/cycle/i');

        new GraphDefinition(
            [new GraphNode('a', 't'), new GraphNode('b', 't')],
            [new Connection('a', 'out', 'b', 'in'), new Connection('b', 'out', 'a', 'in')],
        );
    }
}
```

`tests/Unit/Graph/GraphValidatorTest.php` (uses the real registry + the Macro-A fixtures GreetNode `test.greet` — required Text input `name`, Text output `greeting` — and UpperNode `test.upper` — required Text input `text`, Text output `upper`):

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class GraphValidatorTest extends TestCase
{
    private GraphValidator $validator;

    protected function setUp(): void
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory);
        $registry->registerMany([GreetNode::class, UpperNode::class]);
        $this->validator = new GraphValidator($registry);
    }

    public function test_valid_wired_graph_passes(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('u', 'test.upper')],
            [new Connection('g', 'greeting', 'u', 'text')],
        );

        $this->validator->validate($graph);
        $this->addToAssertionCount(1);
    }

    public function test_unknown_node_type_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('x', 'missing.type', ['name' => 'v'])], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/unknown node type \[missing\.type\]/i');
        $this->validator->validate($graph);
    }

    public function test_unknown_ports_violate(): void
    {
        try {
            $this->validator->validate(new GraphDefinition(
                [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('u', 'test.upper')],
                [new Connection('g', 'nope', 'u', 'wrong')],
            ));
            $this->fail('Expected InvalidGraphException');
        } catch (InvalidGraphException $e) {
            $joined = implode(' | ', $e->violations());
            $this->assertStringContainsString('output port [nope]', $joined);
            $this->assertStringContainsString('input port [wrong]', $joined);
        }
    }

    public function test_unwired_required_input_without_config_violates(): void
    {
        $graph = new GraphDefinition([new GraphNode('g', 'test.greet')], []);

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/required input \[name\].*unwired/i');
        $this->validator->validate($graph);
    }
}
```

Port-type incompatibility needs two fixture types with non-Text ports; both existing fixtures are Text-only. Add a small fixture `tests/Fixtures/Nodes/CountNode.php` (registered ONLY inside the validator test, so discovery tests asserting exactly-[GreetNode, UpperNode] stay valid — **it must NOT live in tests/Fixtures/Nodes** for that reason; put it in `tests/Fixtures/GraphNodes/CountNode.php`, namespace `Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes`):

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\GraphNodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.count', category: 'testing')]
final class CountNode implements FlowNodeHandler
{
    #[Input(type: PortType::Int, required: true)]
    public int $seed;

    #[Output(type: PortType::Int)]
    public int $count;

    public function execute(NodeContext $context): NodeResult
    {
        $seed = $context->inputs['seed'];

        if (! is_int($seed)) {
            return NodeResult::failed(new \InvalidArgumentException('Input [seed] must be an int.'));
        }

        return NodeResult::success(['count' => $seed + 1]);
    }
}
```

Add to the validator test (register `CountNode::class` too in setUp):

```php
    public function test_port_type_incompatibility_violates(): void
    {
        $graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada']), new GraphNode('c', 'test.count')],
            [new Connection('g', 'greeting', 'c', 'seed')],
        );

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/\[text\].*cannot feed.*\[int\]/i');
        $this->validator->validate($graph);
    }
```

- [ ] **Step 2: RED** — explicit-path run, class-not-found.

- [ ] **Step 3: Implement**

`src/Graph/GraphDefinition.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;

/**
 * Immutable, execution-ready description of a flow graph. The constructor
 * enforces STRUCTURAL invariants only (identity, referential integrity,
 * acyclicity); semantic rules that need the node catalog live in
 * {@see GraphValidator}. `$metadata` carries definition-level extras
 * (required run inputs, aggregate compensator, ...) round-tripped by the
 * serializer and ignored by structural checks.
 *
 * @api
 */
final class GraphDefinition
{
    /**
     * @var list<string>
     */
    private readonly array $order;

    /**
     * @param  list<GraphNode>  $nodes
     * @param  list<Connection>  $connections
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $connections,
        public readonly array $metadata = [],
    ) {
        $violations = [];
        $byId = [];

        if ($this->nodes === []) {
            $violations[] = 'Graph must contain at least one node.';
        }

        foreach ($this->nodes as $node) {
            if (isset($byId[$node->id])) {
                $violations[] = "Duplicate node id [{$node->id}].";

                continue;
            }

            $byId[$node->id] = $node;
        }

        $seenWires = [];

        foreach ($this->connections as $wire) {
            foreach ([$wire->sourceNodeId, $wire->targetNodeId] as $endpoint) {
                if (! isset($byId[$endpoint])) {
                    $violations[] = "Connection [{$wire->identity()}] references unknown node [{$endpoint}].";
                }
            }

            if (isset($seenWires[$wire->identity()])) {
                $violations[] = "Duplicate connection [{$wire->identity()}].";
            }

            $seenWires[$wire->identity()] = true;
        }

        $order = $violations === [] ? $this->computeTopologicalOrder($byId) : [];

        if ($order === [] && $violations === [] && $this->nodes !== []) {
            $violations[] = 'Graph contains a cycle.';
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }

        $this->order = $order;
    }

    public function node(string $id): ?GraphNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function nodeIds(): array
    {
        return array_map(static fn (GraphNode $node): string => $node->id, $this->nodes);
    }

    /**
     * Kahn order computed once at construction; the graph executor
     * (Macro C) consumes this for wave planning.
     *
     * @return list<string>
     */
    public function topologicalOrder(): array
    {
        return $this->order;
    }

    /**
     * @param  array<string, GraphNode>  $byId
     * @return list<string> empty when a cycle prevents completion
     */
    private function computeTopologicalOrder(array $byId): array
    {
        $inDegree = array_fill_keys(array_keys($byId), 0);
        $adjacency = [];

        foreach ($this->connections as $wire) {
            $adjacency[$wire->sourceNodeId][] = $wire->targetNodeId;
            $inDegree[$wire->targetNodeId]++;
        }

        $queue = [];

        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $order = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($adjacency[$id] ?? [] as $next) {
                if (--$inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return count($order) === count($byId) ? $order : [];
    }
}
```

`src/Graph/GraphValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeRegistry;

/**
 * Semantic graph validation against the node catalog: every node type
 * registered, every wire lands on real ports with compatible types, and
 * every required input is fed by a wire or a config literal. Studio and
 * the definition repository run this before persisting/publishing.
 *
 * @api
 */
final class GraphValidator
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @throws InvalidGraphException
     */
    public function validate(GraphDefinition $graph): void
    {
        $violations = [];
        $definitions = [];

        foreach ($graph->nodes as $node) {
            try {
                $definitions[$node->id] = $this->registry->get($node->type);
            } catch (UnknownNodeTypeException) {
                $violations[] = "Unknown node type [{$node->type}] on node [{$node->id}].";
            }
        }

        $wiredInputs = [];

        foreach ($graph->connections as $wire) {
            $source = $definitions[$wire->sourceNodeId] ?? null;
            $target = $definitions[$wire->targetNodeId] ?? null;

            $out = $source?->output($wire->sourcePortKey);
            $in = $target?->input($wire->targetPortKey);

            if ($source !== null && $out === null) {
                $violations[] = "Connection [{$wire->identity()}] references unknown output port [{$wire->sourcePortKey}] on [{$wire->sourceNodeId}].";
            }

            if ($target !== null && $in === null) {
                $violations[] = "Connection [{$wire->identity()}] references unknown input port [{$wire->targetPortKey}] on [{$wire->targetNodeId}].";
            }

            if ($out !== null && $in !== null && ! $in->type->accepts($out->type)) {
                $violations[] = "Connection [{$wire->identity()}]: output type [{$out->type->value}] cannot feed input type [{$in->type->value}].";
            }

            $wiredInputs[$wire->targetNodeId][$wire->targetPortKey] = true;
        }

        foreach ($graph->nodes as $node) {
            $definition = $definitions[$node->id] ?? null;

            if ($definition === null) {
                continue;
            }

            foreach ($definition->inputs as $port) {
                $satisfied = isset($wiredInputs[$node->id][$port->key]) || array_key_exists($port->key, $node->config);

                if ($port->required && ! $satisfied) {
                    $violations[] = "Required input [{$port->key}] on node [{$node->id}] is unwired and has no config value.";
                }
            }
        }

        if ($violations !== []) {
            throw new InvalidGraphException($violations);
        }
    }
}
```

`tests/Contract/GraphApiContractTest.php` (initial; extended each PR):

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GraphApiContractTest extends TestCase
{
    public function test_graph_api_classes_are_annotated_api(): void
    {
        $classes = [
            \Padosoft\LaravelFlow\Graph\GraphNode::class,
            \Padosoft\LaravelFlow\Graph\Connection::class,
            \Padosoft\LaravelFlow\Graph\GraphDefinition::class,
            \Padosoft\LaravelFlow\Graph\GraphValidator::class,
            \Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }

    public function test_graph_definition_exposes_topological_order(): void
    {
        $this->assertTrue((new ReflectionClass(\Padosoft\LaravelFlow\Graph\GraphDefinition::class))->hasMethod('topologicalOrder'));
    }
}
```

- [ ] **Step 4: GREEN** — explicit paths + `vendor/bin/phpunit --testsuite Contract`.
- [ ] **Step 5: `composer quality` green; commit** `feat(graph): GraphDefinition with structural invariants and semantic GraphValidator`; **then close B-PR1**: local-copilot-review loop → push `task/v2b-01-graph-vos` → PR to `task/v2b-graph-definition` (Copilot via GraphQL fallback) → CI+review loop → merge → `git checkout task/v2b-graph-definition && git pull && git checkout -b task/v2b-02-json-schema`.

---

### Task 4: Canonical JSON schema v1 (B-PR2)

**Files:**
- Create: `src/Graph/GraphSerializer.php`
- Test: `tests/Unit/Graph/GraphSerializerTest.php`; extend `tests/Contract/GraphApiContractTest.php`

**Interfaces:**
- Produces: `GraphSerializer` `@api`, stateless: `SCHEMA_VERSION = 1`, `KIND = 'laravel-flow'`; `toArray(GraphDefinition): array{schema_version:int, kind:string, metadata:array, nodes:list<array>, connections:list<array>}` (node arrays: `{id, type, config, position}`, connection arrays: `{sourceNodeId, sourcePortKey, targetNodeId, targetPortKey}`); `fromArray(array): GraphDefinition` (throws `InvalidGraphException` on wrong/missing `schema_version` or `kind`, malformed node/connection entries); `toJson`/`fromJson`; `checksum(GraphDefinition): string` — sha256 of the RECURSIVELY key-sorted `toArray()` JSON (stable across input key order).

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Graph;

use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use PHPUnit\Framework\TestCase;

final class GraphSerializerTest extends TestCase
{
    private GraphSerializer $serializer;

    private GraphDefinition $graph;

    protected function setUp(): void
    {
        $this->serializer = new GraphSerializer;
        $this->graph = new GraphDefinition(
            [new GraphNode('g', 'test.greet', ['name' => 'Ada'], ['x' => 1, 'y' => 2]), new GraphNode('u', 'test.upper')],
            [new Connection('g', 'greeting', 'u', 'text')],
            ['required_inputs' => ['name']],
        );
    }

    public function test_round_trip_preserves_the_graph(): void
    {
        $rebuilt = $this->serializer->fromArray($this->serializer->toArray($this->graph));

        $this->assertSame($this->serializer->checksum($this->graph), $this->serializer->checksum($rebuilt));
        $this->assertSame(['g', 'u'], $rebuilt->nodeIds());
        $this->assertSame(['required_inputs' => ['name']], $rebuilt->metadata);
        $this->assertSame(['x' => 1, 'y' => 2], $rebuilt->node('g')?->position);
    }

    public function test_envelope_shape(): void
    {
        $array = $this->serializer->toArray($this->graph);

        $this->assertSame(GraphSerializer::SCHEMA_VERSION, $array['schema_version']);
        $this->assertSame(GraphSerializer::KIND, $array['kind']);
        $this->assertCount(2, $array['nodes']);
        $this->assertSame('g', $array['nodes'][0]['id']);
        $this->assertSame('greeting', $array['connections'][0]['sourcePortKey']);
    }

    public function test_checksum_is_stable_across_key_order(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $shuffled = $array;
        $shuffled['nodes'][0] = array_reverse($shuffled['nodes'][0], true);
        krsort($shuffled);

        $this->assertSame(
            $this->serializer->checksum($this->serializer->fromArray($array)),
            $this->serializer->checksum($this->serializer->fromArray($shuffled)),
        );
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $array['schema_version'] = 99;

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/schema_version/i');
        $this->serializer->fromArray($array);
    }

    public function test_wrong_kind_is_rejected(): void
    {
        $array = $this->serializer->toArray($this->graph);
        $array['kind'] = 'other';

        $this->expectException(InvalidGraphException::class);
        $this->expectExceptionMessageMatches('/kind/i');
        $this->serializer->fromArray($array);
    }

    public function test_json_round_trip(): void
    {
        $json = $this->serializer->toJson($this->graph);
        $rebuilt = $this->serializer->fromJson($json);

        $this->assertSame($this->serializer->checksum($this->graph), $this->serializer->checksum($rebuilt));
    }
}
```

- [ ] **Step 2: RED** (explicit path). 
- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Graph;

use JsonException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;

/**
 * Canonical JSON envelope for graph definitions (schema v1) and the
 * stable content checksum used by the definition store, signing and the
 * node-level cache. The checksum canonicalizes by recursively sorting
 * keys, so semantically identical payloads always hash the same.
 *
 * @api
 */
final class GraphSerializer
{
    public const SCHEMA_VERSION = 1;

    public const KIND = 'laravel-flow';

    /**
     * @return array<string, mixed>
     */
    public function toArray(GraphDefinition $graph): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::KIND,
            'metadata' => $graph->metadata,
            'nodes' => array_map(static fn (GraphNode $node): array => [
                'id' => $node->id,
                'type' => $node->type,
                'config' => $node->config,
                'position' => $node->position,
            ], $graph->nodes),
            'connections' => array_map(static fn (Connection $wire): array => [
                'sourceNodeId' => $wire->sourceNodeId,
                'sourcePortKey' => $wire->sourcePortKey,
                'targetNodeId' => $wire->targetNodeId,
                'targetPortKey' => $wire->targetPortKey,
            ], $graph->connections),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fromArray(array $payload): GraphDefinition
    {
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidGraphException(['Unsupported or missing schema_version; expected '.self::SCHEMA_VERSION.'.']);
        }

        if (($payload['kind'] ?? null) !== self::KIND) {
            throw new InvalidGraphException(["Unsupported or missing kind; expected '".self::KIND."'."]);
        }

        $nodes = [];

        foreach (is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [] as $index => $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['type'] ?? null)) {
                throw new InvalidGraphException(["Malformed node entry at index {$index}."]);
            }

            $nodes[] = new GraphNode(
                $node['id'],
                $node['type'],
                is_array($node['config'] ?? null) ? $node['config'] : [],
                is_array($node['position'] ?? null) ? $node['position'] : null,
            );
        }

        $connections = [];

        foreach (is_array($payload['connections'] ?? null) ? $payload['connections'] : [] as $index => $wire) {
            if (! is_array($wire)
                || ! is_string($wire['sourceNodeId'] ?? null) || ! is_string($wire['sourcePortKey'] ?? null)
                || ! is_string($wire['targetNodeId'] ?? null) || ! is_string($wire['targetPortKey'] ?? null)) {
                throw new InvalidGraphException(["Malformed connection entry at index {$index}."]);
            }

            $connections[] = new Connection($wire['sourceNodeId'], $wire['sourcePortKey'], $wire['targetNodeId'], $wire['targetPortKey']);
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return new GraphDefinition($nodes, $connections, $metadata);
    }

    /**
     * @throws JsonException
     */
    public function toJson(GraphDefinition $graph, int $flags = 0): string
    {
        return json_encode($this->toArray($graph), $flags | JSON_THROW_ON_ERROR);
    }

    public function fromJson(string $json): GraphDefinition
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidGraphException(['Invalid JSON payload: '.$e->getMessage()]);
        }

        if (! is_array($decoded)) {
            throw new InvalidGraphException(['Graph payload must decode to an object.']);
        }

        return $this->fromArray($decoded);
    }

    public function checksum(GraphDefinition $graph): string
    {
        $canonical = $this->toArray($graph);
        $this->ksortRecursive($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }
}
```

Extend `GraphApiContractTest`: add `GraphSerializer::class` to the annotation list plus:

```php
    public function test_graph_schema_constants_are_pinned(): void
    {
        $this->assertSame(1, \Padosoft\LaravelFlow\Graph\GraphSerializer::SCHEMA_VERSION);
        $this->assertSame('laravel-flow', \Padosoft\LaravelFlow\Graph\GraphSerializer::KIND);
    }
```

- [ ] **Step 4: GREEN**; **Step 5:** `composer quality`, commit `feat(graph): canonical JSON schema v1 with stable checksum`, close **B-PR2** (full PR boundary procedure), branch `task/v2b-03-definition-repository`.

---

### Task 5: `flow_definitions` migration (B-PR3, part 1)

**Files:**
- Create: `database/migrations/2026_07_08_000005_create_flow_definitions_table.php`
- Modify: `src/LaravelFlowServiceProvider.php` (`publishesMigrations` entry, mirroring the four existing lines at `src/LaravelFlowServiceProvider.php:176-181`)
- Test: extend `tests/Unit/Persistence/PersistenceMigrationTest.php` — **VERIFY IN CODE FIRST:** how that test asserts tables/columns today; mirror its style for `flow_definitions`.

**Schema** (follow the style of `database/migrations/2026_05_02_000001_create_laravel_flow_tables.php` — read it first):

```php
Schema::create('flow_definitions', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('version');
    $table->string('status', 20)->default('draft')->index();
    $table->json('graph');
    $table->string('checksum', 64)->index();
    $table->string('signature', 128)->nullable();
    $table->timestamp('published_at')->nullable();
    $table->timestamps();

    $table->unique(['name', 'version']);
    $table->index(['name', 'status']);
});
```

TDD: add the migration assertions first (RED because table missing), create migration + SP entry (GREEN). `composer quality`; commit `feat(persistence): flow_definitions table`.

---

### Task 6: DefinitionRepository contract + Eloquent implementation (B-PR3, part 2)

**Files:**
- Create: `src/Contracts/DefinitionRepository.php` (`@api`), `src/Graph/StoredDefinition.php` (`@api` read DTO), `src/Persistence/EloquentDefinitionRepository.php` (`@internal`), `src/Graph/Exceptions/DefinitionNotFoundException.php` + `src/Graph/Exceptions/DefinitionLifecycleException.php` (`@api`)
- Modify: `src/LaravelFlowServiceProvider.php` (bind contract → Eloquent impl with `default_storage` connection, mirroring `ApprovalRepository` binding style), `config/laravel-flow.php` (add `definitions` block placeholder used again by Task 7)
- Test: `tests/Unit/Persistence/DefinitionRepositoryTest.php` extending `PersistenceTestCase`; extend `GraphApiContractTest`

**Interfaces (exact `@api` surface):**

```php
interface DefinitionRepository
{
    /** Creates version = max(existing)+1 (or 1) in status 'draft'. */
    public function createDraft(string $name, GraphDefinition $graph): StoredDefinition;

    /** @throws DefinitionNotFoundException */
    public function find(string $name, int $version): StoredDefinition;

    /** Latest by version; optionally constrained to a status. Null when none. */
    public function latest(string $name, ?string $status = null): ?StoredDefinition;

    /** draft → published; archives any previously published version of the same name. @throws DefinitionLifecycleException on non-draft. */
    public function publish(string $name, int $version): StoredDefinition;

    /** published|draft → archived. @throws DefinitionLifecycleException on already-archived. */
    public function archive(string $name, int $version): StoredDefinition;

    /** @return list<StoredDefinition> all versions of a name, ascending. */
    public function versions(string $name): array;
}
```

`StoredDefinition` readonly DTO: `int $id, string $name, int $version, string $status, array $graph, string $checksum, ?string $signature, ?\DateTimeImmutable $publishedAt`. Statuses as public consts on the DTO: `STATUS_DRAFT/PUBLISHED/ARCHIVED`. Published versions are IMMUTABLE: the repository exposes NO update-graph API at all — the only mutations are `publish`/`archive`; a change is always `createDraft` (new version). The Eloquent impl computes `checksum` via `GraphSerializer::checksum()` and stores `GraphSerializer::toArray()` in `graph`.

Tests (behavioral, sqlite in-memory via `PersistenceTestCase`): create-draft auto-increments version; find/latest/versions; publish transitions + auto-archives previous published; publish on published throws `DefinitionLifecycleException`; archive on archived throws; find unknown throws `DefinitionNotFoundException`; stored graph round-trips through `GraphSerializer::fromArray`.

TDD as usual; `composer quality`; commit `feat(persistence): versioned DefinitionRepository with draft/published/archived lifecycle`; close **B-PR3** (PR boundary), branch `task/v2b-04-signing`.

---

### Task 7: Optional HMAC definition signing (B-PR4)

**Files:**
- Create: `src/Graph/DefinitionSigner.php` (`@api`), `src/Graph/Exceptions/DefinitionSignatureException.php` (`@api`)
- Modify: `config/laravel-flow.php` (`definitions.signing.enabled` bool default false, `definitions.signing.secret` env `LARAVEL_FLOW_DEFINITIONS_SIGNING_SECRET`, docblock in the file's comment style), `src/LaravelFlowServiceProvider.php` (singleton, config-driven — mirror the `WebhookDeliveryClient` secret-handling pattern at `src/LaravelFlowServiceProvider.php:110-120`), `src/Persistence/EloquentDefinitionRepository.php` (sign on `createDraft`, verify on every read when enabled)
- Test: `tests/Unit/Graph/DefinitionSignerTest.php`, extend `tests/Unit/Persistence/DefinitionRepositoryTest.php`, extend `GraphApiContractTest`

**Behavior:** `DefinitionSigner(bool $enabled, ?string $secret)`; `sign(string $checksum): ?string` → `hash_hmac('sha256', $checksum, $secret)` when enabled else null; enabled with blank/null secret → `DefinitionSignatureException` at construction (fail fast, mirrors fail-fast philosophy); `verify(string $checksum, ?string $signature): void` → no-op when disabled; when enabled throws `DefinitionSignatureException` on null/mismatching signature (use `hash_equals`). Repository verifies in `find`/`latest`/`versions` hydration path. Tests: disabled = null signatures + verify no-op (tampered rows load fine); enabled = signature persisted, tampered `graph` JSON (checksum mismatch → recompute-and-verify fails) throws; enabled-but-no-secret throws at boot.

TDD; `composer quality`; commit `feat(graph): optional HMAC signing for stored definitions`; close **B-PR4**, branch `task/v2b-05-import-export`.

---

### Task 8: Export/import services + commands (B-PR5, part 1)

**Files:**
- Create: `src/Graph/GraphTransfer.php` (`@api`: `export(StoredDefinition): string` pretty JSON of the serializer envelope + `definition: {name, version, status, checksum}` block; `importDraft(string $json, string $name): StoredDefinition` — parses with `GraphSerializer::fromJson` after stripping the optional `definition` block, validates via `GraphValidator`, persists via `DefinitionRepository::createDraft`), `src/Console/ExportFlowDefinitionCommand.php` + `src/Console/ImportFlowDefinitionCommand.php` (`@internal`; signatures `flow:export {name} {--version=} {--path=}` writing to file or stdout, `flow:import {file} {--name=} {--publish}`)
- Modify: SP (`commands([...])` block + `GraphTransfer` singleton)
- Test: `tests/Unit/Graph/GraphTransferTest.php` (extends `PersistenceTestCase` since it persists), `tests/Unit/Persistence/DefinitionTransferCommandTest.php` (artisan-level, `$this->artisan('flow:export', ...)` style — mirror `tests/Unit/Persistence/ApprovalCommandTest.php` conventions, **VERIFY IN CODE FIRST**)

Export/import round-trip test: createDraft → export → import under new name → checksums equal. `--publish` flag publishes the imported draft. Import of graph failing `GraphValidator` → command exits non-zero printing violations (one per line).

Commit `feat(graph): definition export/import service and commands`.

### Task 9: ModelsGenerator Flow-v2 importer (B-PR5, part 2)

**Files:**
- Create: `src/Graph/ModelsGeneratorFlowImporter.php` (`@api`), fixture `tests/Fixtures/Graph/modelsgenerator-flow2.json`
- Test: `tests/Unit/Graph/ModelsGeneratorFlowImporterTest.php`; extend `GraphApiContractTest`

**Behavior:** accepts the ModelsGenerator envelope `{version:1, kind:'flow2', id?, name?, config:{nodes:[{id, serviceType, position:{x,y}, data}], connections:[{id?, sourceNodeId, sourcePortKey, targetNodeId, targetPortKey}]}}` (also tolerates the bare `{nodes, connections}` config without envelope): maps `serviceType`→`type`, `data`→`config`, `position` passthrough; drops connection `id`s; returns a `GraphDefinition` (STRUCTURAL validation only — the source app's types are not in this registry; semantic validation is the caller's opt-in). Rejects `kind` other than `flow2` when the envelope is present. Fixture: a 3-node flow2 JSON (flow-input → base_module → output shape, verbatim field names). Tests: mapping correctness (type/config/position), envelope + bare-config both accepted, wrong kind rejected, structural violations surface (`InvalidGraphException`).

Commit `feat(graph): ModelsGenerator Flow-v2 importer`; close **B-PR5**, branch `task/v2b-06-builder-compilation`.

---

### Task 10: v1 builder → graph compilation (B-PR6)

**Files:**
- Modify: `src/FlowDefinition.php` (additive `toGraphDefinition(): GraphDefinition`), `config/laravel-flow.php` (`definitions.persist_registered` bool, default `false`), `src/FlowEngine.php` (**VERIFY IN CODE FIRST:** `registerDefinition()` — hook the optional persist AFTER successful registration, resolving `DefinitionRepository` lazily and ONLY when the flag is true; wrap in a checksum-dedupe: skip persisting when `latest($name)` has the same checksum)
- Test: `tests/Unit/FlowDefinitionCompilationTest.php`, `tests/Unit/Persistence/RegisterPersistsDraftTest.php` (PersistenceTestCase), extend `GraphApiContractTest` (pin `toGraphDefinition` method existence)

**Compilation semantics (v1 chain is a degenerate path graph):** each `FlowStep` → `GraphNode(id: step->name, type: 'legacy.step', config: ['handler' => step->handlerFqcn, 'supports_dry_run' => step->supportsDryRun, 'compensator' => step->compensatorFqcn, 'approval_gate' => step->handlerFqcn === ApprovalGate::class])`; consecutive steps wired `Connection(prev->name, 'output', next->name, 'input')`; `metadata: ['required_inputs' => requiredInputs, 'aggregate_compensator' => aggregateCompensatorFqcn, 'compiled_from' => 'v1-builder']`. `legacy.step` is a reserved type string (constant `FlowDefinition::LEGACY_NODE_TYPE`) — NOT registered in the NodeRegistry; such graphs pass STRUCTURAL construction, and their semantic resolution/execution arrives with the graph executor (Macro C C-PR3, already in the master plan). Document this on the method docblock.

Tests: 3-step chain compiles to 3 nodes / 2 ordered connections with correct config incl. approval-gate flag and dry-run; single-step flow → 1 node 0 connections; checksum stable across two compilations; **the ENTIRE pre-existing v1 Unit suite passes unmodified** (this is the gate — do not touch any v1 test); with `persist_registered=true` + persistence configured, `register()` creates a draft and re-registering the identical flow does NOT create a second version; flag default-off → no DB writes on register (assert zero `flow_definitions` rows).

Commit `feat(flow): compile v1 definitions to graphs with optional draft persistence`; close **B-PR6**, branch `task/v2b-07-run-version-pinning`.

---

### Task 11: Run → definition-version pinning (B-PR7, part 1)

**Files:**
- Create: `database/migrations/2026_07_08_000006_add_definition_version_to_laravel_flow_runs.php` (nullable `unsignedInteger('definition_version')` + nullable `string('definition_checksum', 64)` on `flow_runs`; follow `2026_05_04_000002_add_replay_lineage_to_laravel_flow_runs.php` style — read it first) + SP `publishesMigrations` entry
- Modify: **VERIFY IN CODE FIRST:** the run-creation write path — `src/Persistence/EloquentFlowStore.php` / `Contracts\RunRepository::create...` and how `FlowEngine` calls it (read `FlowEnginePersistenceTest` to find the seam). At run creation, when `persist_registered` matched/produced a stored version during registration, write both columns; otherwise leave null. IMPORTANT design note: `FlowDefinition` is a readonly VO — do NOT try to set version props on it. Track the pin in the ENGINE's own registration bookkeeping: **VERIFY IN CODE FIRST** how `FlowEngine::registerDefinition()` stores definitions (likely an array keyed by name) and extend that storage to an entry shape carrying `[definition, ?int version, ?string checksum]` (internal detail, no `@api` change), populated by Task 10's persist hook.
- Modify: `src/Console/ReplayFlowRunCommand.php` — current drift check `definitionDrifted()` (line ~156) stays for UNPINNED runs; for pinned runs compare the stored `definition_checksum` against `GraphSerializer::checksum($definition->toGraphDefinition())`: identical → no warning; different → warning naming BOTH the pinned version and "replay will use the currently registered definition" (graph-exact re-execution ships with Macro C).
- Test: `tests/Unit/Persistence/RunVersionPinningTest.php` + extend `tests/Unit/Persistence/ReplayFlowRunCommandTest.php` (**VERIFY IN CODE FIRST:** its artisan-call conventions).

Tests: run created from a persisted-registered flow carries version+checksum; unpinned legacy run has nulls; replay of pinned unchanged flow emits NO drift warning; replay after changing the registered flow emits the version-aware warning; replay of unpinned run keeps today's warning behavior.

Commit `feat(persistence): pin runs to definition versions with checksum-aware replay drift`.

### Task 12: Macro closure — pins audit + docs (B-PR7, part 2)

- Audit `GraphApiContractTest` covers EVERY `@api` class under `src/Graph` + `Contracts\DefinitionRepository` + the two new commands are `@internal`; `NodeAnnotationSweepTest` now covers `src/Graph` automatically (Task 1 wrote it path-tolerant).
- `docs/UPGRADE.md`: extend the Node-namespace bullet with the Graph namespace + `DefinitionRepository` (same pre-v2 stability note).
- `docs/PROGRESS.md`: Macro B completion entry (dated, counts from the final `composer quality` run, PR numbers, deferrals: docs-site → Macro G; legacy graph execution + version-exact replay → Macro C).
- Full `composer quality`; commit `test(contract): pin Graph namespace surface; Macro B closure docs`; close **B-PR7**.

**Macro PR:** `task/v2b-graph-definition` → `main`, body summarizing the seven PRs + quality trail; Copilot reviewer via GraphQL fallback; CI + review loop; final whole-branch review (most capable model) BEFORE opening it, mirroring Macro A.

## Macro Gate G3 checklist (all verified with evidence before Macro C starts)

- [ ] `composer quality` green on `main` after the macro merge (record counts)
- [ ] A JSON graph authored by hand imports (`flow:import`), validates, persists, publishes, exports and re-imports with identical checksum
- [ ] The ModelsGenerator fixture imports to a valid `GraphDefinition`
- [ ] A v1 fluent definition compiles to a graph, persists as draft when the flag is on, and the FULL pre-existing v1 suite is untouched and green
- [ ] A pinned run replays without a drift warning; a drifted pinned run warns with version context
- [ ] Signing enabled: tampered stored graph fails to load; disabled: no-op (both pinned by tests)
- [ ] `docs/PROGRESS.md` + `docs/UPGRADE.md` updated; lessons in `docs/LESSON.md`
- [ ] **Macro C detailed plan authored** (`docs/superpowers/plans/2026-07-XX-macro-c-graph-executor.md`) per the master-plan JIT rule, folding in: legacy-node resolution strategy (from Macro A deferral), version-exact replay execution (from B-PR7), and any new LESSON entries
