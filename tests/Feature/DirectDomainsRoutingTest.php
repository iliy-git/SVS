<?php

namespace Tests\Feature;

use App\Models\Config;
use App\Models\Subscription;
use App\Services\XrayProfileBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectDomainsRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_xray_profile_builder_includes_direct_domains(): void
    {
        $sub = Subscription::create([
            'name'          => 'Test Direct Routing Sub',
            'token'         => 'test-routing-token',
            'with_balancer' => false,
        ]);

        $conf = Config::create([
            'name'      => 'Node Direct Test',
            'link'      => 'vless://uuid-test@1.2.3.4:443?type=tcp&security=tls#Test',
            'is_active' => true,
        ]);

        $sub->configs()->attach($conf->id);

        $builder = app(XrayProfileBuilder::class);
        $profile = $builder->buildSuccessProfile($sub);

        $firstNode = $profile['nodes'][0] ?? null;
        $this->assertNotNull($firstNode);

        // Find geosite rule in routing
        $routingRules = $firstNode['routing']['rules'] ?? [];
        $directDomainRule = collect($routingRules)->first(function ($rule) {
            return ($rule['outboundTag'] ?? '') === 'direct' && !empty($rule['domain']);
        });

        $this->assertNotNull($directDomainRule);
        $this->assertIsArray($directDomainRule['domain']);
        $this->assertGreaterThan(10, count($directDomainRule['domain']));
        $this->assertContains('regexp:.*\.ru$', $directDomainRule['domain']);
    }
}
