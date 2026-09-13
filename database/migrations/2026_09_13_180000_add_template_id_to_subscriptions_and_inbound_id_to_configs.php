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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('template_id')
                ->nullable()
                ->after('id')
                ->constrained('subscription_templates')
                ->nullOnDelete();
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->integer('inbound_id')->nullable()->after('node_id');
            $table->integer('priority')->default(0)->after('inbound_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });

        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn(['inbound_id', 'priority']);
        });
    }
};
