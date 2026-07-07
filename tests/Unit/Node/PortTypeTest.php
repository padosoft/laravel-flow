<?php

declare(strict_types=1);

namespace Padosoft\LaravelFlow\Tests\Unit\Node;

use Padosoft\LaravelFlow\Node\PortType;
use PHPUnit\Framework\TestCase;

final class PortTypeTest extends TestCase
{
    public function test_same_type_is_accepted(): void
    {
        $this->assertTrue(PortType::Text->accepts(PortType::Text));
    }

    public function test_different_scalar_types_are_rejected(): void
    {
        $this->assertFalse(PortType::Text->accepts(PortType::Int));
        $this->assertFalse(PortType::Bool->accepts(PortType::Json));
    }

    public function test_any_accepts_and_is_accepted_by_everything(): void
    {
        foreach (PortType::cases() as $case) {
            $this->assertTrue(PortType::Any->accepts($case));
            $this->assertTrue($case->accepts(PortType::Any));
        }
    }

    public function test_float_accepts_int_widening(): void
    {
        $this->assertTrue(PortType::Float->accepts(PortType::Int));
        $this->assertFalse(PortType::Int->accepts(PortType::Float));
    }

    public function test_validates_scalar_values(): void
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
