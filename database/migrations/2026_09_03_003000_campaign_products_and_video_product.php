<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaign_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['marketing_campaign_id', 'product_id'], 'mcp_campaign_product_unique');
        });

        if (! Schema::hasColumn('marketing_videos', 'product_id')) {
            Schema::table('marketing_videos', function (Blueprint $table) {
                $table->foreignId('product_id')->nullable()->after('prompt_id')->constrained('products')->nullOnDelete();
            });
        }

        $promptRows = DB::table('marketing_prompts')
            ->whereNotNull('campaign_id')
            ->whereNotNull('product_id')
            ->select('campaign_id', 'product_id')
            ->distinct()
            ->get();

        $now = now();
        foreach ($promptRows as $row) {
            $exists = DB::table('marketing_campaign_product')
                ->where('marketing_campaign_id', $row->campaign_id)
                ->where('product_id', $row->product_id)
                ->exists();
            if ($exists) {
                continue;
            }
            DB::table('marketing_campaign_product')->insert([
                'marketing_campaign_id' => $row->campaign_id,
                'product_id' => $row->product_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $linkedVideos = DB::table('marketing_videos as v')
            ->join('marketing_prompts as p', 'p.id', '=', 'v.prompt_id')
            ->whereNull('v.product_id')
            ->whereNotNull('p.product_id')
            ->select('v.id', 'p.product_id')
            ->get();
        foreach ($linkedVideos as $row) {
            DB::table('marketing_videos')->where('id', $row->id)->update(['product_id' => $row->product_id]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_videos', 'product_id')) {
            Schema::table('marketing_videos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_id');
            });
        }
        Schema::dropIfExists('marketing_campaign_product');
    }
};
