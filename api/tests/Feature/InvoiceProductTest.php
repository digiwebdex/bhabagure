<?php

namespace Tests\Feature;

use App\Enums\PackageStatus;
use App\Models\InvoiceProduct;
use App\Models\TourPackage;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/invoice-items.md: an invoice's Add New Item offers the packages (published and drafts, at their per-person
 * price) and the office's own products; a new product is saved with its price, and can be edited or hidden later.
 */
class InvoiceProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContentSeeder::class);
    }

    #[Test]
    public function the_picker_offers_packages_and_products_and_a_new_product_is_saved_for_next_time(): void
    {
        $accountant = $this->staff('accountant');
        $mustang = TourPackage::query()->where('slug', 'nepal-mustang-adventure-tour-8-days-7-nights')->sole();
        $archived = TourPackage::query()->where('status', PackageStatus::Published->value)->where('id', '!=', $mustang->id)->firstOrFail();
        $archived->update(['status' => PackageStatus::Archived->value]);
        $draft = TourPackage::query()->where('status', PackageStatus::Draft->value)->firstOrFail();

        $items = collect($this->actingAsApi($accountant)->getJson('/api/v1/admin/invoice-products')->assertOk()->json('data'))->keyBy('key');
        // The Mustang package at its per-person price (the sale price), with its code and length as the detail.
        $this->assertSame(['package', 75000, false], [$items["package:{$mustang->id}"]['kind'], $items["package:{$mustang->id}"]['unit_price'], $items["package:{$mustang->id}"]['draft']]);
        $this->assertStringStartsWith($mustang->code.' · 8 days', $items["package:{$mustang->id}"]['description']);
        $this->assertTrue($items["package:{$draft->id}"]['draft'], 'drafts are offered, marked');
        $this->assertArrayNotHasKey("package:{$archived->id}", $items->all(), 'archived packages are not');

        // "Add … as a new product": saved with its price, offered from then on.
        $made = $this->actingAsApi($accountant)->postJson('/api/v1/admin/invoice-products', ['name' => ' Dhaka city tour ', 'unit_price' => 3500, 'description' => 'Lalbagh, Ahsan Manzil, Sadarghat'])
            ->assertCreated()->assertJsonPath('data.name', 'Dhaka city tour')->assertJsonPath('data.unit_price', 3500)->json('data');
        $this->actingAsApi($accountant)->postJson('/api/v1/admin/invoice-products', ['name' => 'Dhaka city tour', 'unit_price' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'A product with this name already exists — pick it from the list.']);
        $this->assertContains('product:'.$made['id'], array_column($this->actingAsApi($accountant)->getJson('/api/v1/admin/invoice-products')->json('data'), 'key'));

        // Hidden: gone from the picker, still on the Products screen.
        $this->actingAsApi($accountant)->putJson("/api/v1/admin/invoice-products/{$made['id']}", ['name' => 'Dhaka city tour', 'unit_price' => 4000, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.unit_price', 4000);
        $this->assertNotContains('product:'.$made['id'], array_column($this->actingAsApi($accountant)->getJson('/api/v1/admin/invoice-products')->json('data'), 'key'));
        $this->assertSame(['product:'.$made['id']], array_column($this->actingAsApi($accountant)->getJson('/api/v1/admin/invoice-products?manage=1')->json('data'), 'key'));
        $this->assertFalse(InvoiceProduct::query()->sole()->is_active);
    }

    #[Test]
    public function only_staff_who_see_invoices_read_the_list_and_only_invoice_managers_add_to_it(): void
    {
        $this->actingAsApi($this->staff('sales_agent'))->getJson('/api/v1/admin/invoice-products')->assertForbidden();
        $this->actingAsApi($this->staff('tour_operator'))->postJson('/api/v1/admin/invoice-products', ['name' => 'X', 'unit_price' => 1])->assertForbidden();
        $this->actingAsApi($this->staff('admin'))->postJson('/api/v1/admin/invoice-products', ['name' => 'Visa processing', 'unit_price' => 2500])->assertCreated();
    }
}
