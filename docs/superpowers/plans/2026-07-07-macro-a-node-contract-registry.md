# Macro A — Node Contract & Registry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the typed, self-describing, enforced node contract to `padosoft/laravel-flow`: PHP attributes, `PortType`, reflection-built definitions, input validation/hydration, node registry with PSR-4 auto-discovery, JSON catalog + `flow:nodes` command, and a v1 `FlowStepHandler` adapter. Purely additive — no v1 behavior changes.

**Architecture:** New `Padosoft\LaravelFlow\Node\` namespace (pure PHP, standalone-agnostic; only the console command lives in `Console\` and touches Illuminate). Attributes are the single source of truth: reflection builds `NodeDefinition`s, which feed validation, the registry, the JSON catalog (later consumed by Studio and MCP schema generation).

**Tech Stack:** PHP ^8.3 (native attributes, enums, readonly), PHPUnit (suites: Unit/Architecture/Contract), Orchestra Testbench for container/command tests, PHPStan 2, Pint.

## Global Constraints

- PHP `^8.3`, `illuminate/* ^13.0` only; core stays headless/standalone-agnostic.
- Every file: `declare(strict_types=1);`. Follow existing style: `final` classes, constructor-promoted `readonly` properties, factory statics (see `src/FlowStepResult.php`).
- New public classes annotated `@api` (or `@internal`), never both; `@api` surface pinned in `tests/Contract` in the same PR that introduces it.
- Gates: after every task `composer quality` must be green (pint --test, phpstan, Unit+Architecture+Contract). Iterate until green; after 3 failed fix attempts use superpowers:systematic-debugging.
- Deferred by explicit YAGNI decision: `PortType::Money`/`Binary` (added later with the nodes that need them — enum is open for extension), `#[Retry]`/`#[Cacheable]` attributes (Macro C, where the executor consumes them).

## Branch & PR map

Macro branch: `task/v2a-node-contract` (from `main`). Subtask branches off it, PRs target it:

| PR | Branch | Tasks |
|---|---|---|
| A-PR1 | `task/v2a-01-ports-attributes` | 1, 2 |
| A-PR2 | `task/v2a-02-definition-validation` | 3, 4 |
| A-PR3 | `task/v2a-03-handler-registry` | 5, 6 |
| A-PR4 | `task/v2a-04-discovery-catalog` | 7, 8 |
| A-PR5 | `task/v2a-05-adapter-pinning` | 9, 10 |

Each PR: G2 gate (pre-push self-review, Copilot loop, CI green on head) before merge.

**Setup:**

```bash
git checkout main && git pull
git checkout -b task/v2a-node-contract && git push -u origin task/v2a-node-contract
git checkout -b task/v2a-01-ports-attributes
```

---

### Task 1: PortType enum + PortDefinition

**Files:**
- Create: `src/Node/PortType.php`
- Create: `src/Node/PortDefinition.php`
- Test: `tests/Unit/Node/PortTypeTest.php`, `tests/Unit/Node/PortDefinitionTest.php`

**Interfaces:**
- Produces: `PortType` (string enum: `Text|Int|Float|Bool|Json|Any`) with `accepts(PortType $source): bool` and `validates(mixed $value): bool`; `PortDefinition` readonly VO (`key`, `type`, `required=false`, `label=null`, `propertyName=null`) with `toArray(): array{key:string,type:string,required:bool,label:string}`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Node/PortTypeTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class PortTypeTest extends TestCase
{
    public function testSameTypeIsAccepted(): void
    {
        $this->assertTrue(PortType::Text->accepts(PortType::Text));
    }

    public function testDifferentScalarTypesAreRejected(): void
    {
        $this->assertFalse(PortType::Text->accepts(PortType::Int));
        $this->assertFalse(PortType::Bool->accepts(PortType::Json));
    }

    public function testAnyAcceptsAndIsAcceptedByEverything(): void
    {
        foreach (PortType::cases() as $case) {
            $this->assertTrue(PortType::Any->accepts($case));
            $this->assertTrue($case->accepts(PortType::Any));
        }
    }

    public function testFloatAcceptsIntWidening(): void
    {
        $this->assertTrue(PortType::Float->accepts(PortType::Int));
        $this->assertFalse(PortType::Int->accepts(PortType::Float));
    }

    public function testValidatesScalarValues(): void
    {
        $this->assertTrue(PortType::Text->validates('hello'));
        $this->assertFalse(PortType::Text->validates(42));
        $this->assertTrue(PortType::Int->validates(42));
        $this->assertFalse(PortType::Int->validates('42'));
        $this->assertTrue(PortType::Float->validates(1.5));
        $this->assertTrue(PortType::Float->validates(2));
        $this->assertTrue(PortType::Bool->validates(false));
        $this->assertTrue(PortType::Json->validates(['a' => 1]));
        $this->assertFalse(PortType::Json->validates('{"a":1}'));
        $this->assertTrue(PortType::Any->validates(null));
    }
}
```

```php
<?php
// tests/Unit/Node/PortDefinitionTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\PortDefinition;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class PortDefinitionTest extends TestCase
{
    public function testToArrayUsesKeyAsLabelFallbackAndOmitsPropertyName(): void
    {
        $port = new PortDefinition(key: 'order_id', type: PortType::Int, required: true, propertyName: 'orderId');

        $this->assertSame(
            ['key' => 'order_id', 'type' => 'int', 'required' => true, 'label' => 'order_id'],
            $port->toArray(),
        );
    }

    public function testToArrayKeepsExplicitLabel(): void
    {
        $port = new PortDefinition(key: 'name', type: PortType::Text, label: 'Customer name');

        $this->assertSame('Customer name', $port->toArray()['label']);
        $this->assertFalse($port->toArray()['required']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'PortTypeTest|PortDefinitionTest'`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\PortType" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/PortType.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Wire-level type of a node port. Drives connection compatibility in the
 * Studio/graph validator and runtime value validation.
 *
 * Open for extension: add cases (e.g. Money, Binary) together with the
 * first node that needs them.
 *
 * @api
 */
enum PortType: string
{
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Json = 'json';
    case Any = 'any';

    /**
     * Whether a value produced by a `$source`-typed output port may be
     * wired into an input port of this type.
     */
    public function accepts(self $source): bool
    {
        if ($this === self::Any || $source === self::Any) {
            return true;
        }

        if ($this === self::Float && $source === self::Int) {
            return true;
        }

        return $this === $source;
    }

    /**
     * Runtime check that a concrete value conforms to this port type.
     */
    public function validates(mixed $value): bool
    {
        return match ($this) {
            self::Text => is_string($value),
            self::Int => is_int($value),
            self::Float => is_float($value) || is_int($value),
            self::Bool => is_bool($value),
            self::Json => is_array($value),
            self::Any => true,
        };
    }
}
```

```php
<?php
// src/Node/PortDefinition.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Immutable definition of one input or output port of a node.
 *
 * `$propertyName` is the handler property the port hydrates into; it is a
 * reflection detail and is deliberately excluded from {@see toArray()}.
 *
 * @api
 */
