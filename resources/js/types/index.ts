import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
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

export interface NewestProduct {
    id: number;
    name: string;
    price: string;
    image_url: string | null;
    tag: 'sold-out' | 'backorder' | 'last-piece' | null;
}

export interface SharedData {
    name: string;
    store: Store;
    categories: StoreCategory[];
    quote: { message: string; author: string };
    auth: Auth;
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
