import { useState } from 'react';
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

type ReplaceOrRenameModalProps = {
    originalName: string;
    onReplace: () => void;
    onRename: (newName: string) => void;
};

export function ReplaceOrRenameModal({
    originalName,
    onReplace,
    onRename,
}: ReplaceOrRenameModalProps) {
    const [open, setOpen] = useState(false);
    const [newName, setNewName] = useState(originalName);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    Resolve duplicate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Duplicate file name</DialogTitle>
                </DialogHeader>
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        A file named{' '}
                        <span className="font-semibold text-foreground">
                            {originalName}
                        </span>{' '}
                        already exists.
                    </p>
                    <div className="space-y-2">
                        <Label htmlFor="new_name">Rename as</Label>
                        <Input
                            id="new_name"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                        />
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                onRename(newName);
                                setOpen(false);
                            }}
                        >
                            Rename upload
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                onReplace();
                                setOpen(false);
                            }}
                        >
                            Replace existing
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
