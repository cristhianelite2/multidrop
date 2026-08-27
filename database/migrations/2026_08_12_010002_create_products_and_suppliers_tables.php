<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // cj, autods...
            $table->string('name');
            $table->json('credentials')->nullable(); // encrypted at model cast
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('MXN');
            $table->string('status')->default('draft');
            $table->json('verified_data')->nullable();
            $table->json('creative_data')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('score_band')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->json('options')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_product_id');
            $table->string('external_variant_id')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('shipping_cost', 12, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->string('warehouse_code')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_product_id', 'external_variant_id'], 'supplier_product_ext_unique');
        });

        Schema::create('product_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('band');
            $table->json('breakdown')->nullable();
            $table->json('factors')->nullable();
            $table->timestamps();
        });

        Schema::create('product_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('planned'); // planned|running|green|yellow|red|killed|scaled
            $table->string('playbook')->default('problem_urgency');
            $table->decimal('budget_cap', 12, 2)->default(0);
            $table->decimal('ad_spend', 12, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tests');
        Schema::dropIfExists('product_scores');
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
    }
};
