<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionTemplate;
use App\Models\Tariff;
use App\Models\TariffItem;
use App\Services\SubscriptionService;
use App\Services\TariffPurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TelegramPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_tariff_endpoint(): void
    {
        $adminUuid = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';

        // Mock SubscriptionService to avoid real node API calls
        $mockSubService = Mockery::mock(SubscriptionService::class);
        $dummySub = Subscription::create([
            'name' => 'Test Sub',
            'token' => 'testtoken123',
            'expires_at' => now()->addDays(30),
            'with_balancer' => true,
            'install_limit' => 1,
        ]);

        $mockSubService->shouldReceive('createFromTemplate')
            ->andReturn($dummySub);

        $this->app->instance(SubscriptionService::class, $mockSubService);

        // Create template and tariff
        $template = SubscriptionTemplate::create([
            'name' => 'Default Template',
            'slug' => 'default_template',
            'description' => 'Test',
            'is_active' => true,
        ]);

        $tariff = Tariff::create([
            'name' => 'Test Tariff',
            'description' => 'Desc',
            'price' => 150,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        TariffItem::create([
            'tariff_id' => $tariff->id,
            'template_id' => $template->id,
            'device_limit' => 2,
            'custom_name' => 'My Tariff Item',
        ]);

        $response = $this->postJson("/{$adminUuid}/telegram/subscriptions/purchase", [
            'telegram_id' => '123456789',
            'tariff_id' => (string) $tariff->id,
            'subscriptions_count' => 1,
            'days' => 30,
            'price' => 1.5,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonPath('data.telegram_id', '123456789');

        $this->assertDatabaseHas('clients', [
            'telegram_id' => '123456789',
        ]);
    }

    public function test_purchase_fallback_tariff_endpoint(): void
    {
        $adminUuid = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';

        $template = SubscriptionTemplate::create([
            'name' => 'Fallback Template',
            'slug' => 'fallback_template',
            'description' => 'Test',
            'is_active' => true,
        ]);

        $mockSubService = Mockery::mock(SubscriptionService::class);
        $dummySub = new Subscription([
            'id' => 888,
            'name' => 'Fallback Sub',
            'token' => 'fallbacktoken123',
            'expires_at' => now()->addDays(30),
            'with_balancer' => true,
            'install_limit' => 1,
        ]);
        $dummySub->id = 888;
        $dummySub->exists = true;

        $mockSubService->shouldReceive('createFromTemplate')
            ->twice()
            ->andReturn($dummySub);

        $this->app->instance(SubscriptionService::class, $mockSubService);

        $response = $this->postJson("/{$adminUuid}/telegram/subscriptions/purchase", [
            'telegram_id' => '987654321',
            'tariff_id' => '2_sub',
            'subscriptions_count' => 2,
            'days' => 30,
            'price' => 2.0,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
        $response->assertJsonPath('data.telegram_id', '987654321');
        $this->assertCount(2, $response->json('data.subscriptions'));
    }

    public function test_extend_subscription_requires_telegram_id(): void
    {
        $adminUuid = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';

        $response = $this->postJson("/{$adminUuid}/telegram/subscriptions/1/extend", [
            'days' => 30,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['telegram_id']);
    }

    public function test_extend_subscription_checks_ownership(): void
    {
        $adminUuid = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';

        $client = Client::create(['name' => 'Owner', 'telegram_id' => '111222']);
        $sub = Subscription::create([
            'name' => 'Sub 1',
            'token' => 'token111',
            'expires_at' => now()->addDays(10),
            'with_balancer' => true,
            'install_limit' => 1,
        ]);
        $client->subscriptions()->attach($sub->id);

        // Try extending with wrong telegram_id
        $response = $this->postJson("/{$adminUuid}/telegram/subscriptions/{$sub->id}/extend", [
            'telegram_id' => '999999', // not the owner
            'days' => 30,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    public function test_extend_subscription_success(): void
    {
        $adminUuid = Setting::where('key', 'admin_uuid')->value('value') ?? 'admin-panel';

        $client = Client::create(['name' => 'Owner', 'telegram_id' => '333444']);
        $sub = Subscription::create([
            'name' => 'Sub 2',
            'token' => 'token222',
            'expires_at' => now()->addDays(5),
            'with_balancer' => true,
            'install_limit' => 1,
        ]);
        $client->subscriptions()->attach($sub->id);

        $mockSubService = Mockery::mock(SubscriptionService::class);
        $mockSubService->shouldReceive('extendSubscription')
            ->once()
            ->with($sub->id, 30)
            ->andReturn(true);

        $this->app->instance(SubscriptionService::class, $mockSubService);

        $response = $this->postJson("/{$adminUuid}/telegram/subscriptions/{$sub->id}/extend", [
            'telegram_id' => '333444',
            'days' => 30,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'subscription_id' => $sub->id,
            ],
        ]);
    }
}
