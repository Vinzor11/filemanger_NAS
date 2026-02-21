<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\PendingApprovalController;
use App\Http\Controllers\Auth\ClaimRegistrationController;
use App\Http\Controllers\Explorer\ExplorerController;
use App\Http\Controllers\Explorer\FileController;
use App\Http\Controllers\Explorer\FolderController;
use App\Http\Controllers\Explorer\SelectionController;
use App\Http\Controllers\Explorer\ShareController;
use App\Http\Controllers\Explorer\SearchController;
use App\Http\Controllers\Explorer\TrashController;
use App\Http\Controllers\PublicAccess\ShareLinkAccessController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('explorer.my');
    }

    return Inertia::render('welcome', [
        'canRegister' => true,
    ]);
})->name('home');

Route::get('/register', [ClaimRegistrationController::class, 'create'])
    ->middleware(['guest', 'throttle:register'])
    ->name('auth.register');
Route::post('/register', [ClaimRegistrationController::class, 'store'])
    ->middleware(['guest', 'throttle:register'])
    ->name('auth.register.store');
Route::get('/register/pending', [ClaimRegistrationController::class, 'pending'])
    ->middleware('guest')
    ->name('auth.register.pending');

Route::middleware(['auth', 'active.user'])->group(function () {
    Route::get('/dashboard', fn () => redirect()->route('explorer.my'))->name('dashboard');

    Route::get('/my-files', [ExplorerController::class, 'myFiles'])->name('explorer.my');
    Route::get('/department-files', [ExplorerController::class, 'departmentFiles'])->name('explorer.department');
    Route::get('/shared-with-me', [ExplorerController::class, 'sharedWithMe'])->name('explorer.shared');
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('search.suggestions');
    Route::post('/selection/trash', [SelectionController::class, 'trash'])
        ->middleware('idempotency:selection-trash')
        ->name('selection.trash');
    Route::post('/selection/move', [SelectionController::class, 'move'])
        ->middleware('idempotency:selection-move')
        ->name('selection.move');
    Route::get('/selection/download', [SelectionController::class, 'download'])
        ->middleware('throttle:download')
        ->name('selection.download');
    Route::get('/selection/share/available-employees', [SelectionController::class, 'availableEmployees'])
        ->name('selection.share.available-employees');
    Route::post('/selection/share/users', [SelectionController::class, 'shareUsers'])
        ->middleware('idempotency:selection-share-users')
        ->name('selection.share.users');
    Route::post('/selection/share/department', [SelectionController::class, 'shareDepartment'])
        ->middleware('idempotency:selection-share-department')
        ->name('selection.share.department');
    Route::post('/selection/restore', [SelectionController::class, 'restore'])
        ->middleware('idempotency:selection-restore')
        ->name('selection.restore');
    Route::post('/selection/purge', [SelectionController::class, 'purge'])
        ->middleware('idempotency:selection-purge')
        ->name('selection.purge');
    Route::get('/folders/{folder:public_id}', [FolderController::class, 'show'])->name('folders.show');
    Route::get('/folders/{folder:public_id}/download', [FolderController::class, 'download'])
        ->middleware('throttle:download')
        ->name('folders.download');
    Route::get('/folders/{folder:public_id}/activities', [FolderController::class, 'activities'])
        ->name('folders.activities');
    Route::post('/folders', [FolderController::class, 'store'])
        ->name('folders.store');
    Route::patch('/folders/{folder:public_id}', [FolderController::class, 'update'])
        ->name('folders.update');
    Route::patch('/folders/{folder:public_id}/move', [FolderController::class, 'move'])
        ->name('folders.move');
    Route::delete('/folders/{folder:public_id}', [FolderController::class, 'destroy'])
        ->middleware('idempotency:delete-folder')
        ->name('folders.destroy');
    Route::delete('/folders/{folder:public_id}/purge', [FolderController::class, 'purge'])
        ->middleware('idempotency:purge-folder')
        ->name('folders.purge');
    Route::post('/folders/{folder:public_id}/restore', [FolderController::class, 'restore'])
        ->middleware('idempotency:restore-folder')
        ->name('folders.restore');
    Route::post('/folders/{folder:public_id}/share/users', [ShareController::class, 'shareFolderUsers'])
        ->name('folders.share.users');
    Route::get('/folders/{folder:public_id}/share/users', [ShareController::class, 'listFolderUserShares'])
        ->name('folders.share.users.index');
    Route::delete('/folders/{folder:public_id}/share/users/{targetUser:public_id}', [ShareController::class, 'revokeFolderUserShare'])
        ->withoutScopedBindings()
        ->name('folders.share.users.revoke');
    Route::post('/folders/{folder:public_id}/share/department', [ShareController::class, 'shareFolderDepartment'])
        ->name('folders.share.department');
    Route::get('/folders/{folder:public_id}/share/available-employees', [ShareController::class, 'availableEmployeesForFolder'])
        ->name('folders.share.available-employees');

    Route::post('/files', [FileController::class, 'upload'])
        ->middleware('idempotency:upload-file')
        ->name('files.store');
    Route::post('/files/upload', [FileController::class, 'upload'])
        ->middleware('idempotency:upload-file')
        ->name('files.upload');
    Route::patch('/files/{file:public_id}/replace', [FileController::class, 'replace'])
        ->middleware('idempotency:replace-file')
        ->name('files.replace');
    Route::patch('/files/{file:public_id}/rename', [FileController::class, 'rename'])
        ->name('files.rename');
    Route::patch('/files/{file:public_id}/move', [FileController::class, 'move'])
        ->name('files.move');
    Route::patch('/files/{file:public_id}/visibility', [FileController::class, 'updateVisibility'])
        ->name('files.visibility.update');
    Route::put('/files/{file:public_id}/tags', [FileController::class, 'syncTags'])
        ->name('files.tags.sync');
    Route::get('/files/{file:public_id}', [FileController::class, 'show'])
        ->name('files.show');
    Route::get('/files/{file:public_id}/versions', [FileController::class, 'versions'])
        ->name('files.versions.index');
    Route::get('/files/{file:public_id}/activities', [FileController::class, 'activities'])
        ->name('files.activities');
    Route::post('/files/{file:public_id}/versions/{versionNo}/restore', [FileController::class, 'restoreVersion'])
        ->name('files.versions.restore');
    Route::get('/files/{file:public_id}/download', [FileController::class, 'download'])
        ->middleware('throttle:download')
        ->name('files.download');
    Route::get('/files/{file:public_id}/preview', [FileController::class, 'preview'])
        ->middleware('throttle:download')
        ->name('files.preview');
    Route::delete('/files/{file:public_id}', [FileController::class, 'destroy'])
        ->middleware('idempotency:delete-file')
        ->name('files.destroy');
    Route::delete('/files/{file:public_id}/purge', [FileController::class, 'purge'])
        ->middleware('idempotency:purge-file')
        ->name('files.purge');
    Route::post('/files/{file:public_id}/restore', [FileController::class, 'restore'])
        ->middleware('idempotency:restore-file')
        ->name('files.restore');

    Route::post('/files/{file:public_id}/share/users', [ShareController::class, 'shareUsers'])
        ->name('files.share.users');
    Route::get('/files/{file:public_id}/share/available-employees', [ShareController::class, 'availableEmployees'])
        ->name('files.share.available-employees');
    Route::post('/files/{file:public_id}/share/department', [ShareController::class, 'shareDepartment'])
        ->name('files.share.department');
    Route::get('/files/{file:public_id}/share/users', [ShareController::class, 'listUserShares'])
        ->name('files.share.users.index');
    Route::patch('/files/{file:public_id}/share/users/{targetUser:public_id}', [ShareController::class, 'updateUserShare'])
        ->withoutScopedBindings()
        ->name('files.share.users.update');
    Route::delete('/files/{file:public_id}/share/users/{targetUser:public_id}', [ShareController::class, 'revokeUserShare'])
        ->withoutScopedBindings()
        ->name('files.share.users.revoke');
    Route::delete('/files/{file:public_id}/share/me', [ShareController::class, 'revokeOwnFileShare'])
        ->name('files.share.me.revoke');
    Route::delete('/folders/{folder:public_id}/share/me', [ShareController::class, 'revokeOwnFolderShare'])
        ->name('folders.share.me.revoke');
    Route::post('/files/{file:public_id}/share/link', [ShareController::class, 'createLink'])
        ->middleware('idempotency:create-share-link')
        ->name('files.share.link.store');
    Route::post('/files/{file:public_id}/share-links', [ShareController::class, 'createLink'])
        ->middleware('idempotency:create-share-link')
        ->name('files.share.links.store');
    Route::get('/files/{file:public_id}/share/links', [ShareController::class, 'listLinks'])
        ->name('files.share.links.index');
    Route::get('/files/{file:public_id}/share-links', [ShareController::class, 'listLinks'])
        ->name('files.share-links.index');
    Route::patch('/share-links/{shareLink:public_id}', [ShareController::class, 'updateLink'])
        ->name('files.share.links.update');
    Route::post('/share-links/{shareLink:public_id}/revoke', [ShareController::class, 'revokeLink'])
        ->middleware('idempotency:revoke-share-link')
        ->name('files.share.links.revoke');

    Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
    Route::get('/trash/folders/{folder:public_id}', [TrashController::class, 'showFolder'])
        ->name('trash.folders.show');

    Route::prefix('/admin')->name('admin.')->group(function (): void {
        Route::get('/approvals', [PendingApprovalController::class, 'index'])
            ->middleware('permission:users.approve')
            ->name('approvals.index');
        Route::post('/approvals/{user:public_id}/approve', [PendingApprovalController::class, 'approve'])
            ->middleware(['permission:users.approve', 'idempotency:approve-user'])
            ->name('approvals.approve');
        Route::post('/approvals/{user:public_id}/reject', [PendingApprovalController::class, 'reject'])
            ->middleware(['permission:users.reject', 'idempotency:reject-user'])
            ->name('approvals.reject');

        Route::get('/employees', [EmployeeController::class, 'index'])
            ->middleware('permission:employees.view')
            ->name('employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])
            ->middleware(['permission:employees.manage', 'idempotency:create-employee'])
            ->name('employees.store');
        Route::patch('/employees/{employee:public_id}', [EmployeeController::class, 'update'])
            ->middleware('permission:employees.manage')
            ->name('employees.update');
        Route::post('/employees/{employee:public_id}/registration-link', [EmployeeController::class, 'sendRegistrationLink'])
            ->middleware('permission:registration_codes.manage')
            ->name('employees.registration-link.send');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view')
            ->name('audit.index');
    });
});

Route::get('/s/{token}', [ShareLinkAccessController::class, 'show'])
    ->middleware('throttle:share-link')
    ->name('public.share.show');
Route::post('/s/{token}/access', [ShareLinkAccessController::class, 'access'])
    ->middleware('throttle:share-link')
    ->name('public.share.access');
Route::get('/s/{token}/download', [ShareLinkAccessController::class, 'download'])
    ->middleware('throttle:share-download')
    ->name('public.share.download');

require __DIR__.'/settings.php';
