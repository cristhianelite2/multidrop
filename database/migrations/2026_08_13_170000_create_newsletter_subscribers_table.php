<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('source', 40)->default('popup'); // popup|checkout
            $table->string('status', 20)->default('pending'); // pending|confirmed|unsubscribed
            $table->string('confirm_token', 64)->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'email']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
