import { useTheme } from '@/Themes/context';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { User } from 'lucide-react';

const trigger =
    'rounded-store text-store-text hover:text-store-link focus-visible:outline-store-accent inline-flex h-11 min-w-11 items-center justify-center gap-2 px-2 text-sm font-semibold focus-visible:outline-2';

const item =
    'text-store-text data-[highlighted]:bg-store-bg data-[highlighted]:text-store-link flex min-h-11 cursor-pointer items-center px-4 text-sm outline-none';

/**
 * Cuenta del cliente en el encabezado: «Ingresar» si no ha iniciado sesión; si ya lo hizo, su nombre
 * con un menú (Mi cuenta, Mis pedidos, Cerrar sesión). En celular solo se ve el ícono.
 */
export function AccountMenu() {
    const { auth } = usePage<SharedData>().props;
    const { root } = useTheme();
    const customer = auth.customer;

    if (!customer) {
        return (
            <Link href="/login" className={trigger}>
                <User className="size-5" aria-hidden="true" />
                <span className="sr-only sm:not-sr-only">Ingresar</span>
            </Link>
        );
    }

    return (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger className={trigger} aria-label={`Cuenta de ${customer.name}`}>
                <User className="size-5" aria-hidden="true" />
                <span className="hidden max-w-32 truncate sm:inline">{customer.name}</span>
            </DropdownMenu.Trigger>
            <DropdownMenu.Portal container={root}>
                <DropdownMenu.Content
                    align="end"
                    sideOffset={6}
                    className="store-surface bg-store-surface z-50 min-w-48 overflow-hidden py-1 shadow-lg"
                >
                    <DropdownMenu.Label className="border-store-border text-store-muted truncate border-b px-4 py-2 text-xs sm:hidden">
                        {customer.name}
                    </DropdownMenu.Label>
                    <DropdownMenu.Item asChild>
                        <Link href="/mi-cuenta" className={item}>
                            Mi cuenta
                        </Link>
                    </DropdownMenu.Item>
                    <DropdownMenu.Item asChild>
                        <Link href="/mi-cuenta/pedidos" className={item}>
                            Mis pedidos
                        </Link>
                    </DropdownMenu.Item>
                    <DropdownMenu.Item className={item} onSelect={() => router.post('/logout')}>
                        Cerrar sesión
                    </DropdownMenu.Item>
                </DropdownMenu.Content>
            </DropdownMenu.Portal>
        </DropdownMenu.Root>
    );
}
