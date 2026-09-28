<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();
const data = ref(null);
const busy = ref(false);

const load = () => axios.post('/isp/get-backups').then((r) => (data.value = r.data));
onMounted(load);

const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.ceil(bytes / 1024)} KB`);

async function backupNow() {
    busy.value = true;
    try {
        toast.success((await axios.post('/isp/backup')).data.message);
        load();
    } catch (err) {
        showError(err);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-3 p-4">
        <section v-if="data" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-700">Backups</h2>
                <button type="button" :disabled="busy" class="rounded-md bg-brand-600 px-3 py-1.5 text-sm text-white hover:bg-brand-700 disabled:opacity-60" @click="backupNow">
                    <i class="bi" :class="busy ? 'bi-hourglass-split' : 'bi-cloud-arrow-down'"></i> {{ busy ? 'Backing up…' : 'Back up now' }}
                </button>
            </div>
            <p class="mb-3 text-xs text-slate-500">
                Each backup holds the whole database, uploaded files and private files (ticket attachments, KYC documents).
                <template v-if="data.schedule.enabled">A backup runs every day at {{ data.schedule.at }}; the newest {{ data.schedule.keep }} are kept.</template>
                <template v-else>Daily backups are switched off (ISP_BACKUP_ENABLED).</template>
                Download them and keep a copy away from this server. Restore from the command line: <code>php artisan isp:restore &lt;file&gt;</code>.
            </p>
            <p v-if="!data.backups.length" class="text-sm text-slate-500">No backups yet.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-start text-xs text-slate-500">
                            <th class="py-1.5 pe-2 font-medium">Taken</th>
                            <th class="py-1.5 pe-2 font-medium">File</th>
                            <th class="py-1.5 pe-2 font-medium">Size</th>
                            <th class="py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in data.backups" :key="b.name" class="border-b border-slate-100">
                            <td class="whitespace-nowrap py-1.5 pe-2">{{ b.created_at }}</td>
                            <td class="py-1.5 pe-2 font-mono text-xs">{{ b.name }}</td>
                            <td class="whitespace-nowrap py-1.5 pe-2">{{ size(b.size) }}</td>
                            <td class="py-1.5 text-end">
                                <a :href="`/isp/backup-download/${b.name}`" class="rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-50"><i class="bi bi-download"></i> Download</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