final class PortDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly PortType $type,
        public readonly bool $required = false,
        public readonly ?string $label = null,
        public readonly ?string $propertyName = null,
    ) {}

    /**
     * @return array{key: string, type: string, required: bool, label: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'required' => $this->required,
            'label' => $this->label ?? $this->key,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'PortTypeTest|PortDefinitionTest'`
Expected: PASS (7 tests)

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — Expected: all green (iterate until green).

```bash
git add src/Node tests/Unit/Node
git commit -m "feat(node): add PortType enum and PortDefinition value object"
```

---

### Task 2: FlowNode / Input / Output attributes

**Files:**
- Create: `src/Node/Attributes/FlowNode.php`, `src/Node/Attributes/Input.php`, `src/Node/Attributes/Output.php`
- Test: `tests/Unit/Node/AttributesTest.php`

**Interfaces:**
- Consumes: `PortType` (Task 1).
- Produces: `#[FlowNode(type, category='general', name=null, icon=null, description=null)]` (TARGET_CLASS); `#[Input(type, required=false, label=null, key=null)]` and `#[Output(type, label=null, key=null)]` (TARGET_PROPERTY). `key === null` means "derive from property name" (done by the factory in Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Node/AttributesTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Attribute;
use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AttributesTest extends TestCase
{
    public function testFlowNodeTargetsClassesOnly(): void
    {
        $meta = (new ReflectionClass(FlowNode::class))->getAttributes(Attribute::class)[0]->newInstance();
        $this->assertSame(Attribute::TARGET_CLASS, $meta->flags);

        $node = new FlowNode(type: 'test.node');
        $this->assertSame('test.node', $node->type);
        $this->assertSame('general', $node->category);
        $this->assertNull($node->name);
    }

    public function testInputAndOutputTargetPropertiesOnly(): void
    {
        foreach ([Input::class, Output::class] as $attribute) {
            $meta = (new ReflectionClass($attribute))->getAttributes(Attribute::class)[0]->newInstance();
            $this->assertSame(Attribute::TARGET_PROPERTY, $meta->flags, $attribute);
        }

        $input = new Input(type: PortType::Int, required: true);
        $this->assertSame(PortType::Int, $input->type);
        $this->assertTrue($input->required);
        $this->assertNull($input->key);

        $output = new Output(type: PortType::Json, key: 'result');
        $this->assertSame('result', $output->key);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter AttributesTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\Attributes\FlowNode" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/Attributes/FlowNode.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;

/**
 * Marks a class as a flow node handler and declares its catalog identity.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FlowNode
{
    public function __construct(
        public readonly string $type,
        public readonly string $category = 'general',
        public readonly ?string $name = null,
        public readonly ?string $icon = null,
        public readonly ?string $description = null,
    ) {}
}
```

```php
<?php
// src/Node/Attributes/Input.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed input port on a public handler property.
 * `$key` defaults to the property name (snake_case is NOT applied).
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Input
{
    public function __construct(
        public readonly PortType $type,
        public readonly bool $required = false,
        public readonly ?string $label = null,
        public readonly ?string $key = null,
    ) {}
}
```

```php
<?php
// src/Node/Attributes/Output.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Attributes;

use Attribute;
use Padosoft\LaravelFlow\Node\PortType;

/**
 * Declares a typed output port on a public handler property.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Output
{
    public function __construct(
        public readonly PortType $type,
        public readonly ?string $label = null,
        public readonly ?string $key = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Unit --filter AttributesTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Quality gate, commit, open A-PR1**

Run: `composer quality` — Expected: green.

```bash
git add src/Node/Attributes tests/Unit/Node/AttributesTest.php
git commit -m "feat(node): add FlowNode, Input, Output attributes"
git push -u origin task/v2a-01-ports-attributes
gh pr create --base task/v2a-node-contract --title "feat(node): port types and node attributes (A-PR1)" --body "Macro A subtask 1/5 per docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md"
```

Then run the copilot-pr-review-loop skill until G2 is green; merge into the macro branch; `git checkout task/v2a-node-contract && git pull && git checkout -b task/v2a-02-definition-validation`.

---

### Task 3: NodeDefinition + NodeDefinitionFactory (reflection)

**Files:**
- Create: `src/Node/NodeDefinition.php`, `src/Node/NodeDefinitionFactory.php`, `src/Node/Exceptions/InvalidNodeDefinitionException.php`
- Test: `tests/Unit/Node/NodeDefinitionFactoryTest.php`

**Interfaces:**
- Consumes: attributes (Task 2), `PortDefinition`/`PortType` (Task 1).
- Produces: `NodeDefinition` readonly VO (`type, name, category, icon, description, inputs: list<PortDefinition>, outputs: list<PortDefinition>, handlerClass`) with `input(string $key): ?PortDefinition`, `output(string $key): ?PortDefinition`, `toArray(): array`; `NodeDefinitionFactory::fromClass(string $class): NodeDefinition` throwing `InvalidNodeDefinitionException` (extends `\InvalidArgumentException`) on: unknown class, missing `#[FlowNode]`, duplicate input/output port key. (Empty `type` is rejected by the `FlowNode` attribute constructor itself — guard added in A-PR1 — so `fromClass` surfaces a plain `InvalidArgumentException` from `newInstance()` for that case; no factory guard.)

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Node/NodeDefinitionFactoryTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class NodeDefinitionFactoryTest extends TestCase
{
    private NodeDefinitionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new NodeDefinitionFactory();
    }

    public function testBuildsDefinitionFromAttributes(): void
    {
        $handler = new #[FlowNode(type: 'billing.refund', category: 'billing', icon: 'credit-card')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public int $orderId;

            #[Input(type: PortType::Float, key: 'amount', label: 'Refund amount')]
            public ?float $refundAmount = null;

            #[Output(type: PortType::Json)]
            public array $receipt;
        };

        $definition = $this->factory->fromClass($handler::class);

        $this->assertSame('billing.refund', $definition->type);
        $this->assertSame('billing', $definition->category);
        $this->assertSame('credit-card', $definition->icon);
        $this->assertCount(2, $definition->inputs);
        $this->assertCount(1, $definition->outputs);

        $orderId = $definition->input('orderId');
        $this->assertNotNull($orderId);
        $this->assertSame(PortType::Int, $orderId->type);
        $this->assertTrue($orderId->required);
        $this->assertSame('orderId', $orderId->propertyName);

        $amount = $definition->input('amount');
        $this->assertNotNull($amount);
        $this->assertSame('refundAmount', $amount->propertyName);
        $this->assertSame('Refund amount', $amount->label);

        $this->assertNotNull($definition->output('receipt'));
        $this->assertNull($definition->input('missing'));
    }

    public function testNameDefaultsToClassBasename(): void
    {
        $definition = $this->factory->fromClass(\Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode::class);

        $this->assertSame('GreetNode', $definition->name);
        $this->assertSame(\Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode::class, $definition->handlerClass);
    }

    public function testToArrayExposesCatalogShape(): void
    {
        $array = $this->factory->fromClass(\Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode::class)->toArray();

        $this->assertSame('test.greet', $array['type']);
        $this->assertSame(
            [['key' => 'name', 'type' => 'text', 'required' => true, 'label' => 'name']],
            $array['inputs'],
        );
        $this->assertArrayHasKey('outputs', $array);
        $this->assertArrayNotHasKey('handlerClass', $array);
    }

    public function testRejectsClassWithoutFlowNodeAttribute(): void
    {
        $plain = new class {};

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/missing.*FlowNode/i');
        $this->factory->fromClass($plain::class);
    }

    public function testRejectsUnknownClass(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->factory->fromClass('App\\Does\\Not\\Exist');
    }

    // NOTE (A-PR1 local review): empty-type rejection now lives in the
    // FlowNode attribute constructor itself and is covered by
    // AttributesTest::test_flow_node_rejects_empty_type. No factory-level
    // empty-type test or guard is needed here.

    public function testRejectsDuplicateInputPortKeys(): void
    {
        $handler = new #[FlowNode(type: 'dup.node')] class
        {
            #[Input(type: PortType::Text, key: 'same')]
            public string $a;

            #[Input(type: PortType::Text, key: 'same')]
            public string $b;
        };

        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/duplicate input port.*same/i');
        $this->factory->fromClass($handler::class);
    }
}
```

Also create the shared fixture (used again by discovery/registry/catalog tasks). Note: it references `FlowNodeHandler`/`NodeContext`/`NodeResult` which do not exist until Task 5 — so in THIS task create it **without** the interface, and Task 5 upgrades it:

```php
<?php
// tests/Fixtures/Nodes/GreetNode.php  (Task-3 version, upgraded in Task 5)
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.greet', category: 'testing')]
final class GreetNode
{
    #[Input(type: PortType::Text, required: true)]
    public string $name;

    #[Output(type: PortType::Text)]
    public string $greeting;
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeDefinitionFactoryTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeDefinitionFactory" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/Exceptions/InvalidNodeDefinitionException.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a class cannot be turned into a valid NodeDefinition.
 *
 * @api
 */
