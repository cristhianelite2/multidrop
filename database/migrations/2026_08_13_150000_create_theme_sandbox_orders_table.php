<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_sandbox_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->string('number')->unique();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->json('items')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->string('coupon')->nullable();
            $table->string('payment_status')->default('paid');
            $table->string('fulfillment_status')->default('unfulfilled');
            $table->string('cj_order_id')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('carrier')->nullable();
            $table->json('cj_payload')->nullable();
            $table->json('cj_response')->nullable();
            $table->json('cj_order_detail')->nullable();
            $table->json('cj_tracking')->nullable();
            $table->text('cj_error')->nullable();
            $table->timestamps();
            $table->index(['theme_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_sandbox_orders');
    }
};
