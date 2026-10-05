import { cn } from '@/lib/utils';
import { Search, ShoppingBag } from 'lucide-react';
import { AccountMenu } from './account-menu';

interface HeaderActionsProps {
    onToggleSearch?: () => void;
    searchOpen?: boolean;
    className?: string;
    buttonClassName?: string;
}

/** Buscar (solo celular), cuenta del cliente y carrito. El carrito llega en la fase 4. */
export function HeaderActions({ onToggleSearch, searchOpen, className, buttonClassName }: HeaderActionsProps) {
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
            <button type="button" aria-label="Carrito" title="Próximamente" className={button}>
                <ShoppingBag className="size-5" />
            </button>
        </div>
    );
}
