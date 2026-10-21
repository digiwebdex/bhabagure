<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\Payments\EmiRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/emi-preview.md: SSLCommerz's EMI chart, served to the booking form's preview and edited in Site settings. The
 * rates are information only; nothing here touches a booking's amount.
 */
class EmiRatesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_website_gets_the_chart_with_no_thirty_months_at_standard_chartered(): void
    {
        $emi = $this->getJson('/api/v1/public/pricing')->assertOk()->json('data.emi');

        $this->assertSame(5000, $emi['minAmount']);
        $this->assertSame(['all_banks', 'standard_chartered', 'city_bank'], array_column($emi['groups'], 'key'));
        $rates = fn (string $key) => array_column(collect($emi['groups'])->firstWhere('key', $key)['tenures'], 'percent', 'months');
        $this->assertEquals([3 => 5.13, 6 => 6.75, 9 => 8.99, 12 => 11.33, 18 => 15.03, 24 => 20.38, 30 => 21.80, 36 => 26.26], $rates('all_banks'));
        $this->assertEquals([3 => 5.67, 6 => 7.86, 9 => 10.74, 12 => 13.77, 18 => 17.65, 24 => 23.25, 36 => 31.07], $rates('standard_chartered'));
        $this->assertEquals([3 => 5.65, 6 => 7.27, 9 => 9.51, 12 => 11.85, 18 => 15.55, 24 => 20.90, 30 => 22.32, 36 => 26.78], $rates('city_bank'));
        // The 35 banks aren't listed: the group says its eligibility is still to be confirmed.
        $this->assertTrue(collect($emi['groups'])->firstWhere('key', 'all_banks')['eligibilityPending']);
        $this->assertFalse(collect($emi['groups'])->firstWhere('key', 'city_bank')['eligibilityPending']);
    }

    #[Test]
    public function staff_edit_the_rates_and_the_website_follows_and_seeding_again_never_overwrites_them(): void
    {
        $admin = $this->staff('admin');
        $value = $this->actingAsApi($admin)->getJson('/api/v1/admin/settings')->assertOk()->json('data.emi');
        $this->assertSame(EmiRates::defaults()['minAmount'], $value['minAmount']);

        // The City Bank's 12 months goes to 12.5 %; its 3 months is no longer offered; Standard Chartered is switched off.
        $value['groups'][2]['rates']['12'] = 12.5;
        $value['groups'][2]['rates']['3'] = null;
        $value['groups'][1]['enabled'] = false;
        $this->actingAsApi($this->staff('sales_agent'))->putJson('/api/v1/admin/settings/emi', ['value' => $value])->assertForbidden();
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/emi', ['value' => $value])->assertOk();

        $emi = $this->getJson('/api/v1/public/pricing')->json('data.emi');
        $this->assertSame(['all_banks', 'city_bank'], array_column($emi['groups'], 'key'));
        $city = array_column(collect($emi['groups'])->firstWhere('key', 'city_bank')['tenures'], 'percent', 'months');
        $this->assertEquals(12.5, $city[12]);
        $this->assertArrayNotHasKey(3, $city);

        // The migration that stored the chart, run again (a re-deploy, a re-seed): the edited rates stay.
        (require database_path('migrations/2026_10_22_100000_emi_rates.php'))->up();
        $this->assertEquals(12.5, SiteSetting::query()->findOrFail('emi')->value['groups'][2]['rates']['12']);

        // Switched off: no preview at all.
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/emi', ['value' => ['enabled' => false] + $value])->assertOk();
        $this->getJson('/api/v1/public/pricing')->assertJsonPath('data.emi', null);
    }

    #[Test]
    public function a_rate_must_be_a_percent_and_the_groups_are_the_charts(): void
    {
        $admin = $this->staff('admin');
        $value = EmiRates::defaults();

        $tooHigh = $value;
        $tooHigh['groups'][0]['rates']['6'] = 101;
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/emi', ['value' => $tooHigh])->assertUnprocessable()->assertJsonValidationErrors('value.groups.0.rates.6');

        $unknown = $value;
        $unknown['groups'][0]['key'] = 'dutch_bangla';
        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/emi', ['value' => $unknown])->assertUnprocessable()->assertJsonValidationErrors('value.groups.0.key');

        $this->actingAsApi($admin)->putJson('/api/v1/admin/settings/emi', ['value' => ['minAmount' => -1] + $value])->assertUnprocessable()->assertJsonValidationErrors('value.minAmount');
    }
}
