import { AuthCard } from '@/components/store/auth-card';
import { StoreButton } from '@/components/store/button';
import { Field, FormStatus } from '@/components/store/form';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { store } = usePage<SharedData>().props;
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <StoreLayout>
            <Head title={`Recuperar contraseña · ${store.name}`} />

            <AuthCard
                title="Recuperar contraseña"
                description="Escribe tu correo y te mandamos un enlace para crear una contraseña nueva."
                footer={
                    <Link href="/login" className="text-store-link font-semibold underline underline-offset-4">
                        Volver a ingresar
                    </Link>
                }
            >
                <FormStatus className="mb-5">{status}</FormStatus>

                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Field
                        label="Correo electrónico"
                        type="email"
                        name="email"
                        autoComplete="email"
                        inputMode="email"
                        autoFocus
                        required
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />

                    <StoreButton type="submit" size="lg" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Enviando…' : 'Enviar enlace'}
                    </StoreButton>
                </form>
            </AuthCard>
        </StoreLayout>
    );
}
