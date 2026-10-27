<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import CommandBlock from '../../Components/Isp/CommandBlock.vue';
import { useToast } from '../../lib/toast';
import { useApiError, fmtDateTime } from '../../lib/isp';
import { confirmDialog } from '../../lib/confirm';

defineOptions({ layout: AppLayout });
const toast = useToast();
const showError = useApiError();

const data = ref(null);
const running = ref(null);
const output = ref(null); // { label, text, ok }

const load = () => axios.post('/isp/get-system').then((r) => (data.value = r.data));
let timer;
onMounted(() => {
    load();
    timer = setInterval(load, 30000);
});
onBeforeUnmount(() => clearInterval(timer));

async function run(t) {
    if (t.command === 'migrate' && !(await confirmDialog({ title: 'Apply database updates now?', text: 'A full backup is taken first.', icon: 'question', confirmButtonText: 'Apply' }))) return;
    running.value = t.command;
    try {
        const { data: res } = await axios.post('/isp/system-run', { command: t.command });
        toast.success(res.message);
        output.value = { label: t.label, text: res.output, ok: true };
    } catch (err) {
        const res = err?.response?.data;
        if (res?.errors?.output !== undefined) output.value = { label: t.label, text: res.errors.output, ok: false };
        showError(err);
    } finally {
        running.value = null;
        load();
    }
}

// a run's state as one short phrase
function state(t) {
    if (!t.last) return { text: 'never ran', cls: 'text-slate-400' };
    if (t.last.finished_at === null) return { text: 'running…', cls: 'text-brand-600' };
    return t.last.exit_code === 0 ? { text: 'ok', cls: 'text-emerald-700' } : { text: `failed (code ${t.last.exit_code})`, cls: 'text-red-600' };
}
const seconds = (t) => (t.last?.finished_at ? Math.max(0, Math.round((new Date(t.last.finished_at) - new Date(t.last.started_at)) / 1000)) : null);
const SOURCE = { schedule: 'automatic', dashboard: 'Run now', auto: 'automatic' };
</script>

