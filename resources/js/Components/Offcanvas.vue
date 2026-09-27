<script setup>
// Right-side panel with the same API as Modal.vue (show / width / @close).
// Same layer as modals (z 2100); quick-entry offcanvases (2130) and dropdowns (2150) stack above it.
defineProps({
    show: { type: Boolean, default: false },
    width: { type: String, default: 'sm:w-[640px]' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity ease-out duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity ease-in duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-[2100] flex justify-end">
                <div class="absolute inset-0 bg-slate-900/40" @click="emit('close')"></div>
                <div class="relative flex h-full w-full flex-col bg-white shadow-2xl animate-slide-in" :class="width">
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
@keyframes slide-in {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
.animate-slide-in {
    animation: slide-in 0.25s ease-out;
}
</style>
