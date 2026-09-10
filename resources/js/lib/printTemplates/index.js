import classicTemplate from './classic';

// Registry of available print/invoice designs. Add a new entry here (key +
// render function of the same shape as classicTemplate) to make a design
// selectable — the storage helpers below already support switching between
// whatever keys exist in this map.
const TEMPLATES = {
    classic: { label: 'Classic', render: classicTemplate },
};

const STORAGE_KEY = 'printTemplate';
const DEFAULT_TEMPLATE = 'classic';

export function listPrintTemplates() {
    return Object.entries(TEMPLATES).map(([key, { label }]) => ({ key, label }));
}

export function getSelectedPrintTemplateKey() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        return stored && TEMPLATES[stored] ? stored : DEFAULT_TEMPLATE;
    } catch (e) {
        return DEFAULT_TEMPLATE;
    }
}

export function setSelectedPrintTemplateKey(key) {
    if (!TEMPLATES[key]) return;
    try {
        localStorage.setItem(STORAGE_KEY, key);
    } catch (e) {
        /* storage unavailable, ignore */
    }
}

export function renderPrintTemplate(key, data = {}) {
    const template = TEMPLATES[key] || TEMPLATES[DEFAULT_TEMPLATE];
    return template.render(data);
}
