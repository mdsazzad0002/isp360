<script setup>
import { ref, onMounted, watch } from 'vue';
import axios from 'axios';
import SearchSelect from '../SearchSelect.vue';

// Remote customer search (name / phone / code). v-model is the selected customer object.
const props = defineProps({
    modelValue: { default: null },
    placeholder: { type: String, default: 'Search customer by name, phone or code' },
    preselectId: { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const options = ref([]);

function fetch(search = '', setLoading = () => {}) {
    setLoading(true);
    return axios
        .post('/get-customer', { forSearch: true, search })
        .then((res) => {
            options.value = res.data;
            return res.data;
        })
        .finally(() => setLoading(false));
}

onMounted(async () => {
    if (props.preselectId) {
        const res = await axios.post('/get-customer', { customerId: props.preselectId });
        const found = res.data?.[0];
        if (found) {
            options.value = [found];
            emit('update:modelValue', found);
        }
    } else {
        fetch();
    }
});

watch(
    () => props.modelValue,
    (v) => {
        if (v && !options.value.find((o) => o.id === v.id)) options.value = [v, ...options.value];
    }
);
</script>

<template>
    <SearchSelect
        :options="options"
        :model-value="modelValue"
        label="display_name"
        :placeholder="placeholder"
        :disabled="disabled"
        @update:model-value="emit('update:modelValue', $event)"
        @search="(q, setLoading) => fetch(q, setLoading)"
    />
</template>
