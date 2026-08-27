<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('preview_image')->nullable();
            $table->string('source')->default('zip'); // zip|seed|clone
            $table->json('design')->nullable();
            $table->foreignId('created_from_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('store_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->json('design')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'is_active']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['store_id', 'email']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('access_token', 64)->nullable()->after('number')->unique();
            $table->string('fulfillment_status')->default('unfulfilled')->after('payment_status');
            $table->json('shipping_address')->nullable()->after('meta');
            $table->string('customer_email')->nullable()->after('customer_id');
            $table->string('customer_name')->nullable()->after('customer_email');
            $table->string('customer_phone')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'access_token',
                'fulfillment_status',
                'shipping_address',
                'customer_email',
                'customer_name',
                'customer_phone',
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'email']);
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::dropIfExists('store_designs');
        Schema::dropIfExists('themes');
    }
};
