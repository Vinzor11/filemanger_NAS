<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class PendingApprovalIdempotencyTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_admin_approval_is_processed_once_for_same_idempotency_key(): void
    {
        Role::findOrCreate('Employee', 'web');

        $admin = $this->createUser();
        $this->grantPermissions($admin, ['users.approve']);

        $target = $this->createUser(
            userAttributes: [
                'status' => 'pending',
                'approved_at' => null,
            ],
        );

        $payload = [
            'roles' => ['Employee'],
        ];

        $first = $this->actingAs($admin)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Idempotency-Key', 'approve-user-1')
            ->post(route('admin.approvals.approve', $target->public_id), $payload);

        $first->assertRedirect(route('admin.approvals.index'));

        $replay = $this->actingAs($admin)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Idempotency-Key', 'approve-user-1')
            ->post(route('admin.approvals.approve', $target->public_id), $payload);

        $replay->assertRedirect(route('admin.approvals.index'));

        $target->refresh();

        $this->assertSame('active', $target->status);
        $this->assertNotNull($target->approved_at);
        $this->assertTrue($target->hasRole('Employee'));
        $this->assertSame(1, AuditLog::query()->where('action', 'user.approved')->count());
        $this->assertDatabaseHas('idempotency_keys', [
            'actor_user_id' => $admin->id,
            'scope' => 'approve-user',
            'idempotency_key' => 'approve-user-1',
            'status' => 'completed',
        ]);
        $this->assertSame(1, IdempotencyKey::query()->count());
    }

    public function test_admin_approval_rejects_same_idempotency_key_with_different_payload(): void
    {
        Role::findOrCreate('Employee', 'web');
        Role::findOrCreate('DepartmentManager', 'web');

        $admin = $this->createUser();
        $this->grantPermissions($admin, ['users.approve']);

        $target = $this->createUser(
            userAttributes: [
                'status' => 'pending',
                'approved_at' => null,
            ],
        );

        $this->actingAs($admin)
            ->from(route('admin.approvals.index'))
            ->withHeader('X-Idempotency-Key', 'approve-user-2')
            ->post(route('admin.approvals.approve', $target->public_id), [
                'roles' => ['Employee'],
            ])
            ->assertRedirect(route('admin.approvals.index'));

        $response = $this->actingAs($admin)
            ->withHeader('X-Idempotency-Key', 'approve-user-2')
            ->post(route('admin.approvals.approve', $target->public_id), [
                'roles' => ['DepartmentManager'],
            ]);

        $response
            ->assertStatus(409)
            ->assertJson([
                'message' => 'Idempotency key reuse with different payload.',
            ]);

        $this->assertSame(1, AuditLog::query()->where('action', 'user.approved')->count());
    }
}
