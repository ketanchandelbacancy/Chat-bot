<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { deleteDocument, errorMessage, listDocuments, reprocessDocument, uploadDocument } from '../api'

const emit = defineEmits(['ready-count'])

const documents = ref([])
const uploading = ref(false)
const progress = ref(0)
const error = ref('')
const dragging = ref(false)
const fileInput = ref(null)
let timer = null

const ACCEPT = '.pdf,.docx,.txt,.md,.csv'
const MAX_BYTES = 20 * 1024 * 1024 // keep in sync with DocumentController (max:20480)

async function refresh() {
  try {
    documents.value = await listDocuments()
    emit('ready-count', documents.value.filter((d) => d.status === 'ready').length)
  } catch (e) {
    error.value = errorMessage(e)
  }
  // Keep polling while something is still being processed.
  clearTimeout(timer)
  if (documents.value.some((d) => d.status === 'pending' || d.status === 'processing')) {
    timer = setTimeout(refresh, 3000)
  }
}

async function upload(files) {
  error.value = ''
  for (const file of files) {
    if (file.size > MAX_BYTES) {
      error.value = `${file.name}: file is ${formatSize(file.size)}, the limit is 20 MB.`
      continue
    }
    uploading.value = true
    progress.value = 0
    try {
      await uploadDocument(file, (p) => (progress.value = p))
    } catch (e) {
      error.value = `${file.name}: ${errorMessage(e)}`
    }
  }
  uploading.value = false
  if (fileInput.value) fileInput.value.value = ''
  refresh()
}

function onDrop(e) {
  dragging.value = false
  upload([...e.dataTransfer.files])
}

async function remove(doc) {
  if (!confirm(`Delete "${doc.name}"?`)) return
  await deleteDocument(doc.id)
  refresh()
}

async function retry(doc) {
  await reprocessDocument(doc.id)
  refresh()
}

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

onMounted(refresh)
onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <aside class="panel">
    <h2>Knowledge base</h2>

    <label
      class="dropzone"
      :class="{ dragging }"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="onDrop"
    >
      <input ref="fileInput" type="file" multiple :accept="ACCEPT" hidden @change="upload([...$event.target.files])" />
      <span v-if="uploading">Uploading… {{ progress }}%</span>
      <span v-else><strong>Click or drop files</strong><br /><small>PDF, DOCX, TXT, MD, CSV · max 20 MB</small></span>
    </label>

    <p v-if="error" class="error">{{ error }}</p>

    <ul class="docs">
      <li v-if="!documents.length" class="empty">No documents yet.</li>
      <li v-for="doc in documents" :key="doc.id">
        <div class="doc-main">
          <span class="doc-name" :title="doc.name">{{ doc.name }}</span>
          <small>
            {{ formatSize(doc.size) }}
            <template v-if="doc.status === 'ready'"> · {{ doc.chunks_count }} chunks</template>
          </small>
          <small v-if="doc.status === 'failed'" class="error">{{ doc.error }}</small>
        </div>
        <span class="badge" :class="doc.status">{{ doc.status }}</span>
        <button v-if="doc.status === 'failed'" class="icon" title="Retry" @click="retry(doc)">↻</button>
        <button class="icon" title="Delete" @click="remove(doc)">✕</button>
      </li>
    </ul>
  </aside>
</template>
