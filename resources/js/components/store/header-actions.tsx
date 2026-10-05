import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Search, ShoppingBag } from 'lucide-react';
import { AccountMenu } from './account-menu';

interface HeaderActionsProps {
    onToggleSearch?: () => void;
    searchOpen?: boolean;
    className?: string;
    buttonClassName?: string;
}

/** Buscar (solo celular), cuenta del cliente y carrito con su contador. */
export function HeaderActions({ onToggleSearch, searchOpen, className, buttonClassName }: HeaderActionsProps) {
    const { miniCart } = usePage<SharedData>().props;
    const count = miniCart.count;
    const cartLabel = count > 0 ? `Carrito, ${count} ${count === 1 ? 'pieza' : 'piezas'}` : 'Carrito';

    const button = cn(
        'rounded-store text-store-text hover:text-store-link focus-visible:outline-store-accent inline-flex size-10 items-center justify-center focus-visible:outline-2 sm:size-11',
        buttonClassName,
    );

    return (
        <div className={cn('flex items-center', className)}>
            {onToggleSearch && (
                <button type="button" aria-label="Buscar" aria-expanded={searchOpen} onClick={onToggleSearch} className={cn(button, 'lg:hidden')}>
                    <Search className="size-5" />
                </button>
            )}
            <AccountMenu />
            <Link href="/carrito" aria-label={cartLabel} className={cn(button, 'relative')}>
                <ShoppingBag className="size-5" />
                {count > 0 && (
                    <span
                        aria-hidden="true"
                        className="bg-store-action text-store-action-text font-store-body absolute top-0 right-0 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[11px] font-bold"
                    >
                        {count > 99 ? '99+' : count}
                    </span>
                )}
            </Link>
        </div>
    );
}
