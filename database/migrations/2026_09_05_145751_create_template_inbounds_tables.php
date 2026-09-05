<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_inbounds', function (Blueprint $table) {
            $table->id();

            // Внешний ключ на шаблоны
            $table->foreignId('template_id')
                ->constrained('subscription_templates')
                ->cascadeOnDelete();

            // Внешний ключ на существующую таблицу нод SVS (nodes)
            $table->foreignId('node_id')
                ->constrained('nodes')
                ->cascadeOnDelete();

            // ID инбаунда с панели 3x-ui на этой ноде
            $table->integer('inbound_id');
            $table->integer('priority')->default(0);
            $table->timestamps();

            // Защита от создания дубликатов связей (Нода + Инбаунд) в пределах одного шаблона
            $table->unique(['template_id', 'node_id', 'inbound_id'], 'unique_template_node_inbound');

            // Индекс для быстрого JOIN при сборе конфигураций
//            $table->index(['template_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_inbounds');
    }
};
