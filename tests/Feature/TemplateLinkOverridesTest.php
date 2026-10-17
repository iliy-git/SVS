<?php

namespace Tests\Feature;

use App\Models\Config;
use App\Models\Node;
use App\Models\Subscription;
use App\Models\SubscriptionTemplate;
use App\Models\TemplateInbound;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateLinkOverridesTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_template_with_link_overrides()
    {
        $node = Node::create([
            'name'     => 'Test Node Netherlands',
            'ip'       => '195.133.1.50',
            'api_key'  => 'secret_key',
            'is_active'=> true,
        ]);

        $template = SubscriptionTemplate::create([
            'name'      => 'Custom CDN Template',
            'slug'      => 'custom-cdn',
            'is_active' => true,
        ]);

        $overrides = [
            'enabled' => true,
            'host'    => 'my-custom-cdn.example.com',
            'port'    => 443,
            'suffix'  => '(Обход ТСПУ)',
            'params'  => [
                'sni'  => 'my-custom-cdn.example.com',
                'host' => 'origin.example.com',
                'path' => '/custom/path',
            ],
        ];

        $templateInbound = TemplateInbound::create([
            'template_id'      => $template->id,
            'node_id'          => $node->id,
            'inbound_id'       => 1,
            'traffic_limit_gb' => 50,
            'is_tls'           => true,
            'link_overrides'   => $overrides,
            'priority'         => 10,
        ]);

        $this->assertDatabaseHas('template_inbounds', [
            'id'          => $templateInbound->id,
            'template_id' => $template->id,
            'is_tls'      => 1,
        ]);

        $reloaded = TemplateInbound::find($templateInbound->id);
        $this->assertIsArray($reloaded->link_overrides);
        $this->assertEquals('my-custom-cdn.example.com', $reloaded->link_overrides['host']);
        $this->assertEquals('origin.example.com', $reloaded->link_overrides['params']['host']);
    }

    public function test_subscription_creation_and_sync_with_overrides()
    {
        $user = User::factory()->create();

        $node = Node::create([
            'name'     => 'Hetzner Node',
            'ip'       => '88.99.100.101',
            'api_key'  => 'node_api_token',
            'is_active'=> true,
        ]);

        $template = SubscriptionTemplate::create([
            'name'      => 'Template V1',
            'slug'      => 'tpl-v1',
            'is_active' => true,
        ]);

        $ti = TemplateInbound::create([
            'template_id'      => $template->id,
            'node_id'          => $node->id,
            'inbound_id'       => 5,
            'traffic_limit_gb' => 0,
            'is_tls'           => true,
            'link_overrides'   => [
                'enabled' => true,
                'host'    => 'edge1.fastcdn.io',
                'port'    => 443,
                'suffix'  => '(Обход CDN)',
                'params'  => [
                    'sni'  => 'edge1.fastcdn.io',
                    'host' => 'origin.internal.host',
                    'path' => '/assets/app.js',
                ],
            ],
            'priority'         => 1,
        ]);

        // Создаем подписку
        $subscription = Subscription::create([
            'name'         => 'Test Subscription 1',
            'template_id'  => $template->id,
            'token'        => 'sub_token_123',
        ]);

        // Исходная ссылка с ноды
        $rawLink = 'vless://uuid-1234@88.99.100.101:2053?type=tcp&security=none#Hetzner%20Node';

        $nodeApi = app(\App\Services\NodeApiService::class);
        $isModernized = false;
        $transformedLink = $nodeApi->modernizeLink($rawLink, true, $isModernized, $ti->link_overrides);

        $config = Config::create([
            'node_id'         => $node->id,
            'inbound_id'      => 5,
            'name'            => 'Hetzner Node (Обход CDN)',
            'link'            => $transformedLink,
            'is_modernized'   => $isModernized,
            'link_overrides'  => $ti->link_overrides,
            'email'           => 'sub_client_email@app',
            'traffic_limit'   => 0,
            'priority'        => 1,
            'is_main'         => true,
        ]);

        $subscription->configs()->attach($config->id);

        // Проверяем результат модификации
        $this->assertTrue($isModernized);
        $this->assertStringContainsString('edge1.fastcdn.io', $transformedLink);
        $this->assertStringContainsString('origin.internal.host', $transformedLink);
        $this->assertStringContainsString(rawurlencode('/assets/app.js'), $transformedLink);

        // Обновляем шаблон: меняем host и path на произвольные
        $ti->update([
            'link_overrides' => [
                'enabled' => true,
                'host'    => 'edge2-new.fastcdn.io',
                'port'    => 443,
                'suffix'  => '(Обход CDN v2)',
                'params'  => [
                    'sni'  => 'edge2-new.fastcdn.io',
                    'host' => 'origin.internal.host',
                    'path' => '/new/endpoint',
                ],
            ],
        ]);

        // JIT-синхронизация
        $service = app(SubscriptionService::class);
        $changed = $service->syncWithTemplate($subscription);

        $this->assertTrue($changed);

        $config->refresh();
        $this->assertEquals('edge2-new.fastcdn.io', $config->link_overrides['host']);
        $this->assertStringContainsString('edge2-new.fastcdn.io', $config->link);
        $this->assertEquals('Hetzner Node (Обход CDN v2)', $config->name);

        // Ручная подписка (template_id = null) защищена
        $manualSub = Subscription::create([
            'name'        => 'Manual Sub',
            'template_id' => null,
            'token'       => 'manual_sub_token',
        ]);

        $this->assertFalse($service->syncWithTemplate($manualSub));
    }

    public function test_livewire_create_template_with_overrides()
    {
        $user = User::factory()->create();
        $node = Node::create([
            'name'     => 'Node Germany',
            'ip'       => '1.2.3.4',
            'api_key'  => 'key',
            'is_active'=> true,
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test('subscription-templates.create')
            ->set('name', 'Custom Overrides Template')
            ->set('slug', 'custom-overrides-lw')
            ->set('selectedNodeId', $node->id)
            ->set('selectedInboundId', 10)
            ->call('addInbound')
            ->assertCount('selectedInbounds', 1)
            ->call('openOverrideModal', 0)
            ->assertSet('editingOverrideIndex', 0)
            ->set('editingOverrides.enabled', true)
            ->set('editingOverrides.host', 'cdn.my-custom-proxy.com')
            ->set('editingOverrides.port', 8443)
            ->call('addOverrideParam', 'sni', 'cdn.my-custom-proxy.com')
            ->call('addOverrideParam', 'path', '/my-stream')
            ->call('saveOverrides')
            ->assertSet('editingOverrideIndex', null)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('subscription-templates.index'));

        $this->assertDatabaseHas('subscription_templates', [
            'slug' => 'custom-overrides-lw',
        ]);

        $created = SubscriptionTemplate::where('slug', 'custom-overrides-lw')->first();
        $inbound = $created->inbounds()->first();
        $this->assertNotNull($inbound);
        $this->assertEquals('cdn.my-custom-proxy.com', $inbound->link_overrides['host']);
        $this->assertEquals(8443, $inbound->link_overrides['port']);
        $this->assertEquals('/my-stream', $inbound->link_overrides['params']['path']);
    }

    public function test_livewire_edit_template_modal_overrides()
    {
        $user = User::factory()->create();
        $node = Node::create([
            'name'     => 'Node Finland',
            'ip'       => '5.6.7.8',
            'api_key'  => 'key',
            'is_active'=> true,
        ]);

        $template = SubscriptionTemplate::create([
            'name'      => 'Edit Template Test',
            'slug'      => 'edit-tpl-test',
            'is_active' => true,
        ]);

        TemplateInbound::create([
            'template_id'      => $template->id,
            'node_id'          => $node->id,
            'inbound_id'       => 20,
            'traffic_limit_gb' => 0,
            'is_tls'           => false,
            'link_overrides'   => null,
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test('subscription-templates.edit', ['templateId' => $template->id])
            ->assertCount('selectedInbounds', 1)
            ->call('openOverrideModal', 0)
            ->assertSet('editingOverrideIndex', 0)
            ->set('editingOverrides.enabled', true)
            ->set('editingOverrides.host', 'custom.cloudflare.domain.com')
            ->call('addOverrideParam', 'security', 'tls')
            ->call('addOverrideParam', 'type', 'xhttp')
            ->call('saveOverrides')
            ->assertSet('editingOverrideIndex', null)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('subscription-templates.index'));

        $updatedInbound = TemplateInbound::where('template_id', $template->id)->first();
        $this->assertNotNull($updatedInbound->link_overrides);
        $this->assertEquals('custom.cloudflare.domain.com', $updatedInbound->link_overrides['host']);
        $this->assertEquals('tls', $updatedInbound->link_overrides['params']['security']);
        $this->assertTrue((bool)$updatedInbound->is_tls);
    }

    public function test_backward_compatibility_with_legacy_flat_db_records()
    {
        $user = User::factory()->create();
        $node = Node::create([
            'name'     => 'Legacy Node',
            'ip'       => '1.1.1.1',
            'api_key'  => 'key',
            'is_active'=> true,
        ]);

        $template = SubscriptionTemplate::create([
            'name'      => 'Legacy Format Template',
            'slug'      => 'legacy-tpl',
            'is_active' => true,
        ]);

        // Точный формат существующей записи из реальной базы (id=9 в template_inbounds)
        $legacyDbOverrides = [
            'enabled'     => true,
            'host'        => 'pt0pegkjoi.cdn.twcstorage.ru',
            'port'        => 443,
            'sni'         => 'pt0pegkjoi.cdn.twcstorage.ru',
            'header_host' => 'cdn.komap.pw',
            'path'        => '/assets/vendor.js',
            'type'        => 'xhttp',
            'security'    => 'tls',
            'mode'        => 'packet-up',
        ];

        $ti = TemplateInbound::create([
            'template_id'      => $template->id,
            'node_id'          => $node->id,
            'inbound_id'       => 10,
            'traffic_limit_gb' => 18,
            'is_tls'           => true,
            'link_overrides'   => $legacyDbOverrides,
        ]);

        // 1. Проверяем, что NodeApiService корректно парсит и применяет плоские ключи
        $nodeApi = app(\App\Services\NodeApiService::class);
        $rawLink = 'vless://uuid-test@1.1.1.1:2053?type=tcp&security=none#Legacy%20Node';
        $isMod = false;
        $modernized = $nodeApi->modernizeLink($rawLink, true, $isMod, $ti->link_overrides);

        $this->assertTrue($isMod);
        $this->assertStringContainsString('pt0pegkjoi.cdn.twcstorage.ru:443', $modernized);
        $this->assertStringContainsString('host=cdn.komap.pw', $modernized);
        $this->assertStringContainsString('sni=pt0pegkjoi.cdn.twcstorage.ru', $modernized);
        $this->assertStringContainsString('type=xhttp', $modernized);
        $this->assertStringContainsString('mode=packet-up', $modernized);

        // 2. Проверяем, что Livewire форма редактирования загружает плоские ключи в список параметров
        $lw = \Livewire\Livewire::actingAs($user)
            ->test('subscription-templates.edit', ['templateId' => $template->id])
            ->call('openOverrideModal', 0);

        $params = $lw->get('editingOverrides.params');
        $this->assertNotEmpty($params);

        $keys = array_column($params, 'key');
        $this->assertContains('host', $keys);
        $this->assertContains('sni', $keys);
        $this->assertContains('path', $keys);
        $this->assertContains('type', $keys);
        $this->assertContains('security', $keys);
        $this->assertContains('mode', $keys);

        // И сохранение не теряет параметры
        $lw->call('saveOverrides')
           ->call('save')
           ->assertHasNoErrors();

        $savedInbound = TemplateInbound::where('template_id', $template->id)->first();
        $this->assertNotNull($savedInbound);
        $this->assertEquals('pt0pegkjoi.cdn.twcstorage.ru', $savedInbound->link_overrides['host']);
        $this->assertEquals('cdn.komap.pw', $savedInbound->link_overrides['params']['host']);
    }

    public function test_override_with_only_suffix_is_preserved()
    {
        $user = User::factory()->create();
        $node = Node::create([
            'name'     => 'Node Suffix Test',
            'ip'       => '2.2.2.2',
            'api_key'  => 'key',
            'is_active'=> true,
        ]);

        \Livewire\Livewire::actingAs($user)
            ->test('subscription-templates.create')
            ->set('name', 'Suffix Template')
            ->set('slug', 'suffix-tpl')
            ->set('selectedNodeId', $node->id)
            ->set('selectedInboundId', 1)
            ->call('addInbound')
            ->call('openOverrideModal', 0)
            ->set('editingOverrides.enabled', true)
            ->set('editingOverrides.suffix', '(Резерв)')
            ->call('saveOverrides')
            ->call('save')
            ->assertHasNoErrors();

        $template = SubscriptionTemplate::where('slug', 'suffix-tpl')->first();
        $inbound = $template->inbounds()->first();
        $this->assertNotNull($inbound->link_overrides);
        $this->assertEquals('(Резерв)', $inbound->link_overrides['suffix']);

        // Проверяем имя конфига через resolveConfigName
        $service = app(SubscriptionService::class);
        $configName = (new \ReflectionMethod($service, 'resolveConfigName'))->invoke($service, $node, $inbound);
        $this->assertEquals('Node Suffix Test (Резерв)', $configName);
    }

    public function test_is_main_consistency_between_create_and_sync()
    {
        $node = Node::create([
            'name'     => 'Main Test Node',
            'ip'       => '3.3.3.3',
            'api_key'  => 'key',
            'is_active'=> true,
        ]);

        $template = SubscriptionTemplate::create([
            'name'      => 'Main Sync Template',
            'slug'      => 'main-sync-tpl',
            'is_active' => true,
        ]);

        $ti = TemplateInbound::create([
            'template_id'      => $template->id,
            'node_id'          => $node->id,
            'inbound_id'       => 15,
            'traffic_limit_gb' => 0,
            'is_tls'           => false,
            'link_overrides'   => [
                'enabled' => true,
                'host'    => 'edge.cf.example.com',
                'port'    => 443,
            ],
            'priority'         => 1,
        ]);

        $service = app(SubscriptionService::class);
        $isMain = $service->resolveIsMain($ti);
        $this->assertTrue($isMain, 'resolveIsMain must return true when link_overrides has enabled overrides');
    }
}
