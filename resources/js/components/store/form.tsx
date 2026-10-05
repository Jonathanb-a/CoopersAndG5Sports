import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode, useId } from 'react';

interface FieldProps extends Omit<ComponentProps<'input'>, 'id'> {
    label: string;
    error?: string;
    hint?: string;
    /** Campo que no se puede editar (se ve, pero no se cambia). */
    wrapperClassName?: string;
}

/** Campo de formulario de la tienda: etiqueta visible, error asociado y colores del tema. */
export function Field({ label, error, hint, className, wrapperClassName, ...props }: FieldProps) {
    const id = useId();
    const messageId = `${id}-message`;

    return (
        <div className={wrapperClassName}>
            <label htmlFor={id} className="font-store-body text-store-text mb-1.5 block text-sm font-semibold">
                {label}
            </label>
            <input
                id={id}
                aria-invalid={error ? true : undefined}
                aria-describedby={error || hint ? messageId : undefined}
                className={cn(
                    'rounded-store font-store-body bg-store-surface text-store-text placeholder:text-store-muted h-11 w-full border-[length:var(--store-border-width)] px-3 text-base',
                    'focus-visible:outline-store-accent read-only:bg-store-bg read-only:text-store-muted focus-visible:outline-2 focus-visible:outline-offset-1',
                    error ? 'border-red-600' : 'border-store-border',
                    className,
                )}
                {...props}
            />
            {(error || hint) && (
                <p id={messageId} className={cn('mt-1.5 text-sm', error ? 'font-medium text-red-600 dark:text-red-400' : 'text-store-muted')}>
                    {error ?? hint}
                </p>
            )}
        </div>
    );
}

/** Aviso de éxito o información sobre un formulario (se anuncia a lectores de pantalla). */
export function FormStatus({ children, className }: { children?: ReactNode; className?: string }) {
    if (!children) {
        return null;
    }

    return (
        <p role="status" className={cn('store-surface text-store-text px-4 py-3 text-sm font-medium', className)}>
            {children}
        </p>
    );
}

/** Aviso de error general de un formulario. */
export function FormAlert({ children, className }: { children?: ReactNode; className?: string }) {
    if (!children) {
        return null;
    }

    return (
        <p role="alert" className={cn('rounded-store border border-red-600 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400', className)}>
            {children}
        </p>
    );
}
