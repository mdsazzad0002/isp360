<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Editor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Table } from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import Image from '@tiptap/extension-image';
import axios from 'axios';

const props = defineProps({
    modelValue: { type: String, default: '' },
    uploadUrl: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const fileInput = ref(null);

const editor = new Editor({
    content: props.modelValue || '',
    extensions: [
        StarterKit,
        Table.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,
        Image,
    ],
    editorProps: {
        attributes: { class: 'rich-text-content min-h-[220px] px-3 py-2 text-sm focus:outline-none' },
    },
    onUpdate: ({ editor: ed }) => {
        emit('update:modelValue', ed.getHTML());
    },
});

watch(
    () => props.modelValue,
    (value) => {
        if (value !== editor.getHTML()) {
            editor.commands.setContent(value || '', { emitUpdate: false });
        }
    }
);

function insertTable() {
    editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
}

function triggerImagePick() {
    fileInput.value?.click();
}

async function onImageSelected(e) {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    const formData = new FormData();
    formData.append('image', file);
    const res = await axios.post(props.uploadUrl, formData, { headers: { 'Content-Type': 'multipart/form-data' } });
    if (res.data.status) {
        editor.chain().focus().setImage({ src: res.data.url }).run();
    }
}

onBeforeUnmount(() => {
    editor.destroy();
});
</script>

<template>
    <div class="overflow-hidden rounded-md border border-slate-300">
        <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-slate-50 px-2 py-1.5">
            <button type="button" @click="editor.chain().focus().toggleBold().run()" class="rounded px-2 py-1 text-xs font-bold hover:bg-slate-200" :class="{ 'bg-slate-200': editor.isActive('bold') }">B</button>
            <button type="button" @click="editor.chain().focus().toggleItalic().run()" class="rounded px-2 py-1 text-xs italic hover:bg-slate-200" :class="{ 'bg-slate-200': editor.isActive('italic') }">I</button>
            <button type="button" @click="editor.chain().focus().toggleBulletList().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" :class="{ 'bg-slate-200': editor.isActive('bulletList') }">
                <i class="bi bi-list-ul"></i>
            </button>
            <button type="button" @click="editor.chain().focus().toggleOrderedList().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" :class="{ 'bg-slate-200': editor.isActive('orderedList') }">
                <i class="bi bi-list-ol"></i>
            </button>
            <span class="mx-1 h-4 w-px bg-slate-300"></span>
            <button type="button" @click="insertTable" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Insert table">
                <i class="bi bi-table"></i> Table
            </button>
            <button type="button" @click="editor.chain().focus().addColumnAfter().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Add column">+Col</button>
            <button type="button" @click="editor.chain().focus().addRowAfter().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Add row">+Row</button>
            <button type="button" @click="editor.chain().focus().deleteColumn().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Delete column">-Col</button>
            <button type="button" @click="editor.chain().focus().deleteRow().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Delete row">-Row</button>
            <button type="button" @click="editor.chain().focus().deleteTable().run()" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Delete table">
                <i class="bi bi-trash"></i>
            </button>
            <span class="mx-1 h-4 w-px bg-slate-300"></span>
            <button type="button" @click="triggerImagePick" class="rounded px-2 py-1 text-xs hover:bg-slate-200" title="Insert image">
                <i class="bi bi-image"></i> Image
            </button>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

<style>
.rich-text-content table {
    border-collapse: collapse;
    width: 100%;
}
.rich-text-content table td,
.rich-text-content table th {
    border: 1px solid #94a3b8;
    padding: 4px 8px;
    position: relative;
}
.rich-text-content table th {
    background: #f1f5f9;
    font-weight: 600;
}
.rich-text-content img {
    max-width: 100%;
    height: auto;
}
.rich-text-content ul {
    list-style: disc;
    padding-left: 1.25rem;
}
.rich-text-content ol {
    list-style: decimal;
    padding-left: 1.25rem;
}
</style>
