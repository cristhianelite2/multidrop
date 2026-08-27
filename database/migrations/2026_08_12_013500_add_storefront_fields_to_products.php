<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('slug');
            $table->decimal('compare_at_price', 12, 2)->nullable()->after('price');
            $table->string('badge')->nullable()->after('status');
            $table->unsignedInteger('stock')->nullable()->after('badge');
            $table->boolean('is_featured')->default(false)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'compare_at_price', 'badge', 'stock', 'is_featured']);
        });
    }
};
