<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('marketing_videos', 'music_attribution')) {
            Schema::table('marketing_videos', function (Blueprint $table) {
                $table->text('music_attribution')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_videos', 'music_attribution')) {
            Schema::table('marketing_videos', function (Blueprint $table) {
                $table->dropColumn('music_attribution');
            });
        }
    }
};
