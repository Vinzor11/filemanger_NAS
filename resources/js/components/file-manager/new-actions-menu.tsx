import { FileUp, FolderPlus, FolderUp, Plus } from 'lucide-react';
import { useState } from 'react';
import { NewFolderModal } from '@/components/file-manager/new-folder-modal';
import { UploadModal } from '@/components/file-manager/upload-modal';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type FolderScope = 'private' | 'department';
type UploadFolderOption = {
    public_id: string;
    name: string;
    path?: string | null;
};

type NewActionsMenuProps = {
    folderPublicId?: string | null;
    parentFolderId?: string | null;
    uploadFolderOptions?: UploadFolderOption[];
    defaultScope?: FolderScope;
    showScope?: boolean;
    canCreateFolder?: boolean;
    canUpload?: boolean;
    onUploadProcessingChange?: (isProcessing: boolean) => void;
};

export function NewActionsMenu({
    folderPublicId = null,
    parentFolderId = null,
    uploadFolderOptions = [],
    defaultScope = 'private',
    showScope = true,
    canCreateFolder = true,
    canUpload = true,
    onUploadProcessingChange,
}: NewActionsMenuProps) {
    const [isNewFolderOpen, setIsNewFolderOpen] = useState(false);
    const [isUploadFilesOpen, setIsUploadFilesOpen] = useState(false);
    const [isUploadFolderOpen, setIsUploadFolderOpen] = useState(false);

    const canUploadToTarget =
        canUpload &&
        (Boolean(folderPublicId) || uploadFolderOptions.length > 0);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button">
                        <Plus />
                        New
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    {canCreateFolder ? (
                        <DropdownMenuItem
                            onSelect={() => setIsNewFolderOpen(true)}
                        >
                            <FolderPlus />
                            New folder
                        </DropdownMenuItem>
                    ) : null}
                    {canCreateFolder ? <DropdownMenuSeparator /> : null}
                    <DropdownMenuItem
                        disabled={!canUploadToTarget}
                        onSelect={() => {
                            if (canUploadToTarget) {
                                setIsUploadFilesOpen(true);
                            }
                        }}
                    >
                        <FileUp />
                        Upload files
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!canUploadToTarget}
                        onSelect={() => {
                            if (canUploadToTarget) {
                                setIsUploadFolderOpen(true);
                            }
                        }}
                    >
                        <FolderUp />
                        Upload folder
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {canCreateFolder ? (
                <NewFolderModal
                    parentFolderId={parentFolderId}
                    defaultScope={defaultScope}
                    showScope={showScope}
                    hideTrigger
                    open={isNewFolderOpen}
                    onOpenChange={setIsNewFolderOpen}
                />
            ) : null}

            {canUploadToTarget ? (
                <UploadModal
                    folderPublicId={folderPublicId}
                    folderOptions={uploadFolderOptions}
                    selectionMode="files"
                    hideTrigger
                    open={isUploadFilesOpen}
                    onOpenChange={setIsUploadFilesOpen}
                    onProcessingChange={onUploadProcessingChange}
                />
            ) : null}

            {canUploadToTarget ? (
                <UploadModal
                    folderPublicId={folderPublicId}
                    folderOptions={uploadFolderOptions}
                    selectionMode="folder"
                    hideTrigger
                    open={isUploadFolderOpen}
                    onOpenChange={setIsUploadFolderOpen}
                    onProcessingChange={onUploadProcessingChange}
                />
            ) : null}
        </>
    );
}
