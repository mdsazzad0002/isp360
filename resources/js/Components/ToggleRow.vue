<script setup>
defineProps({
    modelValue: { type: String, required: true }, // 'active' | 'inactive'
    label: { type: String, required: true },
    hint: { type: String, default: '' },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex items-center justify-between py-2.5">
        <div>
            <p class="text-sm font-medium text-slate-700">{{ label }}</p>
            <span v-if="hint" class="mt-0.5 block text-xs text-slate-400">{{ hint }}</span>
        </div>
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue === 'active'"
            @click="$emit('update:modelValue', modelValue === 'active' ? 'inactive' : 'active')"
            class="relative h-6 w-11 shrink-0 cursor-pointer rounded-full transition"
            :class="modelValue === 'active' ? 'bg-brand-500' : 'bg-slate-300'"
        >
            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white transition" :class="modelValue === 'active' ? 'left-5' : 'left-0.5'"></span>
        </button>
    </div>
</template>
