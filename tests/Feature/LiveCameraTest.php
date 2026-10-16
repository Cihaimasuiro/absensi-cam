<?php

namespace Tests\Feature;

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveCameraTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_live_camera(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('live-camera.index');
        $response->assertSee('Live Kamera &amp; Monitor Presensi', false);
    }

    public function test_authenticated_user_can_poll_recent_logs(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('live-camera.recent'));

        $response->assertOk();
        $response->assertJsonStructure([
            'present_today',
            'logs',
        ]);
    }
}
