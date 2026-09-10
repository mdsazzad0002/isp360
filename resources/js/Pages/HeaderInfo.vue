<script setup>
import { usePage } from '@inertiajs/vue3';

const company = usePage().props.company;
</script>

<template>
    <div class="mb-2">
        <div class="flex gap-2 rounded-lg border border-slate-400 p-2">
            <div class="w-1/6">
                <img :src="company?.logo || '/noImage.jpg'" class="h-full w-full rounded shadow" />
            </div>
            <div class="w-5/6">
                <h4 class="m-0 text-lg font-semibold">{{ company?.title }}</h4>
                <address class="m-0 not-italic"><strong>Mobile: </strong>{{ company?.phone }}</address>
                <address class="m-0 not-italic" v-html="company?.address"></address>
            </div>
        </div>
    </div>
</template>
