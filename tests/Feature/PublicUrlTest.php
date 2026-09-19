<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Serving the shop through a tunnel.
 *
 * A tunnel terminates TLS and forwards the request on as plain HTTP, with the
 * real scheme and host in X-Forwarded-*. Those headers are only believed when
 * TRUSTED_PROXIES says so, which is what this pins down.
 */
class PublicUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function throughTunnel(string $host = 'shop-8000.devtunnels.ms')
    {
        return $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => $host,
            'X-Forwarded-Port' => '443',
        ])->get('/');
    }

    public function test_forwarded_headers_are_ignored_until_the_proxy_is_trusted(): void
    {
        // Trusting a proxy you do not control lets a caller forge the host
        // Laravel believes it is serving, so this stays off until TRUSTED_PROXIES
        // is set for a run that is genuinely behind one.
        $this->throughTunnel()
            ->assertOk()
            ->assertDontSee('https://shop-8000.devtunnels.ms/products', false);
    }
}
