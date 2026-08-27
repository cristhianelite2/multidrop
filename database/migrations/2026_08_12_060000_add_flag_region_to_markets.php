<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            if (! Schema::hasColumn('markets', 'region')) {
                $table->string('region', 32)->default('other')->after('name')->index();
            }
            if (! Schema::hasColumn('markets', 'flag')) {
                $table->string('flag', 16)->nullable()->after('region');
            }
            if (! Schema::hasColumn('markets', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->default(100)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            foreach (['region', 'flag', 'sort_order'] as $col) {
                if (Schema::hasColumn('markets', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
