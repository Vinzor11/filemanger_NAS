<?php

namespace Tests\Feature\Explorer;

use App\Services\ExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class SharingFeatureTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_available_employees_endpoint_returns_active_employees_and_excludes_actor(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);

        $folder = $this->createPrivateFolder($actor);
        $file = $this->createFile($folder, $actor);

        $activeTarget = $this->createUser($department, [
            'email' => 'active-target@example.test',
            'status' => 'active',
        ]);

        $inactiveUser = $this->createUser($department, [
            'email' => 'inactive-user@example.test',
            'status' => 'pending',
        ]);

        $inactiveEmployeeUser = $this->createUser($department, [
            'email' => 'inactive-employee@example.test',
            'status' => 'active',
        ]);
        $inactiveEmployeeUser->employee()->update([
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($actor)
            ->getJson(route('files.share.available-employees', $file->public_id));

        $response->assertOk();
        $response->assertJsonFragment([
            'public_id' => $activeTarget->public_id,
            'email' => 'active-target@example.test',
        ]);
        $response->assertJsonMissing([
            'public_id' => $actor->public_id,
        ]);
        $response->assertJsonMissing([
            'public_id' => $inactiveUser->public_id,
        ]);
        $response->assertJsonMissing([
            'public_id' => $inactiveEmployeeUser->public_id,
        ]);
    }

    public function test_file_and_folder_activities_endpoints_return_ok_for_owner(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $this->grantPermissions($actor, ['files.view']);

        $folder = $this->createPrivateFolder($actor);
        $file = $this->createFile($folder, $actor);

        $this->actingAs($actor)
            ->getJson(route('files.activities', $file->public_id))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->actingAs($actor)
            ->getJson(route('folders.activities', $folder->public_id))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_file_can_be_shared_to_selected_employees(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($actor, [
            'visibility' => 'private',
        ]);
        $file = $this->createFile($folder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $response = $this->actingAs($actor)
            ->from(route('folders.show', $folder->public_id))
            ->post(route('files.share.users', $file->public_id), [
                'shares' => [
                    [
                        'user_id' => $target->id,
                        'can_view' => true,
                        'can_download' => true,
                        'can_edit' => false,
                        'can_delete' => false,
                    ],
                ],
            ]);

        $response->assertRedirect(route('folders.show', $folder->public_id));
        $response->assertSessionHas('status', 'File sharing updated.');
        $this->assertDatabaseHas('file_permissions', [
            'file_id' => $file->id,
            'user_id' => $target->id,
            'can_view' => true,
            'can_download' => true,
        ]);
    }

    public function test_file_share_permissions_can_restrict_download(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($actor, [
            'visibility' => 'private',
        ]);
        $file = $this->createFile($folder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $response = $this->actingAs($actor)
            ->post(route('files.share.users', $file->public_id), [
                'shares' => [
                    [
                        'user_id' => $target->id,
                        'can_view' => true,
                        'can_download' => false,
                        'can_edit' => false,
                        'can_delete' => false,
                    ],
                ],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('file_permissions', [
            'file_id' => $file->id,
            'user_id' => $target->id,
            'can_view' => true,
            'can_download' => false,
        ]);
        $this->assertTrue($target->can('view', $file->fresh()));
        $this->assertFalse($target->can('download', $file->fresh()));
    }

    public function test_file_share_permissions_grant_edit_and_delete_without_global_permissions(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view']);

        $folder = $this->createPrivateFolder($actor, [
            'visibility' => 'private',
        ]);
        $file = $this->createFile($folder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $response = $this->actingAs($actor)
            ->post(route('files.share.users', $file->public_id), [
                'shares' => [
                    [
                        'user_id' => $target->id,
                        'can_view' => true,
                        'can_download' => false,
                        'can_edit' => true,
                        'can_delete' => true,
                    ],
                ],
            ]);

        $response->assertRedirect();
        $this->assertTrue($target->can('update', $file->fresh()));
        $this->assertTrue($target->can('delete', $file->fresh()));
    }

    public function test_my_files_and_folder_contents_include_sharing_metadata_with_recipients(): void
    {
        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $target = $this->createUser($department, [
            'email' => 'recipient@example.test',
        ]);
        $this->grantPermissions($owner, ['share.manage', 'files.view']);

        $rootFolder = $this->createPrivateFolder($owner, [
            'name' => 'Projects',
            'path' => 'Projects',
            'visibility' => 'private',
        ]);
        $childFolder = $this->createPrivateFolder($owner, [
            'name' => 'Docs',
            'parent_id' => $rootFolder->id,
            'path' => 'Projects/Docs',
            'visibility' => 'private',
        ]);
        $sharedFile = $this->createFile($rootFolder, $owner, [
            'original_name' => 'roadmap.txt',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($owner)->post(route('folders.share.users', $rootFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_upload' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('folders.share.users', $childFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_upload' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('files.share.users', $sharedFile->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_download' => true,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $myFilesPayload = app(ExplorerService::class)->myFiles($owner, ['per_page' => 20]);
        $rootFolderRow = $myFilesPayload['folders']->firstWhere('id', $rootFolder->id);

        $this->assertNotNull($rootFolderRow);
        $this->assertTrue((bool) data_get($rootFolderRow, 'sharing.is_shared'));
        $this->assertSame($target->public_id, data_get($rootFolderRow, 'sharing.shared_with.0.public_id'));
        $this->assertSame('recipient@example.test', data_get($rootFolderRow, 'sharing.shared_with.0.email'));

        $contentsPayload = app(ExplorerService::class)->folderContents($rootFolder, ['per_page' => 20]);
        $childFolderRow = $contentsPayload['children']->firstWhere('id', $childFolder->id);
        $fileRow = collect($contentsPayload['files']->items())->firstWhere('id', $sharedFile->id);

        $this->assertNotNull($childFolderRow);
        $this->assertTrue((bool) data_get($childFolderRow, 'sharing.is_shared'));
        $this->assertSame($target->public_id, data_get($childFolderRow, 'sharing.shared_with.0.public_id'));
        $this->assertSame('recipient@example.test', data_get($childFolderRow, 'sharing.shared_with.0.email'));

        $this->assertNotNull($fileRow);
        $this->assertTrue((bool) data_get($fileRow, 'sharing.is_shared'));
        $this->assertSame($target->public_id, data_get($fileRow, 'sharing.shared_with.0.public_id'));
        $this->assertSame('recipient@example.test', data_get($fileRow, 'sharing.shared_with.0.email'));
    }

    public function test_shared_with_me_payload_includes_file_access_flags_from_share_permissions(): void
    {
        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($owner, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view']);

        $folder = $this->createPrivateFolder($owner, [
            'visibility' => 'private',
        ]);
        $file = $this->createFile($folder, $owner, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($owner)->post(route('files.share.users', $file->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_download' => true,
                    'can_edit' => true,
                    'can_delete' => true,
                ],
            ],
        ])->assertRedirect();

        $payload = app(ExplorerService::class)->sharedWithMe($target, ['per_page' => 20]);
        $files = $payload['files'];
        $row = collect($files->items())->firstWhere('id', $file->id);

        $this->assertNotNull($row);
        $this->assertCount(0, $payload['folders']);
        $this->assertTrue((bool) data_get($row, 'access.can_view'));
        $this->assertTrue((bool) data_get($row, 'access.can_download'));
        $this->assertTrue((bool) data_get($row, 'access.can_edit'));
        $this->assertTrue((bool) data_get($row, 'access.can_delete'));
    }

    public function test_shared_with_me_shows_shared_folder_roots_and_keeps_subtree_files_inside_folder_structure(): void
    {
        $department = $this->createDepartment();
        $owner = $this->createUser($department);
        $target = $this->createUser($department);
        $this->grantPermissions($owner, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view']);

        $rootFolder = $this->createPrivateFolder($owner, [
            'name' => 'Shared Root',
            'path' => 'Shared Root',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $nestedFolder = $this->createPrivateFolder($owner, [
            'name' => 'Nested',
            'parent_id' => $rootFolder->id,
            'path' => 'Shared Root/Nested',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $nestedFile = $this->createFile($nestedFolder, $owner, [
            'visibility' => 'private',
            'department_id' => null,
            'original_name' => 'nested-file.txt',
        ]);

        $directFolder = $this->createPrivateFolder($owner, [
            'name' => 'Direct Folder',
            'path' => 'Direct Folder',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $directFile = $this->createFile($directFolder, $owner, [
            'visibility' => 'private',
            'department_id' => null,
            'original_name' => 'direct-file.txt',
        ]);

        $this->actingAs($owner)->post(route('folders.share.users', $rootFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_upload' => false,
                    'can_edit' => true,
                    'can_delete' => true,
                ],
            ],
        ])->assertRedirect();

        // Directly sharing a nested folder should not duplicate it in the Shared With Me root.
        $this->actingAs($owner)->post(route('folders.share.users', $nestedFolder->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_upload' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('files.share.users', $directFile->public_id), [
            'shares' => [
                [
                    'user_id' => $target->id,
                    'can_view' => true,
                    'can_download' => false,
                    'can_edit' => true,
                    'can_delete' => false,
                ],
            ],
        ])->assertRedirect();

        $payload = app(ExplorerService::class)->sharedWithMe($target, ['per_page' => 20]);
        $folders = $payload['folders'];
        $files = $payload['files'];

        $this->assertCount(1, $folders);
        $this->assertSame($rootFolder->id, $folders->first()?->id);

        $nestedRow = collect($files->items())->firstWhere('id', $nestedFile->id);
        $directRow = collect($files->items())->firstWhere('id', $directFile->id);

        $this->assertNull($nestedRow);
        $this->assertNotNull($directRow);
        $this->assertTrue((bool) data_get($directRow, 'access.can_view'));
        $this->assertFalse((bool) data_get($directRow, 'access.can_download'));
        $this->assertTrue((bool) data_get($directRow, 'access.can_edit'));
        $this->assertFalse((bool) data_get($directRow, 'access.can_delete'));
    }

    public function test_file_can_be_shared_to_actors_department_for_my_files(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $peer = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($peer, ['files.view']);

        $folder = $this->createPrivateFolder($actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $file = $this->createFile($folder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->assertFalse($peer->can('view', $file));

        $response = $this->actingAs($actor)
            ->from(route('folders.show', $folder->public_id))
            ->post(route('files.share.department', $file->public_id));

        $response->assertRedirect(route('folders.show', $folder->public_id));
        $response->assertSessionHas('status', 'File shared with your department.');
        $this->assertDatabaseHas('files', [
            'id' => $file->id,
            'visibility' => 'department',
            'department_id' => $actor->employee?->department_id,
        ]);

        $this->assertTrue($peer->can('view', $file->fresh()));
    }

    public function test_department_share_applies_configured_file_permissions(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $peer = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($peer, ['files.view', 'files.download']);

        $folder = $this->createPrivateFolder($actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $file = $this->createFile($folder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $response = $this->actingAs($actor)
            ->post(route('files.share.department', $file->public_id), [
                'can_view' => true,
                'can_download' => false,
                'can_edit' => true,
                'can_delete' => false,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('file_permissions', [
            'file_id' => $file->id,
            'user_id' => $peer->id,
            'can_view' => true,
            'can_download' => false,
            'can_edit' => true,
            'can_delete' => false,
        ]);

        $this->assertTrue($peer->can('view', $file->fresh()));
        $this->assertFalse($peer->can('download', $file->fresh()));
        $this->assertTrue($peer->can('update', $file->fresh()));
        $this->assertFalse($peer->can('delete', $file->fresh()));
    }

    public function test_folder_can_be_shared_to_selected_employee_with_permissions_for_nested_files(): void
    {
        $ownerDepartment = $this->createDepartment(['code' => 'OWN-DEP']);
        $targetDepartment = $this->createDepartment(['code' => 'TGT-DEP']);

        $actor = $this->createUser($ownerDepartment);
        $target = $this->createUser($targetDepartment);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($target, ['files.view']);

        $folder = $this->createPrivateFolder($actor, [
            'name' => 'Shared Root',
            'path' => 'Shared Root',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $childFolder = $this->createPrivateFolder($actor, [
            'name' => 'Child',
            'parent_id' => $folder->id,
            'path' => 'Shared Root/Child',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $file = $this->createFile($childFolder, $actor, [
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->assertFalse($target->can('view', $folder));
        $this->assertFalse($target->can('view', $childFolder));
        $this->assertFalse($target->can('view', $file));

        $response = $this->actingAs($actor)
            ->from(route('folders.show', $folder->public_id))
            ->post(route('folders.share.users', $folder->public_id), [
                'shares' => [
                    [
                        'user_id' => $target->id,
                        'can_view' => true,
                        'can_upload' => false,
                        'can_edit' => true,
                        'can_delete' => true,
                    ],
                ],
            ]);

        $response->assertRedirect(route('folders.show', $folder->public_id));
        $response->assertSessionHas('status', 'Folder sharing updated.');
        $this->assertDatabaseHas('folder_permissions', [
            'folder_id' => $folder->id,
            'user_id' => $target->id,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
        ]);
        $this->assertTrue($target->can('view', $folder->fresh()));
        $this->assertTrue($target->can('view', $childFolder->fresh()));
        $this->assertTrue($target->can('view', $file->fresh()));
        $this->assertTrue($target->can('download', $file->fresh()));
        $this->assertTrue($target->can('update', $file->fresh()));
        $this->assertTrue($target->can('delete', $file->fresh()));
    }

    public function test_department_files_include_department_shared_folders_and_direct_files(): void
    {
        $department = $this->createDepartment();
        $actor = $this->createUser($department);
        $peer = $this->createUser($department);
        $this->grantPermissions($actor, ['share.manage', 'files.view']);
        $this->grantPermissions($peer, ['files.view']);

        $departmentSharedFolder = $this->createPrivateFolder($actor, [
            'name' => 'Dept Shared Folder',
            'path' => 'Dept Shared Folder',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $fileInsideSharedFolder = $this->createFile($departmentSharedFolder, $actor, [
            'original_name' => 'inside-folder.txt',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $privateFolderForDirectFile = $this->createPrivateFolder($actor, [
            'name' => 'Private Source',
            'path' => 'Private Source',
            'visibility' => 'private',
            'department_id' => null,
        ]);
        $directDepartmentSharedFile = $this->createFile($privateFolderForDirectFile, $actor, [
            'original_name' => 'direct-shared.txt',
            'visibility' => 'private',
            'department_id' => null,
        ]);

        $this->actingAs($actor)->post(route('folders.share.department', $departmentSharedFolder->public_id), [
            'can_view' => true,
            'can_upload' => false,
            'can_edit' => true,
            'can_delete' => false,
        ])->assertRedirect();

        $this->actingAs($actor)->post(route('files.share.department', $directDepartmentSharedFile->public_id), [
            'can_view' => true,
            'can_download' => false,
            'can_edit' => true,
            'can_delete' => false,
        ])->assertRedirect();

        $payload = app(ExplorerService::class)->departmentFiles($peer, ['per_page' => 20]);
        $folders = $payload['folders'];
        $files = $payload['files'];

        $this->assertNotNull($folders->firstWhere('id', $departmentSharedFolder->id));

        $insideFolderRow = collect($files->items())->firstWhere('id', $fileInsideSharedFolder->id);
        $directFileRow = collect($files->items())->firstWhere('id', $directDepartmentSharedFile->id);

        $this->assertNull($insideFolderRow);
        $this->assertNotNull($directFileRow);
        $this->assertTrue((bool) data_get($directFileRow, 'access.can_view'));
        $this->assertFalse((bool) data_get($directFileRow, 'access.can_download'));
        $this->assertTrue((bool) data_get($directFileRow, 'access.can_edit'));
        $this->assertFalse((bool) data_get($directFileRow, 'access.can_delete'));
    }
}
