<script setup>
defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: 'max-w-2xl' },
});

const emit = defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-[2100] flex items-center justify-center bg-slate-900/40 px-4" @click="emit('close')">
                <div class="w-full rounded-lg bg-white shadow-xl" :class="maxWidth" @click.stop>
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
