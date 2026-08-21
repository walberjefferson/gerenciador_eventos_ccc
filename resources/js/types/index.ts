import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    /** Fica nulo quando quem visita a pagina nao esta autenticado. */
    user: User | null;
    /**
     * Os nomes das permissoes de quem esta logado. Serve so para a tela nao
     * oferecer caminho que o servidor recusaria; a tranca de verdade continua
     * no middleware da rota.
     */
    permissoes: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    /**
     * O Inertia exige que os dados compartilhados aceitem qualquer chave: cada
     * pagina acrescenta as suas. Esta linha e o que torna SharedData utilizavel
     * em usePage<SharedData>().
     */
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
