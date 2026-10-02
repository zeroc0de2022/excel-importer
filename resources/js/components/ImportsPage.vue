<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from '../api';

const props = defineProps({
    sampleUrl: String,
    maxFileMb: String,
});

const imports = ref([]);
const fileInput = ref(null);
const uploading = ref(false);
const uploadError = ref(null);
const live = ref(false);
let pollTimer = null;

const isFinished = (item) => item.status === 'completed' || item.status === 'failed';
const active = computed(() => imports.value.filter((item) => !isFinished(item)));

async function loadImports() {
    imports.value = (await api('/api/imports')).data;
}

async function refreshImport(id) {
    const { data } = await api(`/api/imports/${id}`);
    const index = imports.value.findIndex((item) => item.id === id);

    if (index === -1) {
        imports.value.unshift(data);
    } else {
        imports.value[index] = data;
    }
}

async function upload() {
    const file = fileInput.value.files[0];
    if (!file) {
        uploadError.value = 'Choose an .xlsx file first.';
        return;
    }

    const form = new FormData();
    form.append('file', file);

    uploading.value = true;
    uploadError.value = null;

    try {
        const { data } = await api('/api/imports', { method: 'POST', body: form });
        imports.value.unshift(data);
        fileInput.value.value = '';
    } catch (error) {
        uploadError.value = error.message;
    } finally {
        uploading.value = false;
    }
}

// Live update from a RowsCreated event (sent once per 1000-row chunk)
function applyProgress(event) {
    const item = imports.value.find((i) => i.id === event.importId);
    if (!item) {
        refreshImport(event.importId);
        return;
    }

    item.processed_rows = event.processed;
    item.failed_rows = event.failed;
    item.imported_rows = event.processed - event.failed;
    if (item.total_rows) {
        item.progress = Math.min(100, Math.floor((event.processed * 100) / item.total_rows));
    }
}

onMounted(() => {
    loadImports();

    window.Echo.channel('imports')
        .listen('.rows.created', applyProgress)
        .listen('.import.finished', (event) => refreshImport(event.importId));

    const connection = window.Echo.connector.pusher.connection;
    live.value = connection.state === 'connected';
    connection.bind('state_change', (states) => (live.value = states.current === 'connected'));

    // Polling fallback: used when the WebSocket is down, and while the file is still
    // being read (the total row count isn't known yet and isn't part of the event).
    pollTimer = setInterval(() => {
        active.value
            .filter((item) => !live.value || item.total_rows === null)
            .forEach((item) => refreshImport(item.id));
    }, 2000);
});

onUnmounted(() => {
    clearInterval(pollTimer);
    window.Echo.leave('imports');
});

const statusClass = {
    pending: 'bg-slate-100 text-slate-700',
    processing: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-rose-100 text-rose-800',
};

const formatDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const number = (n) => (n ?? 0).toLocaleString();
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold">Upload an .xlsx file</h1>
        <p class="mt-1 text-sm text-slate-500">
            Columns: <code>id</code>, <code>name</code>, <code>date</code> (d.m.Y); the first row is a header.
            Up to {{ maxFileMb }} MB.
            <a :href="sampleUrl" class="text-indigo-600 hover:underline">Download a sample file</a>.
        </p>

        <form class="mt-4 flex flex-wrap items-center gap-3" @submit.prevent="upload">
            <input
                ref="fileInput"
                type="file"
                accept=".xlsx"
                class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm hover:file:bg-slate-200"
            />
            <button
                type="submit"
                :disabled="uploading"
                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ uploading ? 'Uploading…' : 'Start import' }}
            </button>
        </form>
        <p v-if="uploadError" class="mt-2 text-sm text-rose-600">{{ uploadError }}</p>
    </section>

    <section class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Import history</h2>
            <span class="flex items-center gap-2 text-xs text-slate-500">
                <span class="h-2 w-2 rounded-full" :class="live ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                {{ live ? 'Live updates' : 'Polling' }}
            </span>
        </div>

        <p v-if="imports.length === 0" class="mt-4 text-sm text-slate-500">No imports yet.</p>

        <ul class="mt-4 space-y-3">
            <li v-for="item in imports" :key="item.id" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ item.file_name }}</p>
                        <p class="text-xs text-slate-500">{{ formatDate(item.created_at) }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a v-if="item.report_url" :href="item.report_url" class="text-sm text-indigo-600 hover:underline">
                            result.txt
                        </a>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[item.status]">
                            {{ item.status }}
                        </span>
                    </div>
                </div>

                <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                        class="h-full rounded-full bg-indigo-500 transition-all duration-300"
                        :class="{ 'animate-pulse w-full opacity-30': item.progress === null && !isFinished(item) }"
                        :style="item.progress !== null ? { width: item.progress + '%' } : {}"
                    ></div>
                </div>

                <p class="mt-2 text-xs text-slate-600">
                    <template v-if="item.total_rows === null && !isFinished(item)">Reading the file…</template>
                    <template v-else>
                        {{ number(item.processed_rows) }} / {{ number(item.total_rows) }} rows processed ·
                        {{ number(item.imported_rows) }} imported ·
                        {{ number(item.failed_rows) }} with errors or duplicates
                    </template>
                </p>
                <p v-if="item.error" class="mt-1 whitespace-pre-line text-xs text-rose-600">{{ item.error }}</p>
            </li>
        </ul>
    </section>
</template>
