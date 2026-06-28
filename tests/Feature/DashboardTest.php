<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_with_an_active_campaign_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        Membership::factory()->for($tenant)->for($user)->create(['activo' => true]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get(route('dashboard'));

        $response->assertOk();
    }
}
