<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('brand_id')
                    ->constrained('stores')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
        });
    }
};
