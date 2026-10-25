<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_is_formatted_without_float_decision_logic(): void
    {
        $this->assertSame('₹499.37', Money::formatInr(49937));
        $this->assertSame('123456789012', Money::assertReference('123-456-789-012'));
    }

    public function test_reference_must_be_exactly_twelve_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::assertReference('1234');
    }
}
