import { AuthCard } from '@/components/store/auth-card';
import { StoreButton } from '@/components/store/button';
import { Field } from '@/components/store/form';
import { GoogleButton } from '@/components/store/google-button';
import { LegalConsent } from '@/components/store/legal-consent';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

export default function Register() {
    const { store } = usePage<SharedData>().props;
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <StoreLayout>
            <Head title={`Crear cuenta · ${store.name}`} />

            <AuthCard
                title="Crear cuenta"
                description="Con una sola cuenta compras en Copper's y en Sports G5."
                footer={
                    <>
                        ¿Ya tienes cuenta?{' '}
                        <Link href="/login" className="text-store-link font-semibold underline underline-offset-4">
                            Ingresar
                        </Link>
                    </>
                }
            >
                <GoogleButton />

                <form onSubmit={submit} className="space-y-4" noValidate>
                    <Field
                        label="Nombre"
                        name="name"
                        autoComplete="name"
                        autoFocus
                        required
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                    />
                    <Field
                        label="Correo electrónico"
                        type="email"
                        name="email"
                        autoComplete="email"
                        inputMode="email"
                        required
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />
                    <Field
                        label="Contraseña"
                        type="password"
                        name="password"
                        autoComplete="new-password"
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

                    <LegalConsent />
                    <StoreButton type="submit" size="lg" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Creando cuenta…' : 'Crear cuenta'}
                    </StoreButton>
                </form>
            </AuthCard>
        </StoreLayout>
    );
}
