<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('template_inbounds', function (Blueprint $table) {
            $table->json('link_overrides')->nullable()->after('is_tls');
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->json('link_overrides')->nullable()->after('is_modernized');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_inbounds', function (Blueprint $table) {
            $table->dropColumn('link_overrides');
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn('link_overrides');
        });
    }
};
