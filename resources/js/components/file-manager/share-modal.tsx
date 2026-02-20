import { router } from '@inertiajs/react';
import { Users, X } from 'lucide-react';
import {
    type FormEvent,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { FileRow, FolderRow } from '@/components/file-manager/file-table';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type ShareTarget =
    | { kind: 'file'; file: FileRow }
    | { kind: 'folder'; folder: FolderRow }
    | { kind: 'bulk-files'; files: FileRow[] };

type ShareModalProps = {
    target: ShareTarget;
    onOpenChange: (open: boolean) => void;
};

type AvailableEmployee = {
    id: number;
    public_id: string;
    email: string;
    name: string;
    department: {
        id: number;
        name: string;
        code: string;
    } | null;
};

type EmployeeResponse = {
    data: AvailableEmployee[];
};

type SharePermissions = {
    can_view: boolean;
    can_download: boolean;
    can_upload: boolean;
    can_edit: boolean;
    can_delete: boolean;
};

type PermissionTargetKind = 'file' | 'folder';

function firstErrorMessage(errors: Record<string, string | string[]>): string {
    for (const value of Object.values(errors)) {
        if (Array.isArray(value) && value.length > 0) {
            return String(value[0]);
        }
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }
    }

    return 'Unable to complete sharing action.';
}

function defaultPermissionsForTarget(): SharePermissions {
    return {
        can_view: true,
        can_download: false,
        can_upload: false,
        can_edit: false,
        can_delete: false,
    };
}

function displayEmployeeLabel(employee: AvailableEmployee): string {
    const name = employee.name.trim();
    const email = employee.email.trim();

    return name !== '' ? name : email;
}

function toInitials(value: string): string {
    const parts = value
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2);

    if (!parts.length) {
        return '?';
    }

    return parts.map((part) => part.charAt(0).toUpperCase()).join('');
}

function permissionsPayloadForTarget(
    targetKind: PermissionTargetKind,
    permissions: SharePermissions,
): Record<string, boolean> {
    if (targetKind === 'file') {
        return {
            can_view: permissions.can_view,
            can_download: false,
            can_edit: permissions.can_edit,
            can_delete: false,
        };
    }

    return {
        can_view: permissions.can_view,
        can_upload: permissions.can_edit,
        can_edit: permissions.can_edit,
        can_delete: false,
    };
}

function PermissionsFields({
    targetKind,
    permissions,
    onPermissionsChange,
    disabled = false,
}: {
    targetKind: PermissionTargetKind;
    permissions: SharePermissions;
    onPermissionsChange: (updater: (current: SharePermissions) => SharePermissions) => void;
    disabled?: boolean;
}) {
    return (
        <div className="space-y-2">
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked disabled />
                View (required)
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={permissions.can_edit}
                    disabled={disabled}
                    onChange={(event) =>
                        onPermissionsChange((current) => ({
                            ...current,
                            can_edit: event.target.checked,
                        }))
                    }
                />
                {targetKind === 'folder' ? 'Edit (includes upload)' : 'Edit'}
            </label>
        </div>
    );
}

