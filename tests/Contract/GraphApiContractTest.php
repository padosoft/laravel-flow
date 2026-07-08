<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Contract;

use Padosoft\LaravelFlow\Contracts\DefinitionRepository;
use Padosoft\LaravelFlow\Graph\Connection;
use Padosoft\LaravelFlow\Graph\DefinitionSigner;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionLifecycleException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionNotFoundException;
use Padosoft\LaravelFlow\Graph\Exceptions\DefinitionSignatureException;
use Padosoft\LaravelFlow\Graph\Exceptions\InvalidGraphException;
use Padosoft\LaravelFlow\Graph\GraphDefinition;
use Padosoft\LaravelFlow\Graph\GraphNode;
use Padosoft\LaravelFlow\Graph\GraphSerializer;
use Padosoft\LaravelFlow\Graph\GraphTransfer;
use Padosoft\LaravelFlow\Graph\GraphValidator;
use Padosoft\LaravelFlow\Graph\StoredDefinition;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GraphApiContractTest extends TestCase
{
    public function test_graph_api_classes_are_annotated_api(): void
    {
        $classes = [
            GraphNode::class,
            Connection::class,
            GraphDefinition::class,
            GraphValidator::class,
            InvalidGraphException::class,
            GraphSerializer::class,
            DefinitionRepository::class,
            StoredDefinition::class,
            DefinitionNotFoundException::class,
            DefinitionLifecycleException::class,
            DefinitionSigner::class,
            DefinitionSignatureException::class,
            GraphTransfer::class,
        ];

        foreach ($classes as $class) {
            $doc = (string) (new ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class);
            $this->assertStringNotContainsString('@internal', $doc, $class);
        }
    }

    public function test_definition_repository_exposes_lifecycle_methods(): void
    {
        $reflection = new ReflectionClass(DefinitionRepository::class);

        foreach (['createDraft', 'find', 'latest', 'publish', 'archive', 'versions'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), $method);
        }
    }

    public function test_stored_definition_statuses_are_pinned(): void
    {
        $this->assertSame('draft', StoredDefinition::STATUS_DRAFT);
        $this->assertSame('published', StoredDefinition::STATUS_PUBLISHED);
        $this->assertSame('archived', StoredDefinition::STATUS_ARCHIVED);
    }

    public function test_graph_definition_exposes_topological_order(): void
    {
        $this->assertTrue((new ReflectionClass(GraphDefinition::class))->hasMethod('topologicalOrder'));
    }

    public function test_graph_schema_constants_are_pinned(): void
    {
        $this->assertSame(1, GraphSerializer::SCHEMA_VERSION);
        $this->assertSame('laravel-flow', GraphSerializer::KIND);
    }

    public function test_definition_signer_exposes_sign_and_verify(): void
    {
        $reflection = new ReflectionClass(DefinitionSigner::class);

        foreach (['isEnabled', 'sign', 'verify'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), $method);
        }
    }

    public function test_graph_transfer_exposes_export_and_import_draft(): void
    {
        $reflection = new ReflectionClass(GraphTransfer::class);

        foreach (['export', 'importDraft'] as $method) {
            $this->assertTrue($reflection->hasMethod($method), $method);
        }
    }
}
