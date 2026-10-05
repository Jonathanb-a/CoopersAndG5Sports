import { AuthCard } from '@/components/store/auth-card';
import { StoreButton } from '@/components/store/button';
import { Field } from '@/components/store/form';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

interface ResetPasswordProps {
    token: string;
    email: string | null;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { store } = usePage<SharedData>().props;
    const form = useForm({ token, email: email ?? '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <StoreLayout>
            <Head title={`Contraseña nueva · ${store.name}`} />

            <AuthCard title="Contraseña nueva" description="Elige la contraseña con la que vas a entrar a tu cuenta.">
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Field
                        label="Correo electrónico"
                        type="email"
                        name="email"
                        autoComplete="email"
                        readOnly
                        value={form.data.email}
                        error={form.errors.email}
                    />
                    <Field
                        label="Contraseña nueva"
                        type="password"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        hint="Mínimo 8 caracteres."
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                    />
                    <Field
                        label="Confirmar contraseña"
                        type="password"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        error={form.errors.password_confirmation}
                    />

                    <StoreButton type="submit" size="lg" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Guardando…' : 'Guardar contraseña'}
                    </StoreButton>
                </form>
            </AuthCard>
        </StoreLayout>
    );
}
