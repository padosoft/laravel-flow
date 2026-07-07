<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\PortDefinition;
use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class PortDefinitionTest extends TestCase
{
    public function test_to_array_uses_key_as_label_fallback_and_omits_property_name(): void
    {
        $port = new PortDefinition(key: 'order_id', type: PortType::Int, required: true, propertyName: 'orderId');

        $this->assertSame(
            ['key' => 'order_id', 'type' => 'int', 'required' => true, 'label' => 'order_id'],
            $port->toArray(),
        );
    }

    public function test_to_array_keeps_explicit_label(): void
    {
        $port = new PortDefinition(key: 'name', type: PortType::Text, label: 'Customer name');

        $this->assertSame('Customer name', $port->toArray()['label']);
        $this->assertFalse($port->toArray()['required']);
    }
}
