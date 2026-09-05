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
            $table->unsignedBigInteger('traffic_limit_gb')->default(0)->after('inbound_id'); // 0 = безлимит
            $table->boolean('is_tls')->default(false)->after('traffic_limit_gb');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('template_inbounds', function (Blueprint $table) {
            $table->dropColumn(['traffic_limit_gb', 'is_tls']);
        });
    }
};