<template>
    <div class="space-y-3 p-4">
        <div v-if="!data" class="p-6 text-center text-slate-400">Loading…</div>
        <template v-else>
            <!-- the scheduler: the one thing set up outside the app -->
            <div class="rounded-lg border bg-white p-4 shadow-sm" :class="data.scheduler.running ? 'border-slate-200' : 'border-red-300'">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-base font-semibold text-slate-800">System &amp; Automation</h1>
                    <span v-if="data.scheduler.running" class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700"><i class="bi bi-check-circle"></i> Automatic tasks running · last tick {{ fmtDateTime(data.scheduler.last_beat) }}</span>
                    <span v-else class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700"><i class="bi bi-exclamation-triangle"></i> Automatic tasks are NOT running{{ data.scheduler.last_beat ? ` since ${fmtDateTime(data.scheduler.last_beat)}` : '' }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Everything below runs on its own. Use <b>Run now</b> to start a task immediately; nothing here needs a terminal.</p>
                <div v-if="!data.scheduler.running" class="mt-3 space-y-2 text-sm">
                    <p class="text-slate-700">Billing, suspensions, OLT polling and backups wait until the scheduler runs. Add this line <b>once</b> to the server's cron (cPanel → Cron Jobs → "Once per minute", or <code>crontab -e</code>). It turns green here within a minute.</p>
                    <CommandBlock :code="data.scheduler.cron_line" label="Cron job (once per minute)" />
                </div>
            </div>

            <div v-if="data.migrations.pending.length" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <b>{{ data.migrations.pending.length }} database update(s) waiting.</b>
                        <span v-if="data.migrations.auto && data.scheduler.running"> They are applied automatically within 5 minutes (after a backup).</span>
                        <span v-else> Apply them to finish the software update.</span>
                    </div>
                    <button type="button" :disabled="!!running" class="rounded-md bg-brand-500 px-3 py-1.5 text-sm text-white" @click="run({ command: 'migrate', label: 'Database updates' })">{{ running === 'migrate' ? 'Applying…' : 'Apply now' }}</button>
                </div>
                <div class="mt-1 font-mono text-xs text-amber-800">{{ data.migrations.pending.join(', ') }}</div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-start text-slate-600">
                            <th class="px-3 py-2 font-medium">Task</th>
                            <th class="px-3 py-2 font-medium">Runs</th>
                            <th class="px-3 py-2 font-medium">Last run</th>
                            <th class="px-3 py-2 font-medium">Result</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in data.tasks" :key="t.command" class="border-b border-slate-100">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ t.label }}</div>
                                <div class="text-xs text-slate-500">{{ t.what }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs">{{ t.when }}</td>
                            <td class="px-3 py-2 text-xs">
                                <div>{{ t.last ? fmtDateTime(t.last.started_at) : '—' }}</div>
                                <div v-if="t.last" class="text-slate-400">{{ SOURCE[t.last.source] || t.last.source }}<span v-if="seconds(t) !== null"> · {{ seconds(t) }} s</span></div>
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span :class="state(t).cls">{{ state(t).text }}</span>
                                <div v-if="t.last?.exit_code && t.last_ok_at" class="text-slate-400">last ok {{ fmtDateTime(t.last_ok_at) }}</div>
                                <button v-if="t.last?.output" type="button" class="text-brand-600 hover:underline" @click="output = { label: t.label, text: t.last.output, ok: t.last.exit_code === 0 }">output</button>
                            </td>
                            <td class="px-3 py-2 text-end">
                                <button type="button" :disabled="!!running" class="whitespace-nowrap rounded border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-50" @click="run(t)">
                                    <i class="bi bi-play-fill"></i> {{ running === t.command ? 'Running…' : 'Run now' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="output" class="rounded-lg border bg-white p-3 shadow-sm" :class="output.ok ? 'border-slate-200' : 'border-red-300'">
                <div class="mb-1 flex items-center justify-between text-sm">
                    <b>{{ output.label }}</b>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="output = null"><i class="bi bi-x-lg"></i></button>
                </div>
                <pre class="max-h-80 overflow-auto rounded bg-slate-100 p-2 font-mono text-xs text-slate-700">{{ output.text || '(no output)' }}</pre>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm shadow-sm">
                    <h2 class="mb-2 font-semibold text-slate-700">Background jobs (router pushes, SMS)</h2>
                    <div>Waiting: <b>{{ data.queue.waiting }}</b><span v-if="data.queue.oldest_wait_seconds"> · oldest {{ Math.round(data.queue.oldest_wait_seconds / 60) }} min</span></div>
                    <div>Failed: <b :class="data.queue.failed ? 'text-red-600' : ''">{{ data.queue.failed }}</b> <a href="/isp/queue" class="ms-1 text-brand-600 hover:underline">details</a></div>
                    <p class="mt-1 text-xs text-slate-500">
                        Queue: {{ data.queue.driver }}.
                        <template v-if="data.queue.driver === 'sync'">Jobs run at once inside each request.</template>
                        <template v-else-if="data.queue.worked_by_scheduler">The scheduler works the queue every minute.</template>
                        <template v-else-if="data.queue.driver === 'database'">If no worker picks jobs up within 3 minutes, the scheduler works them itself.</template>
                    </p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 text-sm shadow-sm">
                    <h2 class="mb-2 font-semibold text-slate-700">Server</h2>
                    <div>PHP {{ data.php }} · timezone {{ data.timezone }}</div>
                    <div>Database updates: {{ data.migrations.auto ? 'applied automatically (backup first)' : 'applied from this page' }}</div>
                    <p class="mt-1 text-xs text-slate-500">Problems found by the automatic checks (scheduler stopped, ledger mismatch, OLT or PON down) show as a red banner for admins and are e-mailed to ISP_ALERT_EMAIL.</p>
                </div>
            </div>
        </template>
    </div>
</template>
