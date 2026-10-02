<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api';

const groups = ref({});
const meta = ref(null);
const page = ref(1);
const loading = ref(false);

async function load(number) {
    loading.value = true;
    try {
        const response = await api(`/api/rows?per_page=100&page=${number}`);
        // An empty page comes back as [] instead of {}
        groups.value = Array.isArray(response.data) ? {} : response.data;
        meta.value = response.meta;
        page.value = number;
    } finally {
        loading.value = false;
    }
}

onMounted(() => load(1));
</script>

<template>
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Imported rows grouped by date</h1>
        <span v-if="meta" class="text-sm text-slate-500">{{ meta.total.toLocaleString() }} rows</span>
    </div>

    <p v-if="meta && meta.total === 0" class="mt-4 text-sm text-slate-500">Nothing imported yet.</p>

    <div class="mt-4 space-y-4" :class="{ 'opacity-50': loading }">
        <section v-for="(rows, date) in groups" :key="date" class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <h2 class="border-b border-slate-100 px-4 py-2 text-sm font-semibold">
                {{ date }} <span class="font-normal text-slate-400">· {{ rows.length }}</span>
            </h2>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="border-b border-slate-50 last:border-0">
                        <td class="w-48 px-4 py-1.5 font-mono text-slate-500">{{ row.id }}</td>
                        <td class="px-4 py-1.5">{{ row.name }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <nav v-if="meta && meta.last_page > 1" class="mt-6 flex items-center justify-between text-sm">
        <button
            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40"
            :disabled="page <= 1 || loading"
            @click="load(page - 1)"
        >
            ← Previous
        </button>
        <span class="text-slate-500">Page {{ page }} of {{ meta.last_page.toLocaleString() }}</span>
        <button
            class="rounded-md border border-slate-200 bg-white px-3 py-1.5 disabled:opacity-40"
            :disabled="page >= meta.last_page || loading"
            @click="load(page + 1)"
        >
            Next →
        </button>
    </nav>
</template>
