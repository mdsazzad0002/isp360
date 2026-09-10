<script setup>
import { ref, reactive, computed, nextTick, watch, onBeforeUnmount } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    options: { type: Array, default: () => [] },
    modelValue: { default: null },
    label: { type: String, default: 'label' },
    placeholder: { type: String, default: 'Select...' },
    limit: { type: Number, default: 30 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'search', 'enter-no-match', 'open']);

const root = ref(null);
const toggleEl = ref(null);
const panelEl = ref(null);
const searchInput = ref(null);

const open = ref(false);
const query = ref('');
const loading = ref(false);
const highlightIndex = ref(-1);
const panelStyle = reactive({});

const displayLabel = computed(() => (props.modelValue ? props.modelValue[props.label] : ''));

const filteredOptions = computed(() => {
    if (!query.value) {
        return props.options;
    }
    const q = query.value.toLowerCase();
    return props.options.filter((opt) => String(opt[props.label] || '').toLowerCase().includes(q));
});

const visibleOptions = computed(() => filteredOptions.value.slice(0, props.limit));

const debouncedSearch = debounce(() => {
    emit('search', query.value, (val) => { loading.value = val; });
}, 300);

function updatePosition() {
    if (!toggleEl.value) return;
    const rect = toggleEl.value.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;
    const spaceAbove = rect.top;
    const openUpward = spaceBelow < 220 && spaceAbove > spaceBelow;
    panelStyle.position = 'fixed';
    panelStyle.left = rect.left + 'px';
    panelStyle.width = rect.width + 'px';
    panelStyle.maxWidth = rect.width + 'px';
    if (openUpward) {
        panelStyle.top = 'auto';
        panelStyle.bottom = (window.innerHeight - rect.top + 4) + 'px';
        panelStyle.maxHeight = Math.max(160, spaceAbove - 16) + 'px';
    } else {
        panelStyle.bottom = 'auto';
        panelStyle.top = (rect.bottom + 4) + 'px';
        panelStyle.maxHeight = Math.max(160, spaceBelow - 16) + 'px';
    }
}

function handleClickOutside(e) {
    if (root.value && root.value.contains(e.target)) return;
    if (panelEl.value && panelEl.value.contains(e.target)) return;
    closePanel();
}

function openPanel() {
    if (props.disabled || open.value) return;
    emit('open');
    open.value = true;
    query.value = '';
    highlightIndex.value = -1;
    updatePosition();
    window.addEventListener('scroll', updatePosition, true);
    window.addEventListener('resize', updatePosition);
    document.addEventListener('click', handleClickOutside);
    nextTick(() => {
        updatePosition();
        searchInput.value && searchInput.value.focus();
    });
}

function closePanel() {
    open.value = false;
    query.value = '';
    highlightIndex.value = -1;
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
    document.removeEventListener('click', handleClickOutside);
}

function onSearchInput() {
    highlightIndex.value = -1;
    debouncedSearch();
}

function selectOption(opt) {
    emit('update:modelValue', opt);
    closePanel();
}

function clearSelection(e) {
    e.stopPropagation();
    emit('update:modelValue', null);
}

function onArrowDown() {
    if (highlightIndex.value < visibleOptions.value.length - 1) {
        highlightIndex.value++;
    }
}

function onArrowUp() {
    if (highlightIndex.value > 0) {
        highlightIndex.value--;
    }
}

// Resolves once the in-flight (or about-to-run) debounced `search` finishes,
// so a decision below is never made against a stale/empty options list —
// e.g. text typed and Enter fired in the same tick (as a barcode scanner
// wedge does) would otherwise see zero matches purely because the backend
// search hasn't had its debounce delay elapse yet, not because there really
// is no match.
function waitForSearchToSettle() {
    return new Promise((resolve) => {
        if (!loading.value) {
            resolve();
            return;
        }
        const stop = watch(loading, (val) => {
            if (!val) {
                stop();
                resolve();
            }
        });
        setTimeout(() => {
            stop();
            resolve();
        }, 3000);
    });
}

async function onEnter() {
    if (highlightIndex.value > -1 && visibleOptions.value[highlightIndex.value]) {
        selectOption(visibleOptions.value[highlightIndex.value]);
        return;
    }

    if (!query.value.trim()) return;

    debouncedSearch.flush();
    await waitForSearchToSettle();

    // Enter never closes or auto-picks anything on its own beyond the
    // highlighted-option case above — the panel only closes via an explicit
    // selection (click, or Enter on a highlighted option) or a click
    // outside. Zero matches still notifies the parent (some pages use this
    // to offer "create new"), but the panel itself is left open either way.
    if (visibleOptions.value.length === 0) {
        emit('enter-no-match', query.value.trim());
    }
}

defineExpose({ open: openPanel });

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="root" class="relative w-full text-sm" @click="openPanel">
        <div
            ref="toggleEl"
            class="flex min-h-[30px] w-full items-center gap-2 rounded-md border px-3 py-1 transition"
            :class="[
                disabled ? 'cursor-not-allowed bg-slate-50 border-slate-200' : 'cursor-pointer bg-white border-slate-300 hover:border-brand-500',
                open ? 'border-brand-500 ring-2 ring-brand-500/15' : '',
            ]"
        >
            <span class="flex-1 truncate" :class="modelValue ? 'text-slate-800' : 'text-slate-400'">
                {{ modelValue ? displayLabel : placeholder }}
            </span>
            <i
                v-if="modelValue && !disabled"
                class="bi bi-x-circle-fill shrink-0 cursor-pointer text-slate-400 hover:text-red-500"
                @click.stop="clearSelection"
            ></i>
            <i class="bi shrink-0 text-slate-400" :class="open ? 'bi-search' : 'bi-chevron-down'"></i>
        </div>

        <Teleport to="body">
            <div
                v-show="open"
                ref="panelEl"
                :style="panelStyle"
                class="z-[2000] flex flex-col overflow-hidden rounded-md border border-slate-200 bg-white shadow-lg"
                @click.stop
            >
                <div class="border-b border-slate-200 p-1.5">
                    <input
                        ref="searchInput"
                        type="search"
                        autocomplete="off"
                        v-model="query"
                        :placeholder="placeholder"
                        class="w-full rounded border border-slate-200 px-2.5 py-1 text-sm outline-none focus:border-brand-500"
                        @input="onSearchInput"
                        @keydown.esc="closePanel"
                        @keydown.down.prevent="onArrowDown"
                        @keydown.up.prevent="onArrowUp"
                        @keydown.enter.prevent="onEnter"
                    />
                </div>
                <ul class="m-0 min-h-0 flex-1 list-none overflow-y-auto p-1">
                    <li v-if="loading" class="px-3 py-2 text-center text-xs text-slate-500">Searching...</li>
                    <template v-else>
                        <li v-if="visibleOptions.length === 0" class="px-3 py-2 text-center text-xs text-slate-500">No options found</li>
                        <li
                            v-for="(opt, idx) in visibleOptions"
                            :key="idx"
                            class="cursor-pointer truncate rounded px-3 py-2 text-sm"
                            :class="idx === highlightIndex ? 'bg-brand-500 text-white' : 'text-slate-700'"
                            @click="selectOption(opt)"
                            @mouseenter="highlightIndex = idx"
                        >
                            {{ opt[label] }}
                        </li>
                        <li v-if="filteredOptions.length > visibleOptions.length" class="px-3 py-2 text-center text-xs text-slate-500">
                            Showing {{ visibleOptions.length }} of {{ filteredOptions.length }} — keep typing to narrow results
                        </li>
                    </template>
                </ul>
            </div>
        </Teleport>
    </div>
</template>
