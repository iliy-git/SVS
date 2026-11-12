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
        // 1. Добавляем признак публичности тарифа в каталог
        if (!Schema::hasColumn('tariffs', 'is_public')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->boolean('is_public')->default(true)->after('is_active');
            });
        }

        // 2. Создаем таблицу купленных/назначенных тарифов клиента
        if (!Schema::hasTable('client_tariffs')) {
            Schema::create('client_tariffs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('tariff_id')->constrained('tariffs')->cascadeOnDelete();
                $table->decimal('custom_price', 10, 2)->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // 3. Добавляем связь подписки с купленным тарифом
        if (!Schema::hasColumn('subscriptions', 'client_tariff_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreignId('client_tariff_id')->nullable()->after('template_id')->constrained('client_tariffs')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'client_tariff_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropForeign(['client_tariff_id']);
                $table->dropColumn('client_tariff_id');
            });
        }

        Schema::dropIfExists('client_tariffs');

        if (Schema::hasColumn('tariffs', 'is_public')) {
            Schema::table('tariffs', function (Blueprint $table) {
                $table->dropColumn('is_public');
            });
        }
    }
};