export function ShareModal({ target, onOpenChange }: ShareModalProps) {
    const [open, setOpen] = useState(true);
    const [employeeQuery, setEmployeeQuery] = useState('');
    const [availableEmployees, setAvailableEmployees] = useState<
        AvailableEmployee[]
    >([]);
    const [employeesLoading, setEmployeesLoading] = useState(false);
    const [employeesError, setEmployeesError] = useState<string | null>(null);
    const [selectedEmployees, setSelectedEmployees] = useState<
        AvailableEmployee[]
    >([]);
    const [isEmployeePickerOpen, setIsEmployeePickerOpen] = useState(false);
    const employeePickerRef = useRef<HTMLDivElement | null>(null);
    const employeeInputRef = useRef<HTMLInputElement | null>(null);
    const permissionTargetKind: PermissionTargetKind =
        target.kind === 'folder' ? 'folder' : 'file';
    const supportsDepartmentShare = target.kind !== 'bulk-files';
    const [employeePermissions, setEmployeePermissions] =
        useState<SharePermissions>(() =>
            defaultPermissionsForTarget(),
        );
    const [departmentPermissions, setDepartmentPermissions] =
        useState<SharePermissions>(() =>
            defaultPermissionsForTarget(),
        );
    const [shareWithDepartment, setShareWithDepartment] = useState(false);
    const [shareError, setShareError] = useState<string | null>(null);
    const [isShareSubmitting, setIsShareSubmitting] = useState(false);

    const targetPublicId =
        target.kind === 'file'
            ? target.file.public_id
            : target.kind === 'folder'
              ? target.folder.public_id
              : (target.files[0]?.public_id ?? '');
    const targetLabel =
        target.kind === 'file'
            ? target.file.original_name
            : target.kind === 'folder'
              ? target.folder.name
              : `${target.files.length} selected file(s)`;
    const employeesEndpoint =
        target.kind === 'file'
            ? `/files/${targetPublicId}/share/available-employees`
            : target.kind === 'folder'
              ? `/folders/${targetPublicId}/share/available-employees`
              : '/selection/share/available-employees';
    const shareUsersEndpoint =
        target.kind === 'file'
            ? `/files/${targetPublicId}/share/users`
            : target.kind === 'folder'
              ? `/folders/${targetPublicId}/share/users`
              : '/selection/share/users';
    const shareDepartmentEndpoint =
        target.kind === 'file'
            ? `/files/${targetPublicId}/share/department`
            : `/folders/${targetPublicId}/share/department`;
    const isDepartmentVisibleByScope =
        target.kind === 'file'
            ? target.file.visibility === 'department'
            : target.kind === 'folder'
              ? target.folder.visibility === 'department'
              : false;

    const filteredEmployees = useMemo(() => {
        const needle = employeeQuery.trim().toLowerCase();
        if (needle === '') {
            return availableEmployees;
        }

        return availableEmployees.filter((employee) =>
            [employee.name, employee.email, employee.department?.name]
                .filter(Boolean)
                .some((value) => String(value).toLowerCase().includes(needle)),
        );
    }, [availableEmployees, employeeQuery]);

    const selectedEmployeeIds = useMemo(
        () => selectedEmployees.map((employee) => employee.id),
        [selectedEmployees],
    );
    const selectableEmployees = useMemo(
        () =>
            filteredEmployees.filter(
                (employee) => !selectedEmployeeIds.includes(employee.id),
            ),
        [filteredEmployees, selectedEmployeeIds],
    );
    const hasSelectedEmployees = selectedEmployees.length > 0;
    const canSubmitShares =
        hasSelectedEmployees ||
        (supportsDepartmentShare && shareWithDepartment);

    const loadAvailableEmployees = useCallback(async () => {
        setEmployeesLoading(true);
        setEmployeesError(null);

        try {
            const response = await fetch(employeesEndpoint, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error(`Failed to load employees: ${response.status}`);
            }

            const payload = (await response.json()) as EmployeeResponse;
            setAvailableEmployees(payload.data ?? []);
        } catch {
            setEmployeesError('Unable to load employees right now.');
        } finally {
            setEmployeesLoading(false);
        }
    }, [employeesEndpoint]);

    useEffect(() => {
        if (!open) {
            return;
        }

        void loadAvailableEmployees();
    }, [loadAvailableEmployees, open]);

    useEffect(() => {
        if (!open || !isEmployeePickerOpen) {
            return;
        }

        const closePickerOnOutsideClick = (event: MouseEvent) => {
            if (
                employeePickerRef.current &&
                !employeePickerRef.current.contains(event.target as Node)
            ) {
                setIsEmployeePickerOpen(false);
            }
        };

        document.addEventListener('mousedown', closePickerOnOutsideClick);

        return () => {
            document.removeEventListener(
                'mousedown',
                closePickerOnOutsideClick,
            );
        };
    }, [isEmployeePickerOpen, open]);

    useEffect(() => {
        setSelectedEmployees([]);
        setEmployeeQuery('');
        setIsEmployeePickerOpen(false);
        setEmployeePermissions(defaultPermissionsForTarget());
        setDepartmentPermissions(
            defaultPermissionsForTarget(),
        );
        setShareWithDepartment(
            supportsDepartmentShare && isDepartmentVisibleByScope,
        );
        setShareError(null);
    }, [
        isDepartmentVisibleByScope,
        permissionTargetKind,
        supportsDepartmentShare,
        target.kind,
        targetLabel,
        targetPublicId,
    ]);

    const updateOpen = (nextOpen: boolean) => {
        setOpen(nextOpen);
        onOpenChange(nextOpen);
    };

    const submitShares = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setShareError(null);

        if (!canSubmitShares) {
            setShareError(
                supportsDepartmentShare
                    ? 'Select at least one employee or enable department sharing.'
                    : 'Select at least one employee.',
            );

            return;
        }

        if (hasSelectedEmployees && !employeePermissions.can_view) {
            setShareError('Employee share requires view permission.');

            return;
        }

        if (
            supportsDepartmentShare &&
            shareWithDepartment &&
            !departmentPermissions.can_view
        ) {
            setShareError('Department share requires view permission.');

            return;
        }

        const postShareRequest = (
            endpoint: string,
            payload: Parameters<typeof router.post>[1],
        ) =>
            new Promise<void>((resolve, reject) => {
                router.post(endpoint, payload, {
                    headers: {
                        'X-Idempotency-Key': crypto.randomUUID(),
                    },
                    preserveScroll: true,
                    onSuccess: () => resolve(),
                    onError: (errors) => {
                        reject(
                            new Error(
                                firstErrorMessage(
                                    errors as Record<string, string | string[]>,
                                ),
                            ),
                        );
                    },
                });
            });

        setIsShareSubmitting(true);

        try {
            if (hasSelectedEmployees) {
                const sharesPayload = selectedEmployeeIds.map((userId) => ({
                    user_id: userId,
                    ...permissionsPayloadForTarget(
                        permissionTargetKind,
                        employeePermissions,
                    ),
                }));

                await postShareRequest(shareUsersEndpoint, {
                    ...(target.kind === 'bulk-files'
                        ? {
                              files: target.files.map(
                                  (file) => file.public_id,
                              ),
                          }
                        : {}),
                    shares: sharesPayload,
                });
            }

            if (supportsDepartmentShare && shareWithDepartment) {
                await postShareRequest(
                    shareDepartmentEndpoint,
                    permissionsPayloadForTarget(
                        permissionTargetKind,
                        departmentPermissions,
                    ),
                );
            }

            setSelectedEmployees([]);
            setEmployeeQuery('');
            setShareError(null);
            updateOpen(false);
        } catch (error) {
            setShareError(
                error instanceof Error
                    ? error.message
                    : 'Unable to complete sharing action.',
            );
        } finally {
            setIsShareSubmitting(false);
        }
    };

    const selectEmployee = (employee: AvailableEmployee) => {
        setSelectedEmployees((current) => {
            if (current.some((selected) => selected.id === employee.id)) {
                return current;
            }

            return [...current, employee];
        });
        setEmployeeQuery('');
        setIsEmployeePickerOpen(true);
        setShareError(null);
        employeeInputRef.current?.focus();
    };

    const removeSelectedEmployee = (employeeId: number) => {
        setSelectedEmployees((current) =>
            current.filter((employee) => employee.id !== employeeId),
        );
    };

    return (
        <Dialog open={open} onOpenChange={updateOpen}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {target.kind === 'bulk-files'
                            ? 'Share files'
                            : `Share ${target.kind === 'file' ? 'file' : 'folder'}`}
                    </DialogTitle>
                    <DialogDescription className="truncate">
                        {targetLabel}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submitShares} className="space-y-4">
                    <section className="space-y-3 rounded-lg border border-border/70 p-4">
                        <div className="flex items-center justify-between gap-2">
                            <div className="inline-flex items-center gap-2 text-sm font-medium">
                                <Users className="size-4" />
                                Share with employees
                            </div>
                            <div className="flex items-center gap-2">
                                {selectedEmployees.length ? (
                                    <Badge variant="neutral">
                                        {selectedEmployees.length} selected
                                    </Badge>
                                ) : null}
                                {selectedEmployees.length ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setSelectedEmployees([])
                                        }
                                    >
                                        Clear
                                    </Button>
                                ) : null}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => void loadAvailableEmployees()}
                                    disabled={employeesLoading}
                                >
                                    Refresh
                                </Button>
                            </div>
                        </div>

                        <div ref={employeePickerRef} className="relative">
                            <div
                                className="border-input focus-within:border-ring focus-within:ring-ring/50 flex min-h-10 w-full flex-wrap items-center gap-2 rounded-md border bg-card px-2 py-1 text-sm focus-within:ring-[3px]"
                                onClick={() => {
                                    setIsEmployeePickerOpen(true);
                                    employeeInputRef.current?.focus();
                                }}
                            >
                                {selectedEmployees.map((employee) => (
                                    <Badge
                                        key={employee.public_id}
                                        variant="outline"
                                        className="flex h-7 max-w-full items-center gap-1 rounded-full border-border/70 bg-muted/40 px-1.5 text-xs text-foreground"
                                    >
                                        <Avatar className="size-4">
                                            <AvatarFallback className="bg-muted text-[9px] font-semibold text-muted-foreground">
                                                {toInitials(
                                                    displayEmployeeLabel(
                                                        employee,
                                                    ),
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="max-w-[12rem] truncate">
                                            {displayEmployeeLabel(employee)}
                                        </span>
                                        <button
                                            type="button"
                                            className="rounded-full p-0.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                removeSelectedEmployee(
                                                    employee.id,
                                                );
                                            }}
                                            aria-label={`Remove ${displayEmployeeLabel(employee)}`}
                                        >
                                            <X className="size-3" />
                                        </button>
                                    </Badge>
                                ))}
                                <input
                                    ref={employeeInputRef}
                                    value={employeeQuery}
                                    onChange={(event) =>
                                        setEmployeeQuery(event.target.value)
                                    }
                                    onFocus={() => setIsEmployeePickerOpen(true)}
                                    onClick={() => setIsEmployeePickerOpen(true)}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Backspace' &&
                                            employeeQuery === '' &&
                                            selectedEmployees.length > 0
                                        ) {
                                            removeSelectedEmployee(
                                                selectedEmployees[
                                                    selectedEmployees.length - 1
                                                ].id,
                                            );
                                        }

                                        if (event.key === 'Escape') {
                                            setIsEmployeePickerOpen(false);
                                        }
                                    }}
                                    placeholder={
                                        selectedEmployees.length === 0
                                            ? 'Search employee name or email'
                                            : 'Add more employees'
                                    }
                                    className="placeholder:text-muted-foreground h-7 min-w-40 flex-1 border-0 bg-transparent px-1 py-0 outline-none"
                                />
                            </div>
                            {isEmployeePickerOpen ? (
                                <div className="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-md border border-border/70 bg-card p-1 shadow-soft-sm">
                                    {employeesLoading ? (
                                        <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                            Loading employees...
                                        </p>
                                    ) : selectableEmployees.length === 0 ? (
                                        <p className="px-2 py-1.5 text-sm text-muted-foreground">
                                            {availableEmployees.length > 0 &&
                                            selectedEmployees.length ===
                                                availableEmployees.length
                                                ? 'All employees selected.'
                                                : 'No employees found.'}
                                        </p>
                                    ) : (
                                        selectableEmployees.map((employee) => (
                                            <button
                                                key={employee.public_id}
                                                type="button"
                                                className="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/40"
                                                onClick={() =>
                                                    selectEmployee(employee)
                                                }
                                            >
                                                <Avatar className="mt-0.5 size-6">
                                                    <AvatarFallback className="bg-muted text-[10px] font-semibold text-muted-foreground">
                                                        {toInitials(
                                                            displayEmployeeLabel(
                                                                employee,
                                                            ),
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <span className="text-sm">
                                                    <span className="block font-medium">
                                                        {displayEmployeeLabel(
                                                            employee,
                                                        )}
                                                    </span>
                                                    <span className="block text-muted-foreground">
                                                        {employee.email}
                                                    </span>
                                                    {employee.department ? (
                                                        <span className="block text-xs text-muted-foreground">
                                                            {
                                                                employee
                                                                    .department
                                                                    .name
                                                            }
                                                        </span>
                                                    ) : null}
                                                </span>
                                            </button>
                                        ))
                                    )}
                                </div>
                            ) : null}
                        </div>

                        {employeesError ? (
                            <p className="text-sm text-destructive">
                                {employeesError}
                            </p>
                        ) : null}
                    </section>

                    <div className="grid gap-3 lg:grid-cols-2">
                        {hasSelectedEmployees ? (
                            <section className="space-y-2 rounded-md border border-border/60 p-3">
                                <p className="text-sm font-medium">
                                    Employee permissions
                                </p>
                                <PermissionsFields
                                    targetKind={permissionTargetKind}
                                    permissions={employeePermissions}
                                    onPermissionsChange={setEmployeePermissions}
                                    disabled={isShareSubmitting}
                                />
                            </section>
                        ) : null}

                        {supportsDepartmentShare ? (
                            <section className="space-y-2 rounded-md border border-border/60 p-3">
                                <p className="text-sm font-medium">
                                    Share with department
                                </p>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={shareWithDepartment}
                                        disabled={isShareSubmitting}
                                        onChange={(event) =>
                                            setShareWithDepartment(
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    {isDepartmentVisibleByScope
                                        ? 'Update department permissions'
                                        : 'Also share with my department'}
                                </label>
                                {shareWithDepartment ? (
                                    <PermissionsFields
                                        targetKind={permissionTargetKind}
                                        permissions={departmentPermissions}
                                        onPermissionsChange={
                                            setDepartmentPermissions
                                        }
                                        disabled={isShareSubmitting}
                                    />
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        {target.kind === 'file'
                                            ? 'Include department users and define what they can do with this file.'
                                            : 'Include department users and define what they can do in this folder.'}
                                    </p>
                                )}
                            </section>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Button
                            type="submit"
                            disabled={isShareSubmitting || !canSubmitShares}
                        >
                            {isShareSubmitting ? <Spinner /> : null}
                            Share
                        </Button>
                        {shareError ? (
                            <p className="text-sm text-destructive">
                                {shareError}
                            </p>
                        ) : null}
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
