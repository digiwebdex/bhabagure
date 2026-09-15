<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Every API response refuses framing and MIME sniffing and sends no Referer (App\Http\Middleware\SecurityHeaders). */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function json_errors_health_checks_and_signed_in_answers_all_carry_the_headers(): void
    {
        $responses = [
            $this->get('/up'),
            $this->getJson('/api/v1/public/settings'),
            $this->getJson('/api/v1/admin/bookings'),
            $this->getJson('/api/v1/public/invoices/not-a-token'),
            $this->actingAsApi($this->staff('admin'))->getJson('/api/v1/staff/auth/me'),
        ];

        foreach ($responses as $response) {
            $response->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'no-referrer');
        }
    }
}
