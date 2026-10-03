import { cn } from '@/lib/utils';
import { Search, ShoppingBag, User } from 'lucide-react';

interface HeaderActionsProps {
    onToggleSearch?: () => void;
    searchOpen?: boolean;
    className?: string;
    buttonClassName?: string;
}

/** Íconos de buscar (solo celular), carrito y cuenta. El carrito y la cuenta aún no tienen función. */
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
            <button type="button" aria-label="Mi cuenta" title="Próximamente" className={button}>
                <User className="size-5" />
            </button>
            <button type="button" aria-label="Carrito" title="Próximamente" className={button}>
                <ShoppingBag className="size-5" />
            </button>
        </div>
    );
}
