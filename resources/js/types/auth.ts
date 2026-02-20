export type User = {
    public_id: string;
    name?: string;
    email: string | null;
    avatar?: string;
    status: 'pending' | 'active' | 'rejected' | 'blocked';
    employee: {
        public_id: string;
        employee_no: string;
        first_name: string;
        last_name: string;
        department_id: number;
    } | null;
    roles: string[];
    permissions: string[];
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
