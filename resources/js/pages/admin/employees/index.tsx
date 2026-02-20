import { Head, router, useForm } from '@inertiajs/react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TableCardSkeleton } from '@/components/ui/page-loading-skeletons';
import { Spinner } from '@/components/ui/spinner';
import { usePageLoading } from '@/hooks/use-page-loading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Department = { id: number; name: string; code: string };
type Position = { id: number; name: string; department_id: number | null };
type EmployeeStatus = 'active' | 'inactive' | 'resigned';

type EmployeeRow = {
    public_id: string;
    employee_no: string;
    first_name: string;
    last_name: string;
    email: string | null;
    status: EmployeeStatus;
    department?: { name: string } | null;
    user?: { status: 'pending' | 'active' | 'rejected' | 'blocked' } | null;
};

type EmployeeCreateForm = {
    employee_no: string;
    department_id: number | '';
    position_id: number | '';
    position_title: string;
    first_name: string;
    middle_name: string;
    last_name: string;
    email: string;
    mobile: string;
    status: EmployeeStatus;
    hired_at: string;
};

type PageProps = {
    employees: { data: EmployeeRow[] };
    departments: Department[];
    positions: Position[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Employees', href: '/admin/employees' },
];

function getInitialFormData(departments: Department[]): EmployeeCreateForm {
    return {
        employee_no: '',
        department_id: departments[0]?.id ?? '',
        position_id: '',
        position_title: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        email: '',
        mobile: '',
        status: 'active',
        hired_at: '',
    };
}

export default function Employees({
    employees,
    departments,
    positions,
}: PageProps) {
    const isPageLoading = usePageLoading();
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [sendingLinkFor, setSendingLinkFor] = useState<string | null>(null);

    const form = useForm<EmployeeCreateForm>(getInitialFormData(departments));
    const todayDate = useMemo(() => new Date().toISOString().slice(0, 10), []);

    const selectedDepartmentId = useMemo(
        () =>
            typeof form.data.department_id === 'number'
                ? form.data.department_id
                : null,
        [form.data.department_id],
    );

    const availablePositions = useMemo(
        () =>
            positions.filter((position) => {
                if (position.department_id === null) {
                    return true;
                }

                return selectedDepartmentId === position.department_id;
            }),
        [positions, selectedDepartmentId],
    );

    useEffect(() => {
        if (typeof form.data.position_id !== 'number') {
            return;
        }

        const stillValid = availablePositions.some(
            (position) => position.id === form.data.position_id,
        );
        if (!stillValid) {
            form.setData('position_id', '');
        }
    }, [availablePositions, form, form.data.position_id]);

    const handleCreateEmployee = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post('/admin/employees', {
            preserveScroll: true,
            headers: {
                'X-Idempotency-Key': crypto.randomUUID(),
            },
            onSuccess: () => {
                setIsCreateModalOpen(false);
                form.clearErrors();
                form.reset();
            },
        });
    };

    const handleSendRegistrationLink = (employeePublicId: string) => {
        setSendingLinkFor(employeePublicId);

        router.post(
            `/admin/employees/${employeePublicId}/registration-link`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSendingLinkFor(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employees" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Employees</h1>
                    <Button
                        type="button"
                        onClick={() => setIsCreateModalOpen(true)}
                        disabled={departments.length === 0}
                    >
                        Create employee
                    </Button>
                </div>

                <Dialog
                    open={isCreateModalOpen}
                    onOpenChange={(open) => {
                        if (form.processing) {
                            return;
                        }

                        setIsCreateModalOpen(open);

                        if (!open) {
                            form.clearErrors();
                        }
                    }}
                >
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>Create employee</DialogTitle>
                            <DialogDescription>
                                Fill in employee details exactly as recorded by
                                HR. Employee number must be unique.
                            </DialogDescription>
                        </DialogHeader>

                        <form
                            className="grid gap-3 md:grid-cols-2"
                            onSubmit={handleCreateEmployee}
                        >
                            <div className="space-y-1">
                                <Label htmlFor="employee_no">
                                    Employee no.
                                </Label>
                                <Input
                                    id="employee_no"
                                    value={form.data.employee_no}
                                    onChange={(event) =>
                                        form.setData(
                                            'employee_no',
                                            event.target.value
                                                .toUpperCase()
                                                .replace(/\s+/g, ''),
                                        )
                                    }
                                    maxLength={30}
                                    placeholder="EMP-0001"
                                    required
                                />
                                <InputError message={form.errors.employee_no} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="department_id">
                                    Department
                                </Label>
                                <select
                                    id="department_id"
                                    className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                    value={form.data.department_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'department_id',
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value),
                                        )
                                    }
                                    required
                                >
                                    <option value="">Select department</option>
                                    {departments.map((department) => (
                                        <option
                                            key={department.id}
                                            value={department.id}
                                        >
                                            {department.name} ({department.code}
                                            )
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.department_id}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="position_id">
                                    Position (optional)
                                </Label>
                                <select
                                    id="position_id"
                                    className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                    value={form.data.position_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'position_id',
                                            event.target.value === ''
                                                ? ''
                                                : Number(event.target.value),
                                        )
                                    }
                                >
                                    <option value="">No linked position</option>
                                    {availablePositions.map((position) => (
                                        <option
                                            key={position.id}
                                            value={position.id}
                                        >
                                            {position.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.position_id} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="position_title">
                                    Position title (optional)
                                </Label>
                                <Input
                                    id="position_title"
                                    value={form.data.position_title}
                                    onChange={(event) =>
                                        form.setData(
                                            'position_title',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={120}
                                    placeholder="Assistant Manager"
                                    disabled={
                                        typeof form.data.position_id ===
                                        'number'
                                    }
                                />
                                <InputError
                                    message={form.errors.position_title}
                                />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="first_name">First name</Label>
                                <Input
                                    id="first_name"
                                    value={form.data.first_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'first_name',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={80}
                                    required
                                />
                                <InputError message={form.errors.first_name} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="middle_name">
                                    Middle name (optional)
                                </Label>
                                <Input
                                    id="middle_name"
                                    value={form.data.middle_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'middle_name',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={80}
                                />
                                <InputError message={form.errors.middle_name} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="last_name">Last name</Label>
                                <Input
                                    id="last_name"
                                    value={form.data.last_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'last_name',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={80}
                                    required
                                />
                                <InputError message={form.errors.last_name} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="email">Email (optional)</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={150}
                                    placeholder="employee@office.local"
                                />
                                <InputError message={form.errors.email} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="mobile">
                                    Mobile (optional)
                                </Label>
                                <Input
                                    id="mobile"
                                    value={form.data.mobile}
                                    onChange={(event) =>
                                        form.setData(
                                            'mobile',
                                            event.target.value.replace(
                                                /[^0-9+()\-\s]/g,
                                                '',
                                            ),
                                        )
                                    }
                                    maxLength={30}
                                    placeholder="+1 555 123 4567"
                                />
                                <InputError message={form.errors.mobile} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="status">Status</Label>
                                <select
                                    id="status"
                                    className="h-9 w-full rounded-md border bg-background px-2 text-sm"
                                    value={form.data.status}
                                    onChange={(event) =>
                                        form.setData(
                                            'status',
                                            event.target
                                                .value as EmployeeStatus,
                                        )
                                    }
                                    required
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="resigned">Resigned</option>
                                </select>
                                <InputError message={form.errors.status} />
                            </div>

                            <div className="space-y-1 md:col-span-2">
                                <Label htmlFor="hired_at">
                                    Hired date (optional)
                                </Label>
                                <Input
                                    id="hired_at"
                                    type="date"
                                    value={form.data.hired_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'hired_at',
                                            event.target.value,
                                        )
                                    }
                                    max={todayDate}
                                />
                                <InputError message={form.errors.hired_at} />
                            </div>

                            <DialogFooter className="md:col-span-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setIsCreateModalOpen(false)}
                                    disabled={form.processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        departments.length === 0
                                    }
                                >
                                    {form.processing ? (
                                        <>
                                            <Spinner className="size-4" />
                                            Saving...
                                        </>
                                    ) : (
                                        'Save employee'
                                    )}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>

                {isPageLoading ? (
                    <TableCardSkeleton columns={6} rows={8} />
                ) : (
                    <section className="overflow-x-auto rounded-lg border bg-card">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-3">Employee</th>
                                    <th className="px-4 py-3">Department</th>
                                    <th className="px-4 py-3">Email</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Account</th>
                                    <th className="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {employees.data.map((employee) => (
                                    <tr
                                        key={employee.public_id}
                                        className="border-t"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {employee.first_name}{' '}
                                                {employee.last_name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {employee.employee_no}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {employee.department?.name ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {employee.email ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {employee.status}
                                        </td>
                                        <td className="px-4 py-3">
                                            {employee.user?.status ??
                                                'not registered'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {employee.user?.status ===
                                            'active' ? (
                                                <span className="text-xs text-muted-foreground">
                                                    Already active
                                                </span>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        sendingLinkFor ===
                                                            employee.public_id ||
                                                        !employee.email
                                                    }
                                                    onClick={() =>
                                                        handleSendRegistrationLink(
                                                            employee.public_id,
                                                        )
                                                    }
                                                    title={
                                                        !employee.email
                                                            ? 'Employee email is required'
                                                            : undefined
                                                    }
                                                >
                                                    {sendingLinkFor ===
                                                    employee.public_id ? (
                                                        <>
                                                            <Spinner className="size-4" />
                                                            Sending...
                                                        </>
                                                    ) : (
                                                        'Send registration link'
                                                    )}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {!employees.data.length ? (
                                    <tr>
                                        <td
                                            className="px-4 py-6 text-center text-sm text-muted-foreground"
                                            colSpan={6}
                                        >
                                            No employees found.
                                        </td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
