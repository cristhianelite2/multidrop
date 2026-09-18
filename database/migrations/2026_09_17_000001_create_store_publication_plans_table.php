<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_publication_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('batch_uid', 32)->index();
            $table->unsignedInteger('days');
            $table->unsignedInteger('per_day');
            $table->date('start_date');
            $table->json('channels');
            $table->json('product_ids')->nullable();
            $table->string('format', 16)->default('image'); // image|text
            $table->string('status', 16)->default('draft'); // draft|sent|partial|error
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('store_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('store_publication_plans')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('network', 20);
            $table->string('format', 16)->default('image'); // text|image|carousel|video
            $table->text('content');
            $table->string('topic', 255)->nullable();
            $table->json('media_urls')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 20)->default('draft'); // draft|approved|scheduled|published|error|cancelled
            $table->string('sellercentral_post_id', 40)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('day_offset')->default(0);
            $table->unsignedInteger('slot_index')->default(0);
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_publications');
        Schema::dropIfExists('store_publication_plans');
    }
};