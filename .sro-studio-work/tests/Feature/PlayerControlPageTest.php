<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerControlPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_redirects_to_the_single_player_tool(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/live');
    }

    public function test_player_control_has_only_the_focused_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/live');

        $response->assertOk();
        $response->assertSee('Player Control');
        $response->assertSee('Item Finder');
        $response->assertDontSee('Schema &amp; Tables', false);
        $response->assertDontSee('NPC Shops');
        $response->assertDontSee('Maintenance Vault');
    }
}
