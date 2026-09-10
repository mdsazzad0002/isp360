import { reactive } from 'vue';

const toasts = reactive([]);
let uid = 0;

function push(type, message) {
    const id = ++uid;
    toasts.push({ id, type, message });
    setTimeout(() => {
        const idx = toasts.findIndex((t) => t.id === id);
        if (idx !== -1) toasts.splice(idx, 1);
    }, 4000);
}

export function useToast() {
    return {
        toasts,
        success: (message) => push('success', message),
        error: (message) => push('error', message),
        info: (message) => push('info', message),
    };
}
