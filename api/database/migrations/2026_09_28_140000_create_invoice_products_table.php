<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office's own products for invoices (docs/invoice-items.md): services and trips that aren't website packages —
 * "Dhaka tour", visa processing, a ticketing service charge — picked from Add New Item with their price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('description', 300)->nullable();
            $table->decimal('unit_price', 12, 2);
            // Hidden products stay on the invoices that used them but are no longer offered.
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_products');
    }
};
