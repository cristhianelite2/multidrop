<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique(); // MX, US, UK
            $table->string('name');
            $table->string('locale', 12)->default('es_MX');
            $table->string('currency', 3)->default('MXN');
            $table->string('timezone')->default('America/Mexico_City');
            $table->json('tax_profile')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'code']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->json('identity')->nullable(); // colors, fonts, logo
            $table->timestamps();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('sector')->nullable(); // emergencia, hogar, belleza...
            $table->string('store_type')->default('mini'); // mega|mini
            $table->string('status')->default('draft'); // draft|live|paused
            $table->string('theme')->default('default');
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['market_id', 'slug']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('host'); // localhost, shop.ceballosleon.com, superlux.com
            $table->string('path_prefix')->nullable(); // /superlux
            $table->string('type')->default('path'); // path|subdomain|apex
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['host', 'path_prefix']);
        });

        Schema::create('problems', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('sector')->nullable();
            $table->json('keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('problem_market', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('market_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('severity')->default(50);
            $table->json('local_hooks')->nullable();
            $table->timestamps();
            $table->unique(['problem_id', 'market_id']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('problem_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
        Schema::dropIfExists('problem_market');
        Schema::dropIfExists('problems');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('markets');
    }
};
