import { cn } from '@/lib/utils';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { type ReactNode } from 'react';

interface MobileMenuProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Elemento raíz del tema: el panel se monta dentro para heredar sus variables CSS. */
    container: HTMLElement | null;
    title: string;
    /** Lado del que sale el panel. */
    side?: 'left' | 'right';
    className?: string;
    children: ReactNode;
}

/** Panel lateral (menú, filtros, mini carrito): con foco atrapado, cierra con Escape y respeta el movimiento reducido. */
export function MobileMenu({ open, onOpenChange, container, title, side = 'left', className, children }: MobileMenuProps) {
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal container={container}>
                <Dialog.Overlay className="data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 fixed inset-0 z-40 bg-black/50" />
                <Dialog.Content
                    aria-describedby={undefined}
                    className={cn(
                        'bg-store-bg text-store-text fixed inset-y-0 z-50 flex w-80 max-w-[85vw] flex-col p-6 shadow-xl outline-none',
                        'data-[state=closed]:animate-out data-[state=open]:animate-in',
                        side === 'left'
                            ? 'data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left left-0'
                            : 'data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right right-0',
                        className,
                    )}
                >
                    <Dialog.Title className="sr-only">{title}</Dialog.Title>
                    <Dialog.Close
                        aria-label="Cerrar menú"
                        className="rounded-store text-store-text hover:text-store-link focus-visible:outline-store-accent absolute top-3 right-3 inline-flex size-11 items-center justify-center focus-visible:outline-2"
                    >
                        <X className="size-5" />
                    </Dialog.Close>
                    {children}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
