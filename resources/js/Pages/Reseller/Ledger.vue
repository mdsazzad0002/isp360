<script setup>
import PortalLayout from '../../Layouts/PortalLayout.vue';
import ResellerStatement from '../../Components/Isp/ResellerStatement.vue';

defineProps({ reseller: { type: Object, required: true } });
</script>

<template>
    <PortalLayout :user-name="reseller.name" logout-url="/reseller/logout">
        <h1 class="mb-4 text-lg font-semibold text-slate-800">My Ledger</h1>
        <ResellerStatement endpoint="/reseller/get-ledger" />
    </PortalLayout>
</template>
