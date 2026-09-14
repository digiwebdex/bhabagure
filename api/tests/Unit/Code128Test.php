<?php

namespace Tests\Unit;

use App\Support\Barcode\Code128;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Code128Test extends TestCase
{
    #[Test]
    public function it_encodes_set_b_with_the_modulo_103_check_symbol(): void
    {
        // Start B 104 + P(48)×1 + J(42)×2 + J(42)×3 + 1(17)×4 + 2(18)×5 + 3(19)×6 + C(35)×7 = 879; 879 mod 103 = 55.
        $this->assertSame([104, 48, 42, 42, 17, 18, 19, 35, 55, 106], Code128::symbols('PJJ123C'));
        $this->assertSame(104, Code128::symbols('INV-0001')[0]);
    }

    #[Test]
    public function every_symbol_is_eleven_modules_and_the_stop_is_thirteen(): void
    {
        $widths = Code128::widths('INV-0412');
        // Start, 8 characters and the check symbol at 11 modules each, then the 13-module stop.
        $this->assertSame(11 * 10 + 13, array_sum($widths));
        $this->assertCount(6 * 10 + 7, $widths);
    }

    #[Test]
    public function it_refuses_characters_outside_set_b(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Code128::symbols('ইনভয়েস');
    }
}