final class InvalidNodeDefinitionException extends InvalidArgumentException {}
```

```php
<?php
// src/Node/NodeDefinition.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Immutable, catalog-ready description of one node type.
 *
 * @api
 */
final class NodeDefinition
{
    /**
     * @param  list<PortDefinition>  $inputs
     * @param  list<PortDefinition>  $outputs
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $icon,
        public readonly ?string $description,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly string $handlerClass,
    ) {}

    public function input(string $key): ?PortDefinition
    {
        return $this->findPort($this->inputs, $key);
    }

    public function output(string $key): ?PortDefinition
    {
        return $this->findPort($this->outputs, $key);
    }

    /**
     * Catalog projection. Deliberately excludes `handlerClass`
     * (server-side implementation detail).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'category' => $this->category,
            'icon' => $this->icon,
            'description' => $this->description,
            'inputs' => array_map(static fn (PortDefinition $port): array => $port->toArray(), $this->inputs),
            'outputs' => array_map(static fn (PortDefinition $port): array => $port->toArray(), $this->outputs),
        ];
    }

    /**
     * @param  list<PortDefinition>  $ports
     */
    private function findPort(array $ports, string $key): ?PortDefinition
    {
        foreach ($ports as $port) {
            if ($port->key === $key) {
                return $port;
            }
        }

        return null;
    }
}
```

```php
<?php
// src/Node/NodeDefinitionFactory.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use ReflectionClass;

/**
 * Builds {@see NodeDefinition}s from attribute-annotated handler classes.
 *
 * @api
 */
