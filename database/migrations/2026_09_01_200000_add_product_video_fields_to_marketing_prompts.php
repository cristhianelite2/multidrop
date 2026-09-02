<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_prompts', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('campaign_id')->constrained('products')->nullOnDelete();
            $table->json('segments')->nullable()->after('script');
            $table->json('analysis')->nullable()->after('segments');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_prompts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['segments', 'analysis']);
        });
    }
};
