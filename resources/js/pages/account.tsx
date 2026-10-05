import { StoreButton } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { Field, FormStatus } from '@/components/store/form';
import { Reveal } from '@/components/store/reveal';
import StoreLayout from '@/Themes';
import { type CustomerAddress, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

interface AccountProps {
    customer: { name: string; email: string; phone: string | null; has_password: boolean; has_google: boolean };
    addresses: CustomerAddress[];
    status?: string;
}

export default function Account(props: AccountProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Mi cuenta · ${store.name}`} />
            <AccountContent {...props} />
        </StoreLayout>
    );
}

function Section({ title, description, children, id }: { title: string; description?: string; children: ReactNode; id: string }) {
    return (
        <section aria-labelledby={id} className="store-surface p-5 sm:p-7">
            <h2 id={id} className="store-heading text-2xl sm:text-3xl">
                {title}
            </h2>
            {description && <p className="text-store-muted mt-2 text-sm">{description}</p>}
            <div className="mt-5">{children}</div>
        </section>
    );
}

/** «Mi cuenta»: datos, contraseña y libreta de direcciones. */
function AccountContent({ customer, addresses, status }: AccountProps) {
    return (
        <Container className="max-w-3xl py-8 sm:py-12 lg:py-16">
            <Reveal>
                <h1 className="store-heading text-4xl sm:text-5xl lg:text-6xl">Mi cuenta</h1>
                <p className="text-store-muted mt-2">Tu cuenta es la misma en todas nuestras tiendas.</p>
            </Reveal>

            <div className="mt-8 space-y-6">
                <FormStatus>{status}</FormStatus>
                <ProfileForm customer={customer} />
                <PasswordForm hasPassword={customer.has_password} hasGoogle={customer.has_google} />
                <Addresses addresses={addresses} />
            </div>
        </Container>
    );
}

function ProfileForm({ customer }: { customer: AccountProps['customer'] }) {
    const form = useForm({ name: customer.name, phone: customer.phone ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch('/mi-cuenta', { preserveScroll: true });
    };

    return (
        <Section id="datos" title="Mis datos">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field
                    label="Nombre"
                    name="name"
                    autoComplete="name"
                    required
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={form.errors.name}
                />
                <Field label="Correo electrónico" type="email" name="email" readOnly value={customer.email} hint="El correo no se puede cambiar." />
                <Field
                    label="Teléfono"
                    type="tel"
                    name="phone"
                    autoComplete="tel"
                    inputMode="tel"
                    value={form.data.phone}
                    onChange={(event) => form.setData('phone', event.target.value)}
                    error={form.errors.phone}
                    hint="Para avisarte de tu pedido (opcional)."
                />
                <StoreButton type="submit" disabled={form.processing || !form.isDirty}>
                    {form.processing ? 'Guardando…' : 'Guardar datos'}
                </StoreButton>
            </form>
        </Section>
    );
}

function PasswordForm({ hasPassword, hasGoogle }: { hasPassword: boolean; hasGoogle: boolean }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/mi-cuenta/contrasena', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <Section
            id="contrasena"
            title={hasPassword ? 'Cambiar contraseña' : 'Crear contraseña'}
            description={
                !hasPassword && hasGoogle ? 'Entraste con Google. Si quieres, crea una contraseña para entrar también con tu correo.' : undefined
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                {hasPassword && (
                    <Field
                        label="Contraseña actual"
                        type="password"
                        name="current_password"
                        autoComplete="current-password"
                        required
                        value={form.data.current_password}
                        onChange={(event) => form.setData('current_password', event.target.value)}
                        error={form.errors.current_password}
                    />
                )}
                <Field
                    label="Contraseña nueva"
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
                    label="Confirmar contraseña nueva"
                    type="password"
                    name="password_confirmation"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    error={form.errors.password_confirmation}
                />
                <StoreButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Guardando…' : hasPassword ? 'Cambiar contraseña' : 'Crear contraseña'}
                </StoreButton>
            </form>
        </Section>
    );
}

/** Libreta de direcciones: lista, y un formulario para agregar o editar una a la vez. */
function Addresses({ addresses }: { addresses: CustomerAddress[] }) {
    // 'new' = formulario de dirección nueva; un número = id de la que se edita.
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [deleting, setDeleting] = useState<number | null>(null);

    const remove = (id: number) => router.delete(`/mi-cuenta/direcciones/${id}`, { preserveScroll: true, onSuccess: () => setDeleting(null) });

    return (
        <Section id="direcciones" title="Mis direcciones" description="Las usamos para enviar tus pedidos.">
            {addresses.length === 0 && editing !== 'new' && <p className="text-store-muted">Todavía no guardas ninguna dirección.</p>}

            <ul className="space-y-4">
                {addresses.map((address) => (
                    <li key={address.id} className="border-store-border rounded-store border-[length:var(--store-border-width)] p-4">
                        {editing === address.id ? (
                            <AddressForm address={address} onDone={() => setEditing(null)} />
                        ) : (
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="flex gap-3">
                                    <MapPin className="text-store-muted mt-0.5 size-5 shrink-0" aria-hidden="true" />
                                    <address className="text-store-text text-sm leading-relaxed not-italic">
                                        {address.street}
                                        <br />
                                        {address.neighborhood}, C.P. {address.zip}
                                        <br />
                                        {address.city}, {address.state}
                                        {address.notes && <span className="text-store-muted mt-1 block">Referencias: {address.notes}</span>}
                                    </address>
                                </div>

                                {deleting === address.id ? (
                                    <div role="alert" className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-semibold">¿Borrar esta dirección?</span>
                                        <StoreButton size="sm" onClick={() => remove(address.id)}>
                                            Sí, borrar
                                        </StoreButton>
                                        <StoreButton size="sm" variant="outline" onClick={() => setDeleting(null)}>
                                            Cancelar
                                        </StoreButton>
                                    </div>
                                ) : (
                                    <div className="flex gap-2">
                                        <StoreButton
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(address.id)}
                                            aria-label={`Editar la dirección ${address.street}`}
                                        >
                                            Editar
                                        </StoreButton>
                                        <StoreButton
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setDeleting(address.id)}
                                            aria-label={`Borrar la dirección ${address.street}`}
                                        >
                                            Borrar
                                        </StoreButton>
                                    </div>
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            {editing === 'new' ? (
                <div className="border-store-border rounded-store mt-4 border-[length:var(--store-border-width)] p-4">
                    <AddressForm onDone={() => setEditing(null)} />
                </div>
            ) : (
                <StoreButton variant="outline" className="mt-4" onClick={() => setEditing('new')}>
                    Agregar dirección
                </StoreButton>
            )}
        </Section>
    );
}

function AddressForm({ address, onDone }: { address?: CustomerAddress; onDone: () => void }) {
    const form = useForm({
        street: address?.street ?? '',
        neighborhood: address?.neighborhood ?? '',
        city: address?.city ?? '',
        state: address?.state ?? '',
        zip: address?.zip ?? '',
        notes: address?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (address) {
            form.put(`/mi-cuenta/direcciones/${address.id}`, options);
        } else {
            form.post('/mi-cuenta/direcciones', options);
        }
    };

    const field = (name: keyof typeof form.data) => ({
        name,
        value: form.data[name],
        onChange: (event: { target: { value: string } }) => form.setData(name, event.target.value),
        error: form.errors[name],
    });

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <Field label="Calle y número" autoComplete="address-line1" required autoFocus {...field('street')} />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Colonia" autoComplete="address-line2" required {...field('neighborhood')} />
                <Field label="Código postal" autoComplete="postal-code" inputMode="numeric" maxLength={5} required {...field('zip')} />
                <Field label="Ciudad" autoComplete="address-level2" required {...field('city')} />
                <Field label="Estado" autoComplete="address-level1" required {...field('state')} />
            </div>
            <Field label="Referencias" hint="Color de la casa, entre qué calles… (opcional)" {...field('notes')} />

            <div className="flex flex-wrap gap-2">
                <StoreButton type="submit" disabled={form.processing}>
                    {form.processing ? 'Guardando…' : address ? 'Guardar cambios' : 'Guardar dirección'}
                </StoreButton>
                <StoreButton variant="ghost" onClick={onDone}>
                    Cancelar
                </StoreButton>
            </div>
        </form>
    );
}
