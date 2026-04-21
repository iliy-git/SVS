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
        Schema::table('configs', function (Blueprint $table) {
            $table->foreignId('node_id')->nullable()->after('id')->constrained()->onDelete('set null');

            $table->string('email')->nullable()->after('name');

            $table->bigInteger('up')->default(0)->after('traffic_limit');
            $table->bigInteger('down')->default(0)->after('up');

            $table->bigInteger('expiry_time')->nullable()->after('down');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->dropColumn(['node_id', 'email', 'up', 'down', 'expiry_time']);
        });
    }
};
