import { LucideIcon } from 'lucide-react';

export interface Auth {
    /** Cliente con sesión iniciada en esta tienda, o null. */
    customer: { id: number; name: string; email: string } | null;
}

export interface CustomerAddress {
    id: number;
    street: string;
    neighborhood: string;
    city: string;
    state: string;
    zip: string;
    notes: string | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface Store {
    id: number;
    name: string;
    slug: string;
    theme: string | null;
    whatsapp_phone: string | null;
}

export interface StoreCategory {
    id: number;
    name: string;
    slug: string;
}

export interface StoreBanner {
    id: number;
    image_url: string;
    title: string | null;
    link_url: string | null;
}

export interface CategoryCard extends StoreCategory {
    products_count: number;
    image_url: string | null;
}

export type ProductTagKind = 'sold-out' | 'backorder' | 'last-piece';

/** Tarjeta de producto de la tienda (inicio y catálogo). */
export interface StoreProduct {
    id: number;
    slug: string;
    name: string;
    brand: string | null;
    price: string;
    image_url: string | null;
    tag: ProductTagKind | null;
}

export interface CatalogFilters {
    q: string;
    marca: string[];
    talla: string[];
    disponibles: boolean;
    orden: string;
}

export interface CatalogPagination {
    current: number;
    last: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_url: string | null;
    next_url: string | null;
    /** null = puntos suspensivos */
    items: ({ page: number; url: string } | null)[];
}

export interface CatalogOptions {
    brands: { name: string; slug: string }[];
    sizes: string[];
}

export interface SharedData {
    name: string;
    store: Store;
    categories: StoreCategory[];
    quote: { message: string; author: string };
    auth: Auth;
    googleEnabled: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export type SizeStatus = 'available' | 'last-piece' | 'backorder' | 'sold-out';

export interface ProductSize {
    size: string;
    status: SizeStatus;
    selectable: boolean;
}

export interface ProductDetail {
    id: number;
    slug: string;
    name: string;
    brand: string | null;
    color: string | null;
    price: string;
    description: string | null;
    allow_backorder: boolean;
    backorder_days: number | null;
    images: string[];
    sizes: ProductSize[];
    category: { name: string; slug: string } | null;
}
