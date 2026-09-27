<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../lib/toast';
import { useApiError } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();
const data = ref(null);
const labels = { network: 'Router pushes', sms: 'SMS', default: 'Other' };
const drivers = {
    sync: 'Jobs run inside the web request (no worker). Fine for small installations; a slow router or SMS gateway slows that page.',
    database: 'Database queue.',
    redis: 'Redis queue, worked by Horizon.',
};

const load = () => axios.post('/isp/get-queue').then((r) => (data.value = r.data));
let timer;
onMounted(() => {
    load();
    timer = setInterval(load, 15000);
});
onUnmounted(() => clearInterval(timer));

async function act(url, uuid, question) {
    if (question && !(await confirmDialog({ title: question, confirmButtonText: 'Yes' }))) return;
    try {
        toast.success((await axios.post(url, { uuid })).data.message);
        load();
    } catch (err) {
        showError(err);
    }
}
</script>

<template>
    <div class="space-y-3 p-4">
        <section v-if="data" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-700">Background jobs</h2>
                <a v-if="data.horizon" href="/horizon" target="_blank" class="text-sm text-brand-600 hover:underline">Open Horizon dashboard <i class="bi bi-box-arrow-up-right"></i></a>
            </div>
            <p class="mb-3 text-xs text-slate-500">
                {{ drivers[data.driver] || data.driver }}
                <template v-if="data.driver === 'database'">{{ data.in_scheduler ? 'The scheduler works it every minute, so jobs start within a minute.' : 'Run `php artisan queue:work` as a service, or set ISP_QUEUE_IN_SCHEDULER=true.' }}</template>
            </p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div v-for="(n, q) in data.waiting" :key="q" class="rounded-md border border-slate-200 p-3">
                    <div class="text-xs text-slate-500">{{ labels[q] || q }} waiting</div>
                    <div class="text-xl font-semibold text-slate-800">{{ n ?? '—' }}</div>
                </div>
                <div class="rounded-md border p-3" :class="data.failed_total ? 'border-red-200 bg-red-50' : 'border-slate-200'">
                    <div class="text-xs text-slate-500">Failed</div>
                    <div class="text-xl font-semibold" :class="data.failed_total ? 'text-red-600' : 'text-slate-800'">{{ data.failed_total }}</div>
                </div>
            </div>
        </section>

        <section v-if="data" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-700">Failed jobs</h2>
                <div v-if="data.failed_total" class="flex gap-2">
                    <button type="button" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50" @click="act('/isp/queue/retry', null, 'Retry every failed job?')">Retry all</button>
                    <button type="button" class="rounded border border-red-300 px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="act('/isp/queue/forget', null, 'Delete every failed job?')">Delete all</button>
                </div>
            </div>
            <p v-if="!data.failed.length" class="text-sm text-slate-500">No failed jobs.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                            <th class="py-1.5 pr-2 font-medium">Failed at</th>
                            <th class="py-1.5 pr-2 font-medium">Job</th>
                            <th class="py-1.5 pr-2 font-medium">Error</th>
                            <th class="py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="j in data.failed" :key="j.uuid" class="border-b border-slate-100 align-top">
                            <td class="whitespace-nowrap py-1.5 pr-2">{{ j.failed_at }}</td>
                            <td class="py-1.5 pr-2">{{ j.job }} <span class="text-xs text-slate-400">({{ j.queue }})</span></td>
                            <td class="py-1.5 pr-2 text-xs text-slate-600">{{ j.error }}</td>
                            <td class="whitespace-nowrap py-1.5 text-right">
                                <button type="button" class="mr-1 rounded border border-slate-300 px-2 py-0.5 text-xs hover:bg-slate-50" @click="act('/isp/queue/retry', j.uuid)">Retry</button>
                                <button type="button" class="rounded border border-red-300 px-2 py-0.5 text-xs text-red-600 hover:bg-red-50" @click="act('/isp/queue/forget', j.uuid)">Delete</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
