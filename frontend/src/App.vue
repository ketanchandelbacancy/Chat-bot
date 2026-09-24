<script setup>
import { ref } from 'vue'
import ChatWindow from './components/ChatWindow.vue'
import DocumentPanel from './components/DocumentPanel.vue'

const readyCount = ref(0)
</script>

<template>
  <main class="layout">
    <DocumentPanel @ready-count="readyCount = $event" />
    <ChatWindow :ready-count="readyCount" />
  </main>
</template>
