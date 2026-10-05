import { cn } from '@/lib/utils';
import { type ProductSize, type SizeStatus } from '@/types';
import { useId } from 'react';

const statusLabels: Record<SizeStatus, string | null> = {
    available: null,
    'last-piece': 'Última pieza',
    backorder: 'Sobre pedido',
    'sold-out': 'Agotada',
};

interface SizeSelectorProps {
    sizes: ProductSize[];
    value: string | null;
    onChange: (size: string) => void;
    className?: string;
}

/**
 * Selector de talla. Cada talla dice su estado (disponible, última pieza, sobre pedido o agotada).
 * Las agotadas sin sobre pedido van deshabilitadas: no se pueden elegir ni con el teclado.
 */
export function SizeSelector({ sizes, value, onChange, className }: SizeSelectorProps) {
    const name = useId();

    return (
        <fieldset className={className}>
            <legend className="font-store-body text-store-text mb-3 text-sm font-semibold">
                Talla
                {value && <span className="text-store-muted font-normal">: {value}</span>}
            </legend>

            <div className="flex flex-wrap gap-2">
                {sizes.map((item) => {
                    const label = statusLabels[item.status];
                    const selected = value === item.size;

                    return (
                        <label
                            key={item.size}
                            className={cn('flex w-20 flex-col items-center gap-1', item.selectable ? 'cursor-pointer' : 'cursor-not-allowed')}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={item.size}
                                checked={selected}
                                disabled={!item.selectable}
                                onChange={() => onChange(item.size)}
                                className="peer sr-only"
                            />
                            <span
                                className={cn(
                                    'rounded-store font-store-body store-transition peer-focus-visible:outline-store-accent flex h-11 w-full items-center justify-center border-[length:var(--store-border-width)] text-sm font-semibold peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2',
                                    selected
                                        ? 'border-store-action bg-store-action text-store-action-text'
                                        : item.selectable
                                          ? 'border-store-border bg-store-surface text-store-text hover:border-store-text'
                                          : 'border-store-border bg-store-bg text-store-muted line-through opacity-60',
                                )}
                            >
                                {item.size}
                            </span>
                            <span
                                className={cn(
                                    'font-store-body text-center text-xs',
                                    item.selectable ? 'text-store-muted' : 'text-store-muted opacity-80',
                                )}
                            >
                                {label ?? <span className="sr-only">Disponible</span>}
                            </span>
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}
