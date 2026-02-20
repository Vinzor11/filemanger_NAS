<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginPasswordCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_recovers_legacy_plaintext_password_hash_without_server_error(): void
    {
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'status' => 'active',
        ]);

        User::query()
            ->whereKey($user->id)
            ->update([
                'password_hash' => 'password',
            ]);

        $response = $this->post(route('login.store'), [
            'login' => 'legacy@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('explorer.my'));
        $this->assertAuthenticated();

        $user->refresh();
        $this->assertNotSame('password', $user->password_hash);
        $this->assertTrue(Hash::check('password', $user->password_hash));
    }
}
