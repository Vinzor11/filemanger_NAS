import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BreadcrumbItem } from '@/types';

type DiskOption = {
    value: string;
    label: string;
};

type Props = {
    currentDisk: string;
    availableDisks: DiskOption[];
    status?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Storage', href: '/settings/storage' },
];

export default function StorageSettings({
    currentDisk,
    availableDisks,
    status,
}: Props) {
    const { data, setData, put, processing, errors, recentlySuccessful } =
        useForm({
            storage_disk: currentDisk,
        });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        put('/settings/storage', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Storage settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="File storage"
                        description="Choose where new uploads are stored. Existing files remain on their original disk."
                    />

                    <div className="rounded-lg border border-border bg-card p-4 shadow-soft-sm">
                        <div className="mb-3 flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground">
                                Active disk:
                            </span>
                            <Badge variant="info">
                                {currentDisk.toUpperCase()}
                            </Badge>
                        </div>

                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="storage_disk">
                                    Upload destination
                                </Label>
                                <Select
                                    value={data.storage_disk}
                                    onValueChange={(value) =>
                                        setData('storage_disk', value)
                                    }
                                >
                                    <SelectTrigger id="storage_disk">
                                        <SelectValue placeholder="Select a disk" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableDisks.map((disk) => (
                                            <SelectItem
                                                key={disk.value}
                                                value={disk.value}
                                            >
                                                {disk.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.storage_disk} />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit" disabled={processing}>
                                    Save storage setting
                                </Button>
                                {(recentlySuccessful || status) && (
                                    <p className="text-sm text-muted-foreground">
                                        {status ?? 'Saved'}
                                    </p>
                                )}
                            </div>
                        </form>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        Use <strong>LOCAL</strong> for on-computer testing.
                        Switch to <strong>NAS</strong> when you are ready to
                        store new uploads on your NAS path.
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
