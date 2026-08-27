<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->string('strategy', 16)->default('qty'); // qty|pair|both
            $table->unsignedInteger('qty_min')->default(2);
            $table->string('discount_type', 16)->default('percent'); // percent|fixed
            $table->decimal('discount_value', 12, 2)->default(10);
            $table->boolean('is_active')->default(true);
            $table->boolean('publish_as_product')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
        });

        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_id')->constrained('combos')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['combo_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_items');
        Schema::dropIfExists('combos');
    }
};
