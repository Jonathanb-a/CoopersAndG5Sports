import { cn } from '@/lib/utils';
import { type CategoryCard as CategoryCardData } from '@/types';
import { Link } from '@inertiajs/react';

interface CategoryCardProps {
    category: CategoryCardData;
    className?: string;
}

/** Acceso a una categoría: foto de uno de sus productos, nombre y cuántos productos tiene. */
export function CategoryCard({ category, className }: CategoryCardProps) {
    return (
        <Link
            href={`/categoria/${category.slug}`}
            className={cn(
                'group store-surface store-transition store-lift focus-visible:outline-store-accent block focus-visible:outline-2',
                className,
            )}
        >
            <div className="overflow-hidden rounded-[inherit]">
                <div className="bg-store-bg relative aspect-[4/3] overflow-hidden">
                    {category.image_url ? (
                        <img
                            src={category.image_url}
                            alt=""
                            width={800}
                            height={600}
                            loading="lazy"
                            className="store-transition size-full object-cover group-hover:scale-105"
                        />
                    ) : (
                        <div className="store-heading text-store-muted flex size-full items-center justify-center text-6xl" aria-hidden="true">
                            {category.name.charAt(0)}
                        </div>
                    )}
                </div>
                <div className="p-3 sm:p-4">
                    <h3 className="store-heading text-xl sm:text-2xl">{category.name}</h3>
                    <p className="font-store-body text-store-muted mt-0.5 text-sm">
                        {category.products_count} {category.products_count === 1 ? 'producto' : 'productos'}
                    </p>
                </div>
            </div>
        </Link>
    );
}
