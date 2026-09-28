<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PackageStatus;
use App\Http\Controllers\Controller;
use App\Models\InvoiceProduct;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an invoice's Add New Item offers (docs/invoice-items.md, decided 2026-09-28): the packages — published and
 * drafts, each at its per-person price (triple sharing, basic hotel) — and the office's own products with their price.
 * Reading needs payments.view, like the invoices; adding, editing and hiding a product invoices.manage.
 */
class InvoiceProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET admin/invoice-products: the picker's list. `?manage=1`: the products screen, hidden ones included. */
    public function index(Request $request): JsonResponse
    {
        $manage = $request->boolean('manage');
        $products = InvoiceProduct::query()->when(! $manage, fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
        $packages = $manage ? collect() : TourPackage::query()
            ->whereIn('status', [PackageStatus::Published->value, PackageStatus::Draft->value])
            ->orderBy('title_en')->get();

        return response()->json(['data' => [
            ...$products->map(self::product(...)),
            ...$packages->map(fn (TourPackage $package) => [
                'key' => "package:{$package->id}",
                'kind' => 'package',
                'id' => $package->id,
                'name' => $package->title_en ?: $package->title_bn,
                // Code and length, printed under the line as its detail.
                'description' => trim($package->code.' · '.$package->duration_days.' days'.($package->duration_nights !== null ? " / {$package->duration_nights} nights" : '')),
                // The per-person base: the sale price, else the regular price (with a size table, its basic/3-star price for two).
                'unit_price' => Money::toNumber($package->sale_price ?? $package->regular_price),
                'draft' => $package->status === PackageStatus::Draft,
                'is_active' => true,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = InvoiceProduct::query()->create($this->validated($request) + ['created_by_staff_id' => $request->user('staff')->id]);
        $this->audit->record('invoice_product.created', $request->user('staff'), $product, ['name' => $product->name]);

        return response()->json(['data' => self::product($product)], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = InvoiceProduct::query()->findOrFail($id);
        $product->update($this->validated($request, $product));
        $this->audit->record('invoice_product.updated', $request->user('staff'), $product, ['name' => $product->name]);

        return response()->json(['data' => self::product($product)]);
    }

    /** @return array{name: string, description: ?string, unit_price: int|float, is_active?: bool} */
    private function validated(Request $request, ?InvoiceProduct $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('invoice_products', 'name')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:300'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['name.unique' => __('invoices.product_exists')]);
        $data['name'] = trim($data['name']);
        $data['description'] = isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private static function product(InvoiceProduct $product): array
    {
        return [
            'key' => "product:{$product->id}",
            'kind' => 'product',
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'unit_price' => Money::toNumber($product->unit_price),
            'draft' => false,
            'is_active' => $product->is_active,
        ];
    }
}
