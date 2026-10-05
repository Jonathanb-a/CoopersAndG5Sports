import { AuthCard } from '@/components/store/auth-card';
import { StoreButton } from '@/components/store/button';
import { Field, FormStatus } from '@/components/store/form';
import { GoogleButton } from '@/components/store/google-button';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';

export default function Login({ status }: { status?: string }) {
    const { store, errors: pageErrors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    // Los errores de Google llegan en la página (no los produjo este formulario).
    const emailError = form.errors.email ?? pageErrors?.email;

    return (
        <StoreLayout>
            <Head title={`Ingresar · ${store.name}`} />

            <AuthCard
                title="Ingresar"
                description={`Entra a tu cuenta para comprar en ${store.name}. Es la misma cuenta en todas nuestras tiendas.`}
                footer={
                    <>
                        ¿Aún no tienes cuenta?{' '}
                        <Link href="/register" className="text-store-link font-semibold underline underline-offset-4">
                            Crear cuenta
                        </Link>
                    </>
                }
            >
                <FormStatus className="mb-5">{status}</FormStatus>
                <GoogleButton />

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
                        error={emailError}
                    />
                    <Field
                        label="Contraseña"
                        type="password"
                        name="password"
                        autoComplete="current-password"
                        required
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        error={form.errors.password}
                    />

                    <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                        <label className="flex min-h-11 cursor-pointer items-center gap-3">
                            <input
                                type="checkbox"
                                name="remember"
                                checked={form.data.remember}
                                onChange={(event) => form.setData('remember', event.target.checked)}
                                className="accent-store-action size-5 shrink-0"
                            />
                            Mantener sesión iniciada
                        </label>
                        <Link href="/forgot-password" className="text-store-link font-medium underline underline-offset-4">
                            ¿Olvidaste tu contraseña?
                        </Link>
                    </div>

                    <StoreButton type="submit" size="lg" className="w-full" disabled={form.processing}>
                        {form.processing ? 'Ingresando…' : 'Ingresar'}
                    </StoreButton>
                </form>
            </AuthCard>
        </StoreLayout>
    );
}
