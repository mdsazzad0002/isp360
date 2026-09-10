/**
 * Common reusable select box for the app — a lightweight anchored dropdown
 * (NOT a full-screen modal, no backdrop/shadow overlay).
 *
 * Usage is the same as the old v-select:
 *   <easy-select :options="list" v-model="selected" label="display_name"
 *                @input="onChange" @search="onSearch" id="someId"></easy-select>
 *
 * - Clicking the box turns it into a search input (auto-focused) and opens a
 *   small panel right below it, capped to the box's own width.
 * - Typing is debounced (300ms) before it filters / fires the "search" event,
 *   so parents doing remote search (axios calls) are not spammed on every keystroke.
 * - Only the first `limit` (default 20) matching items are rendered at a time.
 * - The panel is moved to <body> and positioned with fixed coordinates computed
 *   from the box's own position, so it is never clipped by an ancestor's
 *   overflow/positioning (works even inside another modal).
 */
Vue.component('easy-select', {
    props: {
        options: {
            type: Array,
            default: () => []
        },
        value: {
            default: null
        },
        label: {
            type: String,
            default: 'label'
        },
        placeholder: {
            type: String,
            default: 'Select...'
        },
        limit: {
            type: Number,
            default: 20
        },
        disabled: {
            type: Boolean,
            default: false
        }
    },

    data() {
        return {
            open: false,
            query: '',
            loading: false,
            highlightIndex: -1,
            panelStyle: {}
        }
    },

    computed: {
        displayLabel() {
            return this.value ? this.value[this.label] : '';
        },
        filteredOptions() {
            if (!this.query) {
                return this.options;
            }
            let q = this.query.toLowerCase();
            return this.options.filter(opt => String(opt[this.label] || '').toLowerCase().includes(q));
        },
        visibleOptions() {
            return this.filteredOptions.slice(0, this.limit);
        }
    },

    created() {
        this.debouncedSearch = _.debounce(() => {
            this.$emit('search', this.query, this.setLoading);
        }, 300);
    },

    mounted() {
        document.body.appendChild(this.$refs.panelEl);
    },

    beforeDestroy() {
        window.removeEventListener('scroll', this.updatePosition, true);
        window.removeEventListener('resize', this.updatePosition);
        document.removeEventListener('click', this.handleClickOutside);
        if (this.$refs.panelEl && this.$refs.panelEl.parentNode) {
            this.$refs.panelEl.parentNode.removeChild(this.$refs.panelEl);
        }
    },

    methods: {
        setLoading(val) {
            this.loading = val;
        },

        updatePosition() {
            if (!this.$refs.toggleEl) {
                return;
            }
            let rect = this.$refs.toggleEl.getBoundingClientRect();
            let spaceBelow = window.innerHeight - rect.bottom;
            this.panelStyle = {
                position: 'fixed',
                left: rect.left + 'px',
                top: (rect.bottom + 4) + 'px',
                width: rect.width + 'px',
                maxWidth: rect.width + 'px',
                maxHeight: Math.max(160, spaceBelow - 16) + 'px'
            };
        },

        handleClickOutside(e) {
            if (this.$refs.root && this.$refs.root.contains(e.target)) {
                return;
            }
            if (this.$refs.panelEl && this.$refs.panelEl.contains(e.target)) {
                return;
            }
            this.closePanel();
        },

        openPanel() {
            if (this.disabled || this.open) {
                return;
            }
            this.open = true;
            this.highlightIndex = -1;
            this.updatePosition();
            window.addEventListener('scroll', this.updatePosition, true);
            window.addEventListener('resize', this.updatePosition);
            document.addEventListener('click', this.handleClickOutside);
            this.$nextTick(() => {
                this.$refs.search && this.$refs.search.focus();
            });
        },

        closePanel() {
            this.open = false;
            this.query = '';
            this.highlightIndex = -1;
            window.removeEventListener('scroll', this.updatePosition, true);
            window.removeEventListener('resize', this.updatePosition);
            document.removeEventListener('click', this.handleClickOutside);
        },

        onSearchInput() {
            this.highlightIndex = -1;
            this.debouncedSearch();
        },

        selectOption(opt) {
            this.$emit('input', opt);
            this.closePanel();
        },

        clearSelection(e) {
            e.stopPropagation();
            this.$emit('input', null);
        },

        onArrowDown() {
            if (this.highlightIndex < this.visibleOptions.length - 1) {
                this.highlightIndex++;
            }
        },

        onArrowUp() {
            if (this.highlightIndex > 0) {
                this.highlightIndex--;
            }
        },

        onEnter() {
            if (this.highlightIndex > -1 && this.visibleOptions[this.highlightIndex]) {
                this.selectOption(this.visibleOptions[this.highlightIndex]);
            }
        }
    },

    template: `
        <div class="easy-select" :class="{ 'is-open': open, 'is-disabled': disabled }" @click="openPanel" ref="root">
            <div class="easy-select-toggle" ref="toggleEl">
                <span v-if="!open" class="easy-select-label" :class="{ 'text-muted': !value }" v-text="value ? displayLabel : placeholder"></span>
                <input v-if="open"
                       type="search"
                       ref="search"
                       class="easy-select-search-input"
                       autocomplete="off"
                       v-model="query"
                       :placeholder="placeholder"
                       @input="onSearchInput"
                       @keydown.esc="closePanel"
                       @keydown.down.prevent="onArrowDown"
                       @keydown.up.prevent="onArrowUp"
                       @keydown.enter.prevent="onEnter" />
                <i v-if="value && !open && !disabled" class="bi bi-x-circle-fill easy-select-clear" @click.stop="clearSelection"></i>
                <i class="bi easy-select-caret" :class="open ? 'bi-search' : 'bi-chevron-down'"></i>
            </div>

            <div class="easy-select-panel" ref="panelEl" v-show="open" :style="panelStyle">
                <ul class="easy-select-panel-list">
                    <li v-if="loading" class="easy-select-panel-msg">Searching...</li>
                    <template v-else>
                        <li v-if="visibleOptions.length === 0" class="easy-select-panel-msg">No options found</li>
                        <li v-for="(opt, idx) in visibleOptions"
                            :key="idx"
                            class="easy-select-panel-option"
                            :class="{ 'is-highlighted': idx === highlightIndex }"
                            @click="selectOption(opt)"
                            @mouseenter="highlightIndex = idx"
                            v-text="opt[label]"></li>
                        <li v-if="filteredOptions.length > visibleOptions.length" class="easy-select-panel-msg">
                            Showing {{ visibleOptions.length }} of {{ filteredOptions.length }} — keep typing to narrow results
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    `
});