final class NodeDefinitionFactory
{
    /**
     * @param  class-string  $class
     */
    public function fromClass(string $class): NodeDefinition
    {
        if (! class_exists($class)) {
            throw new InvalidNodeDefinitionException("Node handler class [{$class}] does not exist.");
        }

        $reflection = new ReflectionClass($class);
        $nodeAttributes = $reflection->getAttributes(FlowNode::class);

        if ($nodeAttributes === []) {
            throw new InvalidNodeDefinitionException("Class [{$class}] is missing the #[FlowNode] attribute.");
        }

        $node = $nodeAttributes[0]->newInstance();

        [$inputs, $outputs] = $this->collectPorts($reflection, $class);

        return new NodeDefinition(
            type: $node->type,
            name: $node->name ?? $reflection->getShortName(),
            category: $node->category,
            icon: $node->icon,
            description: $node->description,
            inputs: $inputs,
            outputs: $outputs,
            handlerClass: $class,
        );
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @return array{0: list<PortDefinition>, 1: list<PortDefinition>}
     */
    private function collectPorts(ReflectionClass $reflection, string $class): array
    {
        $inputs = [];
        $outputs = [];

        foreach ($reflection->getProperties() as $property) {
            foreach ($property->getAttributes(Input::class) as $attribute) {
                $input = $attribute->newInstance();
                $key = $input->key ?? $property->getName();

                if (isset($inputs[$key])) {
                    throw new InvalidNodeDefinitionException("Duplicate input port [{$key}] on [{$class}].");
                }

                $inputs[$key] = new PortDefinition($key, $input->type, $input->required, $input->label, $property->getName());
            }

            foreach ($property->getAttributes(Output::class) as $attribute) {
                $output = $attribute->newInstance();
                $key = $output->key ?? $property->getName();

                if (isset($outputs[$key])) {
                    throw new InvalidNodeDefinitionException("Duplicate output port [{$key}] on [{$class}].");
                }

                $outputs[$key] = new PortDefinition($key, $output->type, false, $output->label, $property->getName());
            }
        }

        return [array_values($inputs), array_values($outputs)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeDefinitionFactoryTest`
Expected: PASS (7 tests)

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — Expected: green.

```bash
git add src/Node tests/Unit/Node tests/Fixtures
git commit -m "feat(node): reflection-based NodeDefinition factory"
```

---

### Task 4: NodeInputValidator + NodeInputHydrator

**Files:**
- Create: `src/Node/NodeInputValidator.php`, `src/Node/NodeInputHydrator.php`, `src/Node/Exceptions/NodeInputValidationException.php`
- Test: `tests/Unit/Node/NodeInputValidatorTest.php`

**Interfaces:**
- Consumes: `NodeDefinition`/`PortDefinition`/`PortType` (Tasks 1, 3).
- Produces: `NodeInputValidator::validate(NodeDefinition $definition, array $inputs): array<string, mixed>` — returns the validated inputs or throws `NodeInputValidationException`; the exception exposes `violations(): array<string, list<string>>` keyed by port key (key `_unknown` groups unexpected keys). `NodeInputHydrator::hydrate(object $handler, NodeDefinition $definition, array $validatedInputs): void` assigns values onto attributed properties.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Node/NodeInputValidatorTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeInputHydrator;
use Padosoft\LaravelFlow\Node\NodeInputValidator;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class NodeInputValidatorTest extends TestCase
{
    private NodeInputValidator $validator;

    private object $handler;

    protected function setUp(): void
    {
        $this->validator = new NodeInputValidator();
        $this->handler = new #[FlowNode(type: 'test.validate')] class
        {
            #[Input(type: PortType::Int, required: true)]
            public int $count;

            #[Input(type: PortType::Text, key: 'note')]
            public ?string $comment = null;
        };
    }

    private function definition(): \Padosoft\LaravelFlow\Node\NodeDefinition
    {
        return (new NodeDefinitionFactory())->fromClass($this->handler::class);
    }

    public function testValidInputsPassThrough(): void
    {
        $validated = $this->validator->validate($this->definition(), ['count' => 3, 'note' => 'hi']);

        $this->assertSame(['count' => 3, 'note' => 'hi'], $validated);
    }

    public function testOptionalInputMayBeAbsent(): void
    {
        $this->assertSame(['count' => 1], $this->validator->validate($this->definition(), ['count' => 1]));
    }

    public function testMissingRequiredInputViolates(): void
    {
        try {
            $this->validator->validate($this->definition(), ['note' => 'hi']);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('count', $e->violations());
            $this->assertStringContainsString('required', $e->violations()['count'][0]);
        }
    }

    public function testTypeMismatchViolatesPerPort(): void
    {
        try {
            $this->validator->validate($this->definition(), ['count' => 'three', 'note' => 42]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertSame(['count', 'note'], array_keys($e->violations()));
        }
    }

    public function testUnknownInputKeyViolates(): void
    {
        try {
            $this->validator->validate($this->definition(), ['count' => 1, 'ghost' => true]);
            $this->fail('Expected NodeInputValidationException');
        } catch (NodeInputValidationException $e) {
            $this->assertArrayHasKey('_unknown', $e->violations());
            $this->assertStringContainsString('ghost', $e->violations()['_unknown'][0]);
        }
    }

    public function testHydratorAssignsValidatedInputsToProperties(): void
    {
        $definition = $this->definition();
        $validated = $this->validator->validate($definition, ['count' => 5, 'note' => 'ciao']);

        (new NodeInputHydrator())->hydrate($this->handler, $definition, $validated);

        $this->assertSame(5, $this->handler->count);
        $this->assertSame('ciao', $this->handler->comment);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeInputValidatorTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeInputValidator" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/Exceptions/NodeInputValidationException.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/**
 * Raised when node inputs violate the node's port contract. Carries
 * per-port violation messages; the reserved key `_unknown` groups
 * inputs that match no declared port.
 *
 * @api
 */
final class NodeInputValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct('Node input validation failed: '.json_encode($violations));
    }

    /**
     * @return array<string, list<string>>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
```

```php
<?php
// src/Node/NodeInputValidator.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Exceptions\NodeInputValidationException;

/**
 * Enforces a node's input port contract BEFORE the handler runs, so a
 * malformed payload can never burn a side effect or provider call.
 *
 * @api
 */
final class NodeInputValidator
{
    /**
     * @param  array<string, mixed>  $inputs  keyed by input port key
     * @return array<string, mixed> validated inputs (known ports only)
     *
     * @throws NodeInputValidationException
     */
    public function validate(NodeDefinition $definition, array $inputs): array
    {
        $violations = [];
        $validated = [];
        $known = [];

        foreach ($definition->inputs as $port) {
            $known[$port->key] = true;

            if (! array_key_exists($port->key, $inputs)) {
                if ($port->required) {
                    $violations[$port->key][] = "Input [{$port->key}] is required.";
                }

                continue;
            }

            $value = $inputs[$port->key];

            if (! $port->type->validates($value)) {
                $violations[$port->key][] = "Input [{$port->key}] must be of type [{$port->type->value}], got [".get_debug_type($value).'].';

                continue;
            }

            $validated[$port->key] = $value;
        }

        foreach (array_keys($inputs) as $key) {
            if (! isset($known[$key])) {
                $violations['_unknown'][] = "Unknown input port [{$key}].";
            }
        }

        if ($violations !== []) {
            throw new NodeInputValidationException($violations);
        }

        return $validated;
    }
}
```

```php
<?php
// src/Node/NodeInputHydrator.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Assigns validated inputs onto the handler's attributed properties, so
 * handlers read typed properties (spec §3.1) instead of a loose array.
 *
 * @api
 */
final class NodeInputHydrator
{
    /**
     * @param  array<string, mixed>  $validatedInputs  output of {@see NodeInputValidator::validate()}
     */
    public function hydrate(object $handler, NodeDefinition $definition, array $validatedInputs): void
    {
        foreach ($definition->inputs as $port) {
            if ($port->propertyName === null || ! array_key_exists($port->key, $validatedInputs)) {
                continue;
            }

            $handler->{$port->propertyName} = $validatedInputs[$port->key];
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeInputValidatorTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Quality gate, commit, open A-PR2**

Run: `composer quality` — Expected: green.

```bash
git add src/Node tests/Unit/Node
git commit -m "feat(node): enforced input validation and property hydration"
git push -u origin task/v2a-02-definition-validation
gh pr create --base task/v2a-node-contract --title "feat(node): node definitions and input validation (A-PR2)" --body "Macro A subtask 2/5 per docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md"
```

Copilot loop until G2 green; merge; `git checkout task/v2a-node-contract && git pull && git checkout -b task/v2a-03-handler-registry`.

---

### Task 5: FlowNodeHandler + NodeContext + NodeResult

**Files:**
- Create: `src/Node/FlowNodeHandler.php`, `src/Node/NodeContext.php`, `src/Node/NodeResult.php`
- Modify: `tests/Fixtures/Nodes/GreetNode.php` (implement the interface)
- Test: `tests/Unit/Node/NodeResultTest.php`

**Interfaces:**
- Produces: `interface FlowNodeHandler { public function execute(NodeContext $context): NodeResult; }`; `NodeContext(flowRunId, definitionName, nodeId, inputs: array<string,mixed>, dryRun=false)` readonly; `NodeResult(success, outputs, ?Throwable error, ?array businessImpact, bool dryRunSkipped, bool paused)` with factories `success(array $outputs = [], ?array $businessImpact = null)`, `failed(Throwable $error)`, `dryRunSkipped()`, `paused(array $outputs = [], ?array $businessImpact = null)` — 1:1 parity with `FlowStepResult` so the v1 adapter (Task 9) maps losslessly.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Node/NodeResultTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NodeResultTest extends TestCase
{
    public function testFactoriesMirrorFlowStepResultSemantics(): void
    {
        $ok = NodeResult::success(['greeting' => 'ciao'], ['emails_sent' => 1]);
        $this->assertTrue($ok->success);
        $this->assertSame(['greeting' => 'ciao'], $ok->outputs);
        $this->assertSame(['emails_sent' => 1], $ok->businessImpact);
        $this->assertFalse($ok->dryRunSkipped);
        $this->assertFalse($ok->paused);

        $error = new RuntimeException('boom');
        $failed = NodeResult::failed($error);
        $this->assertFalse($failed->success);
        $this->assertSame($error, $failed->error);
        $this->assertSame([], $failed->outputs);

        $skipped = NodeResult::dryRunSkipped();
        $this->assertTrue($skipped->success);
        $this->assertTrue($skipped->dryRunSkipped);

        $paused = NodeResult::paused(['token' => 'x']);
        $this->assertTrue($paused->paused);
        $this->assertSame(['token' => 'x'], $paused->outputs);
    }

    public function testHandlerExecutesAgainstContext(): void
    {
        $context = new NodeContext(
            flowRunId: 'run-1',
            definitionName: 'demo',
            nodeId: 'node-1',
            inputs: ['name' => 'Ada'],
        );

        $result = (new GreetNode())->execute($context);

        $this->assertTrue($result->success);
        $this->assertSame(['greeting' => 'Hello Ada'], $result->outputs);
        $this->assertFalse($context->dryRun);
    }
}
```

Upgrade the fixture:

```php
<?php
// tests/Fixtures/Nodes/GreetNode.php  (final version)
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.greet', category: 'testing')]
final class GreetNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $name;

    #[Output(type: PortType::Text)]
    public string $greeting;

    public function execute(NodeContext $context): NodeResult
    {
        $name = $context->inputs['name'];
        assert(is_string($name));

        return NodeResult::success(['greeting' => 'Hello '.$name]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeResultTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeResult" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/NodeContext.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Readonly execution context handed to every node handler.
 * `$inputs` is keyed by input port key and already validated.
 *
 * @api
 */
final class NodeContext
{
    /**
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public readonly string $flowRunId,
        public readonly string $definitionName,
        public readonly string $nodeId,
        public readonly array $inputs,
        public readonly bool $dryRun = false,
    ) {}
}
```

```php
<?php
// src/Node/NodeResult.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Throwable;

/**
 * Readonly DTO summarising one node execution. Factory semantics mirror
 * {@see \Padosoft\LaravelFlow\FlowStepResult} 1:1 so v1 step results map
 * losslessly through the legacy adapter.
 *
 * @api
 */
final class NodeResult
{
    /**
     * @param  array<string, mixed>  $outputs  keyed by output port key
     * @param  array<string, mixed>|null  $businessImpact
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $outputs,
        public readonly ?Throwable $error,
        public readonly ?array $businessImpact,
        public readonly bool $dryRunSkipped,
        public readonly bool $paused,
    ) {}

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function success(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, false);
    }

    public static function failed(Throwable $error): self
    {
        return new self(false, [], $error, null, false, false);
    }

    public static function dryRunSkipped(): self
    {
        return new self(true, [], null, null, true, false);
    }

    /**
     * @param  array<string, mixed>  $outputs
     * @param  array<string, mixed>|null  $businessImpact
     */
    public static function paused(array $outputs = [], ?array $businessImpact = null): self
    {
        return new self(true, $outputs, null, $businessImpact, false, true);
    }
}
```

```php
<?php
// src/Node/FlowNodeHandler.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

/**
 * Contract for every graph node handler. Implementations are resolved
 * through the Laravel container and MUST be annotated with #[FlowNode]
 * (the registry rejects them otherwise). Handlers MUST honour
 * `$context->dryRun`: when true, no persistent state may be mutated.
 *
 * @api
 */
interface FlowNodeHandler
{
    public function execute(NodeContext $context): NodeResult;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeResultTest|NodeDefinitionFactoryTest'`
Expected: PASS (fixture change must not break factory tests)

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — Expected: green.

```bash
git add src/Node tests
git commit -m "feat(node): FlowNodeHandler contract with NodeContext and NodeResult"
```

---

### Task 6: NodeRegistry + config + service provider wiring

**Files:**
- Create: `src/Node/NodeRegistry.php`, `src/Node/Exceptions/DuplicateNodeTypeException.php`, `src/Node/Exceptions/UnknownNodeTypeException.php`
- Modify: `config/laravel-flow.php` (add `nodes` key), `src/LaravelFlowServiceProvider.php` (singletons)
- Test: `tests/Unit/Node/NodeRegistryTest.php`, `tests/Unit/Node/NodeRegistryWiringTest.php`

**Interfaces:**
- Consumes: `NodeDefinitionFactory` (Task 3), `FlowNodeHandler` (Task 5).
- Produces: `NodeRegistry` — `register(string $handlerClass): NodeDefinition`, `registerMany(array $handlerClasses): void`, `has(string $type): bool`, `get(string $type): NodeDefinition`, `all(): array<string, NodeDefinition>` (keyed by type, sorted). Container singleton pre-loaded from `config('laravel-flow.nodes.handlers')`. Registry rejects classes not implementing `FlowNodeHandler` (`InvalidNodeDefinitionException`) and duplicate types (`DuplicateNodeTypeException`); `get` on unknown type throws `UnknownNodeTypeException`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Node/NodeRegistryTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use PHPUnit\Framework\TestCase;

final class NodeRegistryTest extends TestCase
{
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new NodeRegistry(new NodeDefinitionFactory());
    }

    public function testRegisterAndRetrieve(): void
    {
        $definition = $this->registry->register(GreetNode::class);

        $this->assertSame('test.greet', $definition->type);
        $this->assertTrue($this->registry->has('test.greet'));
        $this->assertSame($definition, $this->registry->get('test.greet'));
        $this->assertSame(['test.greet'], array_keys($this->registry->all()));
    }

    public function testDuplicateTypeThrows(): void
    {
        $this->registry->register(GreetNode::class);

        $this->expectException(DuplicateNodeTypeException::class);
        $this->registry->register(GreetNode::class);
    }

    public function testNonHandlerClassIsRejected(): void
    {
        $this->expectException(InvalidNodeDefinitionException::class);
        $this->expectExceptionMessageMatches('/FlowNodeHandler/');
        $this->registry->register(\Padosoft\LaravelFlow\FlowContext::class);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(UnknownNodeTypeException::class);
        $this->registry->get('missing.type');
    }
}
```

```php
<?php
// tests/Unit/Node/NodeRegistryWiringTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class NodeRegistryWiringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    public function testRegistryIsSingletonAndLoadsConfiguredHandlers(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertSame($registry, $this->app->make(NodeRegistry::class));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeRegistryTest|NodeRegistryWiringTest'`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeRegistry" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/Exceptions/DuplicateNodeTypeException.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/** @api */
final class DuplicateNodeTypeException extends RuntimeException {}
```

```php
<?php
// src/Node/Exceptions/UnknownNodeTypeException.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node\Exceptions;

use RuntimeException;

/** @api */
final class UnknownNodeTypeException extends RuntimeException {}
```

```php
<?php
// src/Node/NodeRegistry.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Exceptions\DuplicateNodeTypeException;
use Padosoft\LaravelFlow\Node\Exceptions\InvalidNodeDefinitionException;
use Padosoft\LaravelFlow\Node\Exceptions\UnknownNodeTypeException;

/**
 * Single source of truth for available node types: feeds executor
 * resolution, the JSON catalog (Studio palette) and, later, MCP tool
 * schema generation. Replaces hand-maintained whitelists.
 *
 * @api
 */
final class NodeRegistry
{
    /** @var array<string, NodeDefinition> */
    private array $definitions = [];

    public function __construct(private readonly NodeDefinitionFactory $factory) {}

    /**
     * @param  class-string  $handlerClass
     */
    public function register(string $handlerClass): NodeDefinition
    {
        if (! is_a($handlerClass, FlowNodeHandler::class, true)) {
            throw new InvalidNodeDefinitionException(
                "Node handler [{$handlerClass}] must implement ".FlowNodeHandler::class.'.'
            );
        }

        $definition = $this->factory->fromClass($handlerClass);

        if (isset($this->definitions[$definition->type])) {
            throw new DuplicateNodeTypeException("Node type [{$definition->type}] is already registered.");
        }

        $this->definitions[$definition->type] = $definition;

        return $definition;
    }

    /**
     * @param  list<class-string>  $handlerClasses
     */
    public function registerMany(array $handlerClasses): void
    {
        foreach ($handlerClasses as $handlerClass) {
            $this->register($handlerClass);
        }
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): NodeDefinition
    {
        return $this->definitions[$type]
            ?? throw new UnknownNodeTypeException("Node type [{$type}] is not registered.");
    }

    /**
     * @return array<string, NodeDefinition> keyed by type, sorted by type
     */
    public function all(): array
    {
        $all = $this->definitions;
        ksort($all);

        return $all;
    }
}
```

Config addition — append to the returned array in `config/laravel-flow.php` (keep existing keys untouched):

```php
    /*
    |--------------------------------------------------------------------------
    | Node catalog (v2 graph engine)
    |--------------------------------------------------------------------------
    | `handlers`: FlowNodeHandler class-strings registered at boot.
    | `discovery`: PSR-4 roots scanned for #[FlowNode] handlers, e.g.
    |   ['path' => app_path('Flow/Nodes'), 'namespace' => 'App\\Flow\\Nodes'].
    */
    'nodes' => [
        'handlers' => [],
        'discovery' => [],
    ],
```

Service provider — add to `register()` in `src/LaravelFlowServiceProvider.php`, following the existing singleton style (imports at top: `use Padosoft\LaravelFlow\Node\NodeDefinitionFactory; use Padosoft\LaravelFlow\Node\NodeRegistry;`):

```php
        $this->app->singleton(NodeDefinitionFactory::class);
        $this->app->singleton(NodeRegistry::class, function (Container $app): NodeRegistry {
            $registry = new NodeRegistry($app->make(NodeDefinitionFactory::class));
            /** @var list<class-string> $handlers */
            $handlers = $app['config']->get('laravel-flow.nodes.handlers', []);
            $registry->registerMany($handlers);

            return $registry;
        });
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeRegistryTest|NodeRegistryWiringTest'`
Expected: PASS (5 tests)

- [ ] **Step 5: Quality gate, commit, open A-PR3**

Run: `composer quality` — Expected: green (note: Architecture suite must stay green — no new Illuminate imports inside `src/Node/`).

```bash
git add src/Node src/LaravelFlowServiceProvider.php config/laravel-flow.php tests
git commit -m "feat(node): NodeRegistry with container wiring and config-driven registration"
git push -u origin task/v2a-03-handler-registry
gh pr create --base task/v2a-node-contract --title "feat(node): handler contract and registry (A-PR3)" --body "Macro A subtask 3/5 per docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md"
```

Copilot loop until G2 green; merge; `git checkout task/v2a-node-contract && git pull && git checkout -b task/v2a-04-discovery-catalog`.

---

### Task 7: NodeDiscovery (PSR-4 path scan)

**Files:**
- Create: `src/Node/NodeDiscovery.php`
- Create fixtures: `tests/Fixtures/Nodes/UpperNode.php`, `tests/Fixtures/Nodes/NotANode.php`
- Modify: `src/LaravelFlowServiceProvider.php` (feed discovery config into the registry singleton)
- Test: `tests/Unit/Node/NodeDiscoveryTest.php`

**Interfaces:**
- Produces: `NodeDiscovery::discover(string $path, string $namespace): list<class-string>` — recursively scans `*.php` under `$path`, maps relative paths to FQCNs under `$namespace` (PSR-4), returns classes that carry `#[FlowNode]` AND implement `FlowNodeHandler`, sorted. Nonexistent path returns `[]`.

- [ ] **Step 1: Write fixtures and the failing test**

```php
<?php
// tests/Fixtures/Nodes/UpperNode.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use Padosoft\LaravelFlow\Node\Attributes\Input;
use Padosoft\LaravelFlow\Node\Attributes\Output;
use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;

#[FlowNode(type: 'test.upper', category: 'testing')]
final class UpperNode implements FlowNodeHandler
{
    #[Input(type: PortType::Text, required: true)]
    public string $text;

    #[Output(type: PortType::Text)]
    public string $upper;

    public function execute(NodeContext $context): NodeResult
    {
        $text = $context->inputs['text'];
        assert(is_string($text));

        return NodeResult::success(['upper' => mb_strtoupper($text)]);
    }
}
```

```php
<?php
// tests/Fixtures/Nodes/NotANode.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Fixtures\Nodes;

final class NotANode
{
    public function irrelevant(): string
    {
        return 'not a node';
    }
}
```

```php
<?php
// tests/Unit/Node/NodeDiscoveryTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeDiscovery;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class NodeDiscoveryTest extends TestCase
{
    public function testDiscoversOnlyAttributedHandlerClasses(): void
    {
        $found = (new NodeDiscovery())->discover(
            __DIR__.'/../../Fixtures/Nodes',
            'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        );

        $this->assertSame([GreetNode::class, UpperNode::class], $found);
    }

    public function testNonexistentPathReturnsEmpty(): void
    {
        $this->assertSame([], (new NodeDiscovery())->discover(__DIR__.'/nope', 'App\\Nope'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter NodeDiscoveryTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeDiscovery" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/NodeDiscovery.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\Node\Attributes\FlowNode;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Scans a PSR-4 mapped directory for #[FlowNode]-attributed classes
 * implementing {@see FlowNodeHandler}. Classes are resolved through the
 * autoloader, never by including files directly.
 *
 * @api
 */
final class NodeDiscovery
{
    /**
     * @return list<class-string> sorted FQCNs
     */
    public function discover(string $path, string $namespace): array
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_dir($realPath)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($realPath) + 1, -4);
            $class = rtrim($namespace, '\\').'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(FlowNode::class) === []) {
                continue;
            }

            if (! $reflection->implementsInterface(FlowNodeHandler::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }
}
```

Service provider — extend the `NodeRegistry` singleton closure from Task 6 (import `Padosoft\LaravelFlow\Node\NodeDiscovery`):

```php
        $this->app->singleton(NodeRegistry::class, function (Container $app): NodeRegistry {
            $registry = new NodeRegistry($app->make(NodeDefinitionFactory::class));
            /** @var list<class-string> $handlers */
            $handlers = $app['config']->get('laravel-flow.nodes.handlers', []);
            $registry->registerMany($handlers);

            /** @var list<array{path: string, namespace: string}> $roots */
            $roots = $app['config']->get('laravel-flow.nodes.discovery', []);
            $discovery = new NodeDiscovery();

            foreach ($roots as $root) {
                foreach ($discovery->discover($root['path'], $root['namespace']) as $class) {
                    if (! $registry->has($app->make(NodeDefinitionFactory::class)->fromClass($class)->type)) {
                        $registry->register($class);
                    }
                }
            }

            return $registry;
        });
```

Add a wiring assertion to `tests/Unit/Node/NodeRegistryWiringTest.php`:

```php
    public function testDiscoveryRootsAreRegistered(): void
    {
        $this->app['config']->set('laravel-flow.nodes.handlers', []);
        $this->app['config']->set('laravel-flow.nodes.discovery', [[
            'path' => __DIR__.'/../../Fixtures/Nodes',
            'namespace' => 'Padosoft\\LaravelFlow\\Tests\\Fixtures\\Nodes',
        ]]);
        $this->app->forgetInstance(\Padosoft\LaravelFlow\Node\NodeRegistry::class);

        $registry = $this->app->make(\Padosoft\LaravelFlow\Node\NodeRegistry::class);

        $this->assertTrue($registry->has('test.greet'));
        $this->assertTrue($registry->has('test.upper'));
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeDiscoveryTest|NodeRegistryWiringTest'`
Expected: PASS (4 tests)

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — Expected: green.

```bash
git add src tests
git commit -m "feat(node): PSR-4 attribute-driven node discovery"
```

---

### Task 8: NodeCatalog + flow:nodes command

**Files:**
- Create: `src/Node/NodeCatalog.php`, `src/Console/NodeCatalogCommand.php`
- Modify: `src/LaravelFlowServiceProvider.php` (register command in the existing `$this->commands([...])` block, singleton for catalog)
- Test: `tests/Unit/Node/NodeCatalogTest.php`, `tests/Unit/Node/NodeCatalogCommandTest.php`

**Interfaces:**
- Consumes: `NodeRegistry` (Task 6).
- Produces: `NodeCatalog` (`@api`) — `public const SCHEMA_VERSION = 1;`, `toArray(): array{schema_version: int, nodes: list<array<string, mixed>>}` (nodes sorted by type), `toJson(int $flags = 0): string`. Console command `flow:nodes {--json}` (`@internal`, like other commands).

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Unit/Node/NodeCatalogTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\NodeCatalog;
use Padosoft\LaravelFlow\Node\NodeDefinitionFactory;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\UpperNode;
use PHPUnit\Framework\TestCase;

final class NodeCatalogTest extends TestCase
{
    public function testCatalogShape(): void
    {
        $registry = new NodeRegistry(new NodeDefinitionFactory());
        $registry->registerMany([UpperNode::class, GreetNode::class]);

        $catalog = (new NodeCatalog($registry))->toArray();

        $this->assertSame(NodeCatalog::SCHEMA_VERSION, $catalog['schema_version']);
        $this->assertSame(['test.greet', 'test.upper'], array_column($catalog['nodes'], 'type'));
        $this->assertSame('text', $catalog['nodes'][0]['inputs'][0]['type']);

        $decoded = json_decode((new NodeCatalog($registry))->toJson(), true);
        $this->assertSame($catalog, $decoded);
    }
}
```

```php
<?php
// tests/Unit/Node/NodeCatalogCommandTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Orchestra\Testbench\TestCase;
use Padosoft\LaravelFlow\LaravelFlowServiceProvider;
use Padosoft\LaravelFlow\Tests\Fixtures\Nodes\GreetNode;

final class NodeCatalogCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFlowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laravel-flow.nodes.handlers', [GreetNode::class]);
    }

    public function testJsonOutputIsValidCatalog(): void
    {
        $this->artisan('flow:nodes', ['--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"test.greet"');
    }

    public function testTableOutputListsTypes(): void
    {
        $this->artisan('flow:nodes')
            ->assertExitCode(0)
            ->expectsOutputToContain('test.greet');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeCatalogTest|NodeCatalogCommandTest'`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\NodeCatalog" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/NodeCatalog.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use JsonException;

/**
 * Serializes the registry into the versioned catalog consumed by the
 * Studio palette and external tooling.
 *
 * @api
 */
final class NodeCatalog
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @return array{schema_version: int, nodes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'nodes' => array_values(array_map(
                static fn (NodeDefinition $definition): array => $definition->toArray(),
                $this->registry->all(),
            )),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
```

```php
<?php
// src/Console/NodeCatalogCommand.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Console;

use Illuminate\Console\Command;
use Padosoft\LaravelFlow\Node\NodeCatalog;

/**
 * @internal
 */
final class NodeCatalogCommand extends Command
{
    protected $signature = 'flow:nodes {--json : Output the catalog as JSON}';

    protected $description = 'List registered flow node types and their ports';

    public function handle(NodeCatalog $catalog): int
    {
        if ((bool) $this->option('json')) {
            $this->line($catalog->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (array $node): array => [
                $node['type'],
                $node['category'],
                count($node['inputs']),
                count($node['outputs']),
            ],
            $catalog->toArray()['nodes'],
        );

        $this->table(['Type', 'Category', 'Inputs', 'Outputs'], $rows);

        return self::SUCCESS;
    }
}
```

Service provider: add `NodeCatalogCommand::class` to the existing `$this->commands([...])` array (around `src/LaravelFlowServiceProvider.php:160`) and `$this->app->singleton(NodeCatalog::class);` next to the registry singleton (import both classes).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite Unit --filter 'NodeCatalogTest|NodeCatalogCommandTest'`
Expected: PASS (3 tests)

- [ ] **Step 5: Quality gate, commit, open A-PR4**

Run: `composer quality` — Expected: green.

```bash
git add src tests
git commit -m "feat(node): versioned node catalog and flow:nodes command"
git push -u origin task/v2a-04-discovery-catalog
gh pr create --base task/v2a-node-contract --title "feat(node): discovery and catalog (A-PR4)" --body "Macro A subtask 4/5 per docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md"
```

Copilot loop until G2 green; merge; `git checkout task/v2a-node-contract && git pull && git checkout -b task/v2a-05-adapter-pinning`.

---

### Task 9: LegacyStepNodeAdapter (v1 → v2 bridge)

**Files:**
- Create: `src/Node/LegacyStepNodeAdapter.php`
- Test: `tests/Unit/Node/LegacyStepNodeAdapterTest.php`

**Interfaces:**
- Consumes: v1 `FlowStepHandler`/`FlowContext`/`FlowStepResult` (existing), `NodeContext`/`NodeResult`/`NodeDefinition`/`PortDefinition` (Tasks 1–5).
- Produces: `LegacyStepNodeAdapter implements FlowNodeHandler` — `__construct(FlowStepHandler $step)`; `static definitionFor(string $nodeType, string $stepHandlerClass): NodeDefinition` (ports: input `input` Json optional, output `output` Json); `execute()` bridges `NodeContext` → `FlowContext` (v1 input = the `input` port array, empty `stepOutputs`, dryRun passthrough) and maps `FlowStepResult` → `NodeResult` 1:1 (success/failed/dryRunSkipped/paused, output under the `output` port key, businessImpact preserved).

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Node/LegacyStepNodeAdapterTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;
use Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter;
use Padosoft\LaravelFlow\Node\NodeContext;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyStepNodeAdapterTest extends TestCase
{
    private function context(array $inputs, bool $dryRun = false): NodeContext
    {
        return new NodeContext('run-9', 'legacy-demo', 'node-9', $inputs, $dryRun);
    }

    public function testDefinitionExposesJsonInOutPorts(): void
    {
        $step = new class implements FlowStepHandler
        {
            public function execute(FlowContext $context): FlowStepResult
            {
                return FlowStepResult::success();
            }
        };

        $definition = LegacyStepNodeAdapter::definitionFor('legacy.demo', $step::class);

        $this->assertSame('legacy.demo', $definition->type);
        $this->assertSame(PortType::Json, $definition->input('input')?->type);
        $this->assertFalse($definition->input('input')->required);
        $this->assertSame(PortType::Json, $definition->output('output')?->type);
        $this->assertSame($step::class, $definition->handlerClass);
    }

    public function testSuccessMapsOutputAndImpactAndContext(): void
    {
        $step = new class implements FlowStepHandler
        {
            public ?FlowContext $seen = null;

            public function execute(FlowContext $context): FlowStepResult
            {
                $this->seen = $context;

                return FlowStepResult::success(['total' => 7], ['orders' => 1]);
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context(['input' => ['sku' => 'A1']], dryRun: true));

        $this->assertTrue($result->success);
        $this->assertSame(['output' => ['total' => 7]], $result->outputs);
        $this->assertSame(['orders' => 1], $result->businessImpact);
        $this->assertSame('run-9', $step->seen->flowRunId);
        $this->assertSame(['sku' => 'A1'], $step->seen->input);
        $this->assertTrue($step->seen->dryRun);
        $this->assertSame([], $step->seen->stepOutputs);
    }

    public function testFailureAndControlResultsMapOneToOne(): void
    {
        $error = new RuntimeException('legacy boom');
        $step = new class($error) implements FlowStepHandler
        {
            public function __construct(private readonly \Throwable $error) {}

            public function execute(FlowContext $context): FlowStepResult
            {
                return match ($context->input['mode']) {
                    'fail' => FlowStepResult::failed($this->error),
                    'skip' => FlowStepResult::dryRunSkipped(),
                    default => FlowStepResult::paused(['token' => 't']),
                };
            }
        };
        $adapter = new LegacyStepNodeAdapter($step);

        $failed = $adapter->execute($this->context(['input' => ['mode' => 'fail']]));
        $this->assertFalse($failed->success);
        $this->assertSame($error, $failed->error);

        $skipped = $adapter->execute($this->context(['input' => ['mode' => 'skip']]));
        $this->assertTrue($skipped->dryRunSkipped);

        $paused = $adapter->execute($this->context(['input' => ['mode' => 'pause']]));
        $this->assertTrue($paused->paused);
        $this->assertSame(['output' => ['token' => 't']], $paused->outputs);
    }

    public function testMissingInputPortDefaultsToEmptyArray(): void
    {
        $step = new class implements FlowStepHandler
        {
            public function execute(FlowContext $context): FlowStepResult
            {
                return FlowStepResult::success(['echo' => $context->input]);
            }
        };

        $result = (new LegacyStepNodeAdapter($step))->execute($this->context([]));

        $this->assertSame(['output' => ['echo' => []]], $result->outputs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite Unit --filter LegacyStepNodeAdapterTest`
Expected: ERROR — `Class "Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php
// src/Node/LegacyStepNodeAdapter.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Node;

use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepHandler;
use Padosoft\LaravelFlow\FlowStepResult;

/**
 * Runs a v1 {@see FlowStepHandler} as a v2 graph node. The step's whole
 * input array travels on the single `input` Json port and its output on
 * the `output` Json port; result semantics map 1:1.
 *
 * @api
 */
final class LegacyStepNodeAdapter implements FlowNodeHandler
{
    public function __construct(private readonly FlowStepHandler $step) {}

    /**
     * @param  class-string<FlowStepHandler>  $stepHandlerClass
     */
    public static function definitionFor(string $nodeType, string $stepHandlerClass): NodeDefinition
    {
        return new NodeDefinition(
            type: $nodeType,
            name: substr($stepHandlerClass, (int) strrpos($stepHandlerClass, '\\') + 1) ?: $stepHandlerClass,
            category: 'legacy',
            icon: null,
            description: 'v1 FlowStepHandler adapter for '.$stepHandlerClass,
            inputs: [new PortDefinition('input', PortType::Json)],
            outputs: [new PortDefinition('output', PortType::Json)],
            handlerClass: $stepHandlerClass,
        );
    }

    public function execute(NodeContext $context): NodeResult
    {
        $input = $context->inputs['input'] ?? [];
        assert(is_array($input));

        $result = $this->step->execute(new FlowContext(
            flowRunId: $context->flowRunId,
            definitionName: $context->definitionName,
            input: $input,
            stepOutputs: [],
            dryRun: $context->dryRun,
        ));

        return $this->mapResult($result);
    }

    private function mapResult(FlowStepResult $result): NodeResult
    {
        if ($result->dryRunSkipped) {
            return NodeResult::dryRunSkipped();
        }

        if ($result->paused) {
            return NodeResult::paused(['output' => $result->output], $result->businessImpact);
        }

        if (! $result->success) {
            return NodeResult::failed($result->error ?? new \RuntimeException('Legacy step failed without error detail.'));
        }

        return NodeResult::success(['output' => $result->output], $result->businessImpact);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite Unit --filter LegacyStepNodeAdapterTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Quality gate, then commit**

Run: `composer quality` — Expected: green.

```bash
git add src/Node tests
git commit -m "feat(node): legacy FlowStepHandler adapter for graph nodes"
```

---

### Task 10: Contract + Architecture pinning, macro closure

**Files:**
- Create: `tests/Contract/NodeApiContractTest.php`, `tests/Architecture/NodeNamespaceTest.php`
- Modify: `docs/PROGRESS.md` (macro A entry)

**Interfaces:**
- Consumes: everything from Tasks 1–9.
- Produces: pinned `@api` surface for the `Node` namespace; architecture guarantee that `src/Node` stays framework-free (no `Illuminate\` imports — the command lives in `Console`).

- [ ] **Step 1: Write the failing tests**

Before writing, open `tests/Contract/PublicApiContractTest.php` and mirror its assertion style if it differs from below (stronger existing patterns win; the assertions below are the minimum).

```php
<?php
// tests/Contract/NodeApiContractTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Padosoft\LaravelFlow\Node\FlowNodeHandler;
use Padosoft\LaravelFlow\Node\NodeCatalog;
use Padosoft\LaravelFlow\Node\NodeRegistry;
use Padosoft\LaravelFlow\Node\NodeResult;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;

final class NodeApiContractTest extends TestCase
{
    public function testPortTypeCasesArePinned(): void
    {
        $this->assertSame(
            ['text', 'int', 'float', 'bool', 'json', 'any'],
            array_map(static fn (PortType $c): string => $c->value, PortType::cases()),
        );
        $this->assertNotEmpty((new ReflectionEnum(PortType::class))->getMethod('accepts'));
    }

    public function testFlowNodeHandlerSignatureIsPinned(): void
    {
        $method = (new ReflectionClass(FlowNodeHandler::class))->getMethod('execute');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(\Padosoft\LaravelFlow\Node\NodeResult::class, $returnType->getName());
        $this->assertSame(\Padosoft\LaravelFlow\Node\NodeContext::class, $method->getParameters()[0]->getType()?->getName());
    }

    public function testNodeResultFactoriesArePinned(): void
    {
        foreach (['success', 'failed', 'dryRunSkipped', 'paused'] as $factory) {
            $this->assertTrue((new ReflectionClass(NodeResult::class))->getMethod($factory)->isStatic(), $factory);
        }
    }

    public function testRegistryAndCatalogPublicMethodsArePinned(): void
    {
        foreach (['register', 'registerMany', 'has', 'get', 'all'] as $method) {
            $this->assertTrue((new ReflectionClass(NodeRegistry::class))->hasMethod($method), $method);
        }

        $this->assertSame(1, NodeCatalog::SCHEMA_VERSION);
    }

    public function testNodeApiClassesAreAnnotatedApi(): void
    {
        $classes = [
            \Padosoft\LaravelFlow\Node\PortType::class,
            \Padosoft\LaravelFlow\Node\PortDefinition::class,
            \Padosoft\LaravelFlow\Node\Attributes\FlowNode::class,
            \Padosoft\LaravelFlow\Node\Attributes\Input::class,
            \Padosoft\LaravelFlow\Node\Attributes\Output::class,
            \Padosoft\LaravelFlow\Node\NodeDefinition::class,
            \Padosoft\LaravelFlow\Node\NodeDefinitionFactory::class,
            \Padosoft\LaravelFlow\Node\NodeInputValidator::class,
            \Padosoft\LaravelFlow\Node\NodeInputHydrator::class,
            \Padosoft\LaravelFlow\Node\FlowNodeHandler::class,
            \Padosoft\LaravelFlow\Node\NodeContext::class,
            \Padosoft\LaravelFlow\Node\NodeResult::class,
            \Padosoft\LaravelFlow\Node\NodeRegistry::class,
            \Padosoft\LaravelFlow\Node\NodeDiscovery::class,
            \Padosoft\LaravelFlow\Node\NodeCatalog::class,
            \Padosoft\LaravelFlow\Node\LegacyStepNodeAdapter::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }
}
```

```php
<?php
// tests/Architecture/NodeNamespaceTest.php
declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NodeNamespaceTest extends TestCase
{
    public function testNodeNamespaceIsFrameworkFree(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../src/Node', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString(
                'use Illuminate\\',
                $source,
                "src/Node must stay standalone-agnostic; found Illuminate import in {$file->getFilename()}",
            );
        }
    }
}
```

- [ ] **Step 2: Run tests to verify current state**

Run: `vendor/bin/phpunit --testsuite Contract --filter NodeApiContractTest && vendor/bin/phpunit --testsuite Architecture --filter NodeNamespaceTest`
Expected: PASS if Tasks 1–9 were faithful (these tests are pins, not new behavior). Any FAIL here means an earlier task drifted — fix the source (or the missing `@api` docblock), never the pin.

- [ ] **Step 3: Update PROGRESS**

Append to `docs/PROGRESS.md` following its existing entry format: Macro A (Node Contract & Registry) subtasks merged, gates green, `@api` surface pinned, adapter delivered; link this plan file.

- [ ] **Step 4: Full quality gate**

Run: `composer quality`
Expected: all suites green. Also run the test-count-readme-sync skill check: if README claims test counts, reconcile.

- [ ] **Step 5: Commit, open A-PR5, close the macro**

```bash
git add tests docs/PROGRESS.md
git commit -m "test(contract): pin Node namespace @api surface and architecture invariants"
git push -u origin task/v2a-05-adapter-pinning
gh pr create --base task/v2a-node-contract --title "feat(node): legacy adapter and API pinning (A-PR5)" --body "Macro A subtask 5/5 per docs/superpowers/plans/2026-07-07-macro-a-node-contract-registry.md"
```

Copilot loop until G2 green; merge A-PR5. Then open the **macro PR** `task/v2a-node-contract → main`, run G2 on it, merge.

**Macro Gate G3 checklist (all must be verified with evidence):**
- [ ] `composer quality` green on `main` after merge
- [ ] Testbench smoke: `flow:nodes --json` emits a valid catalog containing configured + discovered nodes
- [ ] A v1 `FlowStepHandler` executes through `LegacyStepNodeAdapter` with 1:1 result mapping (covered by merged tests)
- [ ] No pre-existing v1 test was modified (git diff on `tests/` shows only additions outside `tests/Contract` pins)
- [ ] `docs/PROGRESS.md` updated; lessons (if any) in `docs/LESSON.md`
- [ ] **Macro B detailed plan written** (per master plan authoring rule) and user-reviewed before any Macro B code
