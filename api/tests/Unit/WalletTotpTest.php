<?php

namespace Tests\Unit;

use App\Wallet\Support\Totp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** RFC 6238's SHA-1 test vectors (secret "12345678901234567890"), cut to the six digits an authenticator app shows. */
class WalletTotpTest extends TestCase
{
    #[Test]
    public function codes_match_the_rfc_6238_vectors_and_a_code_is_found_one_step_either_side(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));

        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($time)), "at {$time}");
        }

        $step = Totp::step(1111111109);
        $this->assertSame($step, Totp::verify($secret, '081804', 1111111109));
        $this->assertSame($step, Totp::verify($secret, '081 804', 1111111109 + 30));
        $this->assertNull(Totp::verify($secret, '081804', 1111111109 + 90));
        $this->assertNull(Totp::verify($secret, '12345', 1111111109));
        $this->assertStringStartsWith('otpauth://totp/Bhabaghure%20wallet:owner%40example.test?secret=', Totp::uri($secret, 'owner@example.test', 'Bhabaghure wallet'));
        $this->assertSame(32, strlen(Totp::secret()));
    }
}
