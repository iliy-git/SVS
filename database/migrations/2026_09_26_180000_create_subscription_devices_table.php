<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('device_id')->nullable()->index();
            $table->string('happ_install_code')->nullable()->index();
            $table->timestamps();
        });

        // Safe database-agnostic data migration
        $now = now();
        $subs = DB::table('subscriptions')
            ->where(function ($q) {
                $q->whereNotNull('device_id')->where('device_id', '!=', '');
            })
            ->orWhere(function ($q) {
                $q->whereNotNull('happ_install_code')->where('happ_install_code', '!=', '');
            })
            ->get();

        foreach ($subs as $sub) {
            DB::table('subscription_devices')->insert([
                'subscription_id' => $sub->id,
                'device_id' => $sub->device_id,
                'happ_install_code' => $sub->happ_install_code,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_devices');
    }
};
