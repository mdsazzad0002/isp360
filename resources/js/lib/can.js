import { usePage } from '@inertiajs/vue3';

export function useCan() {
    const page = usePage();
    return function can(action) {
        const user = page.props.auth?.user;
        if (!user) return false;
        if (user.role === 'Superadmin' || user.role === 'admin') return true;
        return (user.actions || []).includes(action);
    };
}
