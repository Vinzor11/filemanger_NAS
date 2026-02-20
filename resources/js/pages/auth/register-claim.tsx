import { Head, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

type RegisterClaimProps = {
    employee_no?: string | null;
    registration_code?: string | null;
};

export default function RegisterClaim({
    employee_no,
    registration_code,
}: RegisterClaimProps) {
    const employeeNo = employee_no ?? '';
    const registrationCode = registration_code ?? '';
    const hasRegistrationContext =
        employeeNo.length > 0 && registrationCode.length > 0;

    const form = useForm({
        employee_no: employeeNo,
        registration_code: registrationCode,
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!hasRegistrationContext) {
            return;
        }

        form.post('/register', {
            preserveScroll: true,
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Claim employee account"
            description="Use the registration link sent by your administrator"
        >
            <Head title="Claim Account" />

            <form onSubmit={submit} className="flex flex-col gap-6">
                <div className="grid gap-4">
                    <div className="rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                        {hasRegistrationContext ? (
                            <p>
                                Registering employee number{' '}
                                <span className="font-semibold text-foreground">
                                    {employeeNo}
                                </span>
                                .
                            </p>
                        ) : (
                            <p>
                                Use the registration link from your admin to
                                continue.
                            </p>
                        )}
                        <InputError
                            message={
                                form.errors.employee_no ||
                                form.errors.registration_code
                            }
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email (optional)</Label>
                        <Input
                            id="email"
                            name="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                            type="email"
                            autoFocus
                            placeholder="you@company.com"
                        />
                        <InputError message={form.errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            name="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            type="password"
                            required
                        />
                        <InputError message={form.errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Confirm password
                        </Label>
                        <Input
                            id="password_confirmation"
                            name="password_confirmation"
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                            type="password"
                            required
                        />
                        <InputError
                            message={form.errors.password_confirmation}
                        />
                    </div>
                </div>

                <Button
                    type="submit"
                    disabled={form.processing || !hasRegistrationContext}
                >
                    {form.processing && <Spinner />}
                    Submit for approval
                </Button>

                <div className="text-center text-sm text-muted-foreground">
                    Already have an account?{' '}
                    <TextLink href="/login">Log in</TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
