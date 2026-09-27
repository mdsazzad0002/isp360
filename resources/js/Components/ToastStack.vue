<script setup>
import { useToast } from '../lib/toast';

const { toasts } = useToast();
</script>

<template>
    <Teleport to="body">
        <div class="fixed bottom-4 end-4 z-[2200] flex w-72 flex-col-reverse gap-2">
            <TransitionGroup
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 translate-y-4"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-for="t in toasts"
                    :key="t.id"
                    class="rounded-md px-4 py-2.5 text-sm text-white shadow-lg"
                    :class="{ 'bg-red-600': t.type === 'error', 'bg-blue-600': t.type === 'info', 'bg-emerald-600': t.type !== 'error' && t.type !== 'info' }"
                >
                    {{ t.message }}
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
