import { useForm } from '@inertiajs/react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type FolderScope = 'private' | 'department';

type NewFolderModalProps = {
    parentFolderId?: string | null;
    defaultScope?: FolderScope;
    showScope?: boolean;
    buttonLabel?: string;
    trigger?: ReactNode;
    hideTrigger?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

export function NewFolderModal({
    parentFolderId = null,
    defaultScope = 'private',
    showScope = true,
    buttonLabel = 'New Folder',
    trigger,
    hideTrigger = false,
    open,
    onOpenChange,
}: NewFolderModalProps) {
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = typeof open === 'boolean';
    const dialogOpen = isControlled ? open : internalOpen;
    const setDialogOpen = useMemo(
        () => onOpenChange ?? setInternalOpen,
        [onOpenChange],
    );
    const form = useForm({
        name: '',
        scope: defaultScope,
        parent_id: parentFolderId ?? '',
    });
    const setData = form.setData;

    useEffect(() => {
        setData('parent_id', parentFolderId ?? '');
    }, [parentFolderId, setData]);

    useEffect(() => {
        setData('scope', defaultScope);
    }, [defaultScope, setData]);

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post('/folders', {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                form.reset('name');
            },
        });
    };

    return (
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
            {hideTrigger ? null : (
                <DialogTrigger asChild>
                    {trigger ?? (
                        <Button type="button" variant="outline">
                            {buttonLabel}
                        </Button>
                    )}
                </DialogTrigger>
            )}
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create folder</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="folder_name">Folder name</Label>
                        <Input
                            id="folder_name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={255}
                            placeholder="Project docs"
                            required
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    {showScope ? (
                        <div className="space-y-2">
                            <Label htmlFor="folder_scope">Scope</Label>
                            <select
                                id="folder_scope"
                                className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                value={form.data.scope}
                                onChange={(event) =>
                                    form.setData(
                                        'scope',
                                        event.target.value as FolderScope,
                                    )
                                }
                            >
                                <option value="private">Private</option>
                                <option value="department">Department</option>
                            </select>
                            <InputError message={form.errors.scope} />
                        </div>
                    ) : null}

                    <InputError message={form.errors.parent_id} />

                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner className="size-4" />}
                        Create folder
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
