<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellercentral_video_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sellercentral_task_id')->nullable()->index();
            $table->string('callback_token', 64)->unique();
            $table->string('mode', 20)->default('assets'); // assets | url_only
            $table->string('status', 32)->default('pending')->index();
            $table->string('title')->nullable();
            $table->string('notebook_id')->nullable();
            $table->text('error_message')->nullable();
            $table->json('video_log')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->string('remote_video_url', 2048)->nullable();
            $table->foreignId('marketing_video_id')->nullable()->constrained('marketing_videos')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'product_id', 'status']);
            $table->index(['campaign_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellercentral_video_jobs');
    }
};
