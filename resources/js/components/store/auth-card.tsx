import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';
import { Container } from './container';
import { Reveal } from './reveal';

interface AuthCardProps {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    className?: string;
}

/** Tarjeta centrada para las pantallas de acceso (ingresar, registro, recuperar contraseña). */
export function AuthCard({ title, description, children, footer, className }: AuthCardProps) {
    return (
        <Container className="py-10 sm:py-16 lg:py-20">
            <Reveal>
                <section className={cn('store-surface mx-auto w-full max-w-md p-6 sm:p-8', className)} aria-labelledby="titulo-acceso">
                    <h1 id="titulo-acceso" className="store-heading text-4xl sm:text-5xl">
                        {title}
                    </h1>
                    {description && <p className="text-store-muted mt-3">{description}</p>}
                    <div className="mt-6">{children}</div>
                    {footer && <div className="border-store-border text-store-muted mt-6 border-t pt-5 text-center text-sm">{footer}</div>}
                </section>
            </Reveal>
        </Container>
    );
}
