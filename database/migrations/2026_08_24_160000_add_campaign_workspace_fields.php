<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->json('insights')->nullable()->after('draft_payload');
            $table->json('targets')->nullable()->after('insights');
            $table->json('advice')->nullable()->after('targets');
            $table->timestamp('advice_at')->nullable()->after('advice');
        });

        Schema::table('marketing_videos', function (Blueprint $table) {
            $table->string('ad_headline', 120)->nullable()->after('original_name');
            $table->string('ad_primary_text', 500)->nullable()->after('ad_headline');
            $table->string('ad_cta', 40)->nullable()->after('ad_primary_text');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn(['insights', 'targets', 'advice', 'advice_at']);
        });
        Schema::table('marketing_videos', function (Blueprint $table) {
            $table->dropColumn(['ad_headline', 'ad_primary_text', 'ad_cta']);
        });
    }
};
