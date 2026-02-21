<?php

namespace Tests\Feature\Explorer;

use App\Models\Folder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class FolderCrudTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_private_folder_create_sets_owner_scope(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.create']);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->post(route('folders.store'), [
                'name' => 'Contracts',
                'scope' => 'private',
            ]);

        $folder = Folder::query()
            ->where('name', 'Contracts')
            ->firstOrFail();

        $response->assertRedirect(route('folders.show', $folder->public_id));
        $this->assertSame($user->id, $folder->owner_user_id);
        $this->assertNull($folder->department_id);
        $this->assertSame('private', $folder->visibility);
        $this->assertFalse($folder->is_deleted);
    }

    public function test_department_folder_create_requires_department_permission(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.create']);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->post(route('folders.store'), [
                'name' => 'Shared Projects',
                'scope' => 'department',
            ]);

        $response->assertRedirect(route('explorer.my'));
        $response->assertSessionHasErrors('scope');
        $this->assertDatabaseMissing('folders', [
            'name' => 'Shared Projects',
            'department_id' => $user->employee?->department_id,
            'is_deleted' => false,
        ]);
    }

    public function test_department_folder_create_sets_department_scope_when_allowed(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.create', 'folders.create_department']);

        $response = $this->actingAs($user)
            ->from(route('explorer.department'))
            ->post(route('folders.store'), [
                'name' => 'Dept Shared',
                'scope' => 'department',
            ]);

        $folder = Folder::query()
            ->where('name', 'Dept Shared')
            ->firstOrFail();

        $response->assertRedirect(route('folders.show', $folder->public_id));
        $this->assertNull($folder->owner_user_id);
        $this->assertSame($user->employee?->department_id, $folder->department_id);
        $this->assertSame('department', $folder->visibility);
        $this->assertFalse($folder->is_deleted);
    }

    public function test_folder_move_rejects_cycle_destination(): void
    {
        $user = $this->createUser();
        $this->grantPermissions($user, ['folders.update']);

        $root = $this->createPrivateFolder($user, [
            'name' => 'Root',
            'path' => 'Root',
        ]);

        $child = $this->createPrivateFolder($user, [
            'name' => 'Child',
            'parent_id' => $root->id,
            'path' => 'Root/Child',
        ]);

        $response = $this->actingAs($user)
            ->from(route('explorer.my'))
            ->patch(route('folders.move', $root->public_id), [
                'destination_folder_uuid' => $child->public_id,
            ]);

        $response->assertRedirect(route('explorer.my'));
        $response->assertSessionHasErrors('destination_folder_id');
        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_create_subfolder_in_shared_folder_sets_owner_to_creator(): void
    {
        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $recipient = $this->createUser($department);

        $this->grantPermissions($owner, ['share.manage', 'files.view']);
        $this->grantPermissions($recipient, ['folders.create']);

        $sharedParent = $this->createPrivateFolder($owner, [
            'name' => 'Shared Parent',
            'path' => 'Shared Parent',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($owner)->post(route('folders.share.users', $sharedParent->public_id), [
            'shares' => [
                [
                    'user_id' => $recipient->id,
                    'can_view' => true,
                    'can_upload' => true,
                    'can_edit' => true,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $response = $this->actingAs($recipient)
            ->from(route('folders.show', $sharedParent->public_id))
            ->post(route('folders.store'), [
                'parent_id' => $sharedParent->public_id,
                'name' => 'Recipient Child',
            ]);

        $child = Folder::query()
            ->where('parent_id', $sharedParent->id)
            ->where('name', 'Recipient Child')
            ->where('is_deleted', false)
            ->firstOrFail();

        $response->assertRedirect(route('folders.show', $child->public_id));
        $this->assertSame($recipient->id, $child->owner_user_id);
        $this->assertNull($child->department_id);
        $this->assertSame('private', $child->visibility);
    }
}
