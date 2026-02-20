<?php

namespace Tests\Unit\Actions;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_action_stores_hashed_password(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'NewStrongPassword!123',
            'password_confirmation' => 'NewStrongPassword!123',
        ]);

        $user->refresh();

        $this->assertNotSame('NewStrongPassword!123', $user->password_hash);
        $this->assertTrue(Hash::check('NewStrongPassword!123', $user->password_hash));
    }
}
