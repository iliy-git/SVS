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
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Название тарифа (например, "Семейный")
            $table->text('description')->nullable(); // Описание для вывода в боте/API
            $table->decimal('price', 10, 2)->default(0); // Стоимость
            $table->integer('duration_days')->default(30); // На сколько дней выдается
            $table->boolean('is_active')->default(true); // Статус (вкл/выкл)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
