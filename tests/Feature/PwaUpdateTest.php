<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaUpdateTest extends TestCase
{
    public function test_service_worker_is_network_first_and_skips_waiting(): void
    {
        $response = $this->get('/sw.js');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee('skipWaiting', false)
            ->assertSee("pathname.startsWith('/api/')", false)
            ->assertSee("pathname.startsWith('/beheer')", false)
            ->assertSee('isPassthrough', false)
            ->assertSee("url.pathname === '/'", false)
            ->assertSee("event.request.mode === 'navigate'", false)
            ->assertDontSee('ironforge-laravel-v1', false);

        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_manifest_is_standalone(): void
    {
        $response = $this->get('/manifest.json')->assertOk();
        $manifest = json_decode($response->getContent(), true);

        $this->assertIsArray($manifest);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('IronForge', $manifest['short_name']);
    }
}
