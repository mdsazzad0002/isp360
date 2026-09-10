<script setup>
import { reactive, ref } from 'vue';
import axios from 'axios';
import AppLayout from '../../Layouts/AppLayout.vue';
import ToggleRow from '../../Components/ToggleRow.vue';
import { useToast } from '../../lib/toast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    settings: { type: Object, required: true },
});

const toast = useToast();
const setting = reactive({
    multi_branch_status: props.settings.multi_branch_status,
});
const onProgress = ref(false);

async function updateBranchManage() {
    onProgress.value = true;
    try {
        const res = await axios.post('/update-branchManage', { ...setting });
        if (res.data.status) {
            toast.success(res.data.message);
            window.location.reload();
        }
    } catch (err) {
        // The toggle switch flips itself the instant it's clicked (see
        // ToggleRow), before this request even starts — so on a rejected
        // save it has to be snapped back to the last saved value here.
        // Otherwise it's left showing "on" even though nothing was actually
        // saved, and only reverts (looking like it "forgot") on the next reload.
        setting.multi_branch_status = props.settings.multi_branch_status;

        if (err.response?.status === 422) {
            const data = err.response.data;
            if (data.errors) {
                Object.values(data.errors).forEach((messages) => messages.forEach((m) => toast.error(m)));
            } else if (data.message) {
                toast.error(data.message);
            }
        } else {
            toast.error('Something went wrong. Please try again.');
        }
    } finally {
        onProgress.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-2xl p-4">
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2.5">
                <h3 class="text-sm font-semibold text-white">Branch Manage</h3>
            </div>
            <form @submit.prevent="updateBranchManage" class="divide-y divide-slate-100 p-4">
                <ToggleRow
                    v-model="setting.multi_branch_status"
                    label="Enable Multi Branch"
                    hint="When enabled, the branch switcher shows up in the topbar so admins can switch between branches"
                />
                <div class="pt-3 text-right">
                    <button type="submit" :disabled="onProgress" class="rounded-md bg-gradient-to-r from-brand-500 to-brand-600 px-6 py-2 text-sm font-medium text-white shadow-sm cursor-pointer disabled:opacity-50">
                        Update Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
