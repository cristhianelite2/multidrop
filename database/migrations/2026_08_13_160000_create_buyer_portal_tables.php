<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('portal_pass_hash')->nullable()->after('access_token');
        });

        Schema::create('order_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_account_id')->constrained('buyer_accounts')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('open');
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });

        Schema::create('fraud_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('email')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['email', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_events');
        Schema::dropIfExists('order_claims');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('portal_pass_hash');
        });
        Schema::dropIfExists('buyer_accounts');
    }
};
