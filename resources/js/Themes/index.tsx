import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { type ComponentType, type ReactNode } from 'react';
import CoppersLayout from './coppers/layout';
import G5Layout from './g5/layout';

/** Un layout por tema. La llave es el valor de stores.theme. */
const layouts: Record<string, ComponentType<{ children: ReactNode }>> = {
    coppers: CoppersLayout,
    g5: G5Layout,
};

/** Layout de la tienda actual: elige el tema con la prop `store.theme` que comparte ResolveStore. */
export default function StoreLayout({ children }: { children: ReactNode }) {
    const { store } = usePage<SharedData>().props;
    const Layout = (store.theme && layouts[store.theme]) || CoppersLayout;

    return <Layout>{children}</Layout>;
}
