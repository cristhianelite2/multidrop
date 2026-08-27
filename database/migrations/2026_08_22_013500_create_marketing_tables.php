<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 24)->default('draft');
            $table->json('platforms');
            $table->decimal('daily_budget', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('landing_handle')->nullable();
            $table->string('landing_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('creatify_link_id')->nullable();
            $table->string('meta_draft_id')->nullable();
            $table->string('tiktok_draft_id')->nullable();
            $table->json('draft_payload')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('marketing_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
            $table->string('name');
            $table->string('hook', 240)->nullable();
            $table->text('script');
            $table->string('audience', 240)->nullable();
            $table->string('language', 16)->default('es');
            $table->string('style', 80)->nullable();
            $table->string('target_platform', 24)->default('Tiktok');
            $table->timestamps();

            $table->index(['store_id', 'campaign_id']);
        });

        Schema::create('marketing_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('prompt_id')->nullable()->constrained('marketing_prompts')->nullOnDelete();
            $table->string('source', 24)->default('upload');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->json('page_handles')->nullable();
            $table->timestamp('stripped_at')->nullable();
            $table->string('creatify_job_id')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_videos');
        Schema::dropIfExists('marketing_prompts');
        Schema::dropIfExists('marketing_campaigns');
    }
};
