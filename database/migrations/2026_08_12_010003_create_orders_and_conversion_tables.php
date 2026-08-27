<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->json('shipping_address')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->string('payment_provider')->nullable();
            $table->string('payment_ref')->nullable();
            $table->string('currency', 3)->default('MXN');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('shipping', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('line_type')->default('primary'); // primary|upsell|cross_sell|bump|bundle_component
            $table->string('name');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total', 12, 2);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_ref')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MXN');
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('fulfillments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('tracking_number')->nullable();
            $table->string('carrier')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('flash'); // flash|bundle|threshold
            $table->string('name');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('stock_threshold')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('type')->default('percent'); // percent|fixed
            $table->decimal('value', 12, 2);
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('upsell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('offer_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('position')->default('pre_pay'); // pre_pay|post_pay
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cross_sell_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('offer_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_sell_rules');
        Schema::dropIfExists('upsell_rules');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('fulfillments');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};
