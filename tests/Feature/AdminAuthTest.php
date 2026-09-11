<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class]);
    }

    public function test_beheer_stays_open_when_admin_password_is_empty(): void
    {
        config(['ironforge.admin_password' => '']);

        $this->get('/beheer')
            ->assertOk()
            ->assertSee('IRONFORGE BEHEER');
    }

    public function test_beheer_redirects_to_login_when_password_is_set(): void
    {
        config(['ironforge.admin_password' => 'secret-admin']);

        $this->get('/beheer')
            ->assertRedirect(route('admin.login'));
    }

    public function test_wrong_password_returns_validation_error(): void
    {
        config(['ironforge.admin_password' => 'secret-admin']);

        $this->from(route('admin.login'))
            ->post('/beheer/login', ['password' => 'wrong'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('password');
    }

    public function test_correct_password_opens_the_dashboard(): void
    {
        config(['ironforge.admin_password' => 'secret-admin']);

        $this->post('/beheer/login', ['password' => 'secret-admin'])
            ->assertRedirect(route('admin.dashboard'));

        $this->get('/beheer')
            ->assertOk()
            ->assertSee('IRONFORGE BEHEER');
    }
}
