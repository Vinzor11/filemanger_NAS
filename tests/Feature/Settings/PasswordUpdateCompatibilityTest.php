<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_update_accepts_legacy_plaintext_current_password(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        User::query()
            ->whereKey($user->id)
            ->update([
                'password_hash' => 'password',
            ]);

        $response = $this->actingAs($user)
            ->from(route('user-password.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'NewStrongPassword!123',
                'password_confirmation' => 'NewStrongPassword!123',
            ]);

        $response->assertRedirect(route('user-password.edit'));
        $response->assertSessionHas('status', 'Password updated.');

        $user->refresh();
        $this->assertNotSame('NewStrongPassword!123', $user->password_hash);
        $this->assertTrue(Hash::check('NewStrongPassword!123', $user->password_hash));
    }
}
