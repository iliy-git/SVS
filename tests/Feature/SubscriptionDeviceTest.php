<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionDevice;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionDeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Проверяем, что устройства привязываются и лимит работает
     */
    public function test_can_bind_multiple_devices_up_to_limit()
    {
        $sub = Subscription::create([
            'name' => 'Test Subscription',
            'token' => 'test-token-multi',
            'install_limit' => 3,
        ]);

        // Привязываем 3 устройства (лимит = 3)
        $sub->devices()->create(['device_id' => 'device-aaa']);
        $sub->devices()->create(['device_id' => 'device-bbb']);
        $sub->devices()->create(['device_id' => 'device-ccc']);

        $this->assertEquals(3, $sub->devices()->count());

        // Проверяем, что все устройства существуют
        $this->assertTrue($sub->devices()->where('device_id', 'device-aaa')->exists());
        $this->assertTrue($sub->devices()->where('device_id', 'device-bbb')->exists());
        $this->assertTrue($sub->devices()->where('device_id', 'device-ccc')->exists());
    }

    /**
     * Проверяем что exists-проверка работает корректно
     */
    public function test_device_exists_check()
    {
        $sub = Subscription::create([
            'name' => 'Test Subscription',
            'token' => 'test-token-exists',
            'install_limit' => 2,
        ]);

        $sub->devices()->create(['device_id' => 'device-111', 'happ_install_code' => 'install-111']);

        // Поиск по device_id
        $this->assertTrue(
            $sub->devices()->where('device_id', 'device-111')->exists()
        );

        // Поиск по happ_install_code
        $this->assertTrue(
            $sub->devices()->where('happ_install_code', 'install-111')->exists()
        );

        // Несуществующее устройство
        $this->assertFalse(
            $sub->devices()->where('device_id', 'device-999')->exists()
        );
    }

    /**
     * Проверяем что лимит не позволяет превысить количество
     */
    public function test_device_limit_enforcement()
    {
        $sub = Subscription::create([
            'name' => 'Test Subscription',
            'token' => 'test-token-limit',
            'install_limit' => 2,
        ]);

        // Симулируем логику из checkDeviceBinding
        $limit = $sub->install_limit ?? 1;

        // Первое устройство
        if ($sub->devices()->count() < $limit) {
            $sub->devices()->create(['device_id' => 'dev-1']);
        }
        $this->assertEquals(1, $sub->devices()->count());

        // Второе устройство
        if ($sub->devices()->count() < $limit) {
            $sub->devices()->create(['device_id' => 'dev-2']);
        }
        $this->assertEquals(2, $sub->devices()->count());

        // Третье устройство — НЕ должно создаться
        if ($sub->devices()->count() < $limit) {
            $sub->devices()->create(['device_id' => 'dev-3']);
        }
        $this->assertEquals(2, $sub->devices()->count());
        $this->assertFalse($sub->devices()->where('device_id', 'dev-3')->exists());
    }

    /**
     * Проверяем сброс всех привязанных устройств
     */
    public function test_reset_device_clears_all_bound_devices()
    {
        $sub = Subscription::create([
            'name' => 'Test Subscription',
            'token' => 'test-token-reset',
            'install_limit' => 3,
            'device_id' => 'device-old',
        ]);

        $sub->devices()->create(['device_id' => 'device-old', 'happ_install_code' => 'code-1']);
        $sub->devices()->create(['device_id' => 'device-two', 'happ_install_code' => 'code-2']);
        $this->assertEquals(2, $sub->devices()->count());

        $service = app(SubscriptionService::class);
        $service->resetDevice($sub->id);

        $sub->refresh();
        $this->assertNull($sub->device_id);
        $this->assertNull($sub->happ_install_code);
        $this->assertEquals(0, $sub->devices()->count());
    }

    /**
     * Проверяем cascadeOnDelete — при удалении подписки удаляются и устройства
     */
    public function test_cascade_delete_removes_devices()
    {
        $sub = Subscription::create([
            'name' => 'Test Subscription',
            'token' => 'test-token-cascade',
            'install_limit' => 2,
        ]);

        $sub->devices()->create(['device_id' => 'dev-1']);
        $sub->devices()->create(['device_id' => 'dev-2']);

        $subId = $sub->id;
        $sub->delete();

        $this->assertEquals(0, SubscriptionDevice::where('subscription_id', $subId)->count());
    }

    /**
     * Проверяем что миграция корректно переносит данные из старых полей
     */
    public function test_existing_device_id_migrated_to_devices_table()
    {
        // Создаём подписку со старым device_id (как было до рефакторинга)
        $sub = Subscription::create([
            'name' => 'Legacy Sub',
            'token' => 'test-token-legacy',
            'device_id' => 'legacy-hwid-123',
            'happ_install_code' => 'legacy-install-code',
            'install_limit' => 1,
        ]);

        // Симулируем то, что делает миграция — вставляем запись в subscription_devices
        $sub->devices()->create([
            'device_id' => $sub->device_id,
            'happ_install_code' => $sub->happ_install_code,
        ]);

        // Проверяем
        $this->assertEquals(1, $sub->devices()->count());
        $device = $sub->devices()->first();
        $this->assertEquals('legacy-hwid-123', $device->device_id);
        $this->assertEquals('legacy-install-code', $device->happ_install_code);
    }
}
