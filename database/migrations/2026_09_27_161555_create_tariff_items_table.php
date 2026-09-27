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
        Schema::create('tariff_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            
            // Связь с твоей существующей таблицей шаблонов
            $table->foreignId('template_id')->constrained('subscription_templates')->cascadeOnDelete();
            
            // Лимит устройств именно для этого шаблона внутри тарифа
            $table->integer('device_limit')->default(1); 
            
            // Добавочное имя к подписке (например, " (Для ТВ)" или " (Основной)")
            $table->string('custom_name')->nullable(); 
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariff_items');
    }
};
