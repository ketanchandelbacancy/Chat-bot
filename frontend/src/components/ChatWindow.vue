<script setup>
import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { nextTick, onMounted, ref } from 'vue'
import { askQuestion, chatHistory, errorMessage } from '../api'

defineProps({ readyCount: { type: Number, default: 0 } })

const STORAGE_KEY = 'rag-conversation-id'

const messages = ref([])
const question = ref('')
const loading = ref(false)
const conversationId = ref(localStorage.getItem(STORAGE_KEY))
const scroller = ref(null)

const render = (text) => DOMPurify.sanitize(marked.parse(text ?? ''))

async function scrollToBottom() {
  await nextTick()
  scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' })
}

async function send() {
  const q = question.value.trim()
  if (!q || loading.value) return

  messages.value.push({ role: 'user', content: q })
  question.value = ''
  loading.value = true
  scrollToBottom()

  try {
    const data = await askQuestion(q, conversationId.value)
    conversationId.value = data.conversation_id
    localStorage.setItem(STORAGE_KEY, data.conversation_id)
    messages.value.push({ role: 'assistant', content: data.answer, sources: data.sources })
  } catch (e) {
    messages.value.push({ role: 'assistant', content: `⚠️ ${errorMessage(e)}`, error: true })
  } finally {
    loading.value = false
    scrollToBottom()
  }
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    send()
  }
}

function newChat() {
  localStorage.removeItem(STORAGE_KEY)
  conversationId.value = null
  messages.value = []
}

onMounted(async () => {
  if (!conversationId.value) return
  try {
    messages.value = await chatHistory(conversationId.value)
    scrollToBottom()
  } catch {
    newChat()
  }
})
</script>

<template>
  <section class="chat">
    <header class="chat-header">
      <h1>Document Chatbot</h1>
      <button class="secondary" @click="newChat">New chat</button>
    </header>

    <div ref="scroller" class="messages">
      <div v-if="!messages.length" class="welcome">
        <p v-if="readyCount">Ask anything about your {{ readyCount }} uploaded document(s).</p>
        <p v-else>Upload a document on the left, wait until it's <b>ready</b>, then ask a question.</p>
      </div>

      <div v-for="(m, i) in messages" :key="i" class="message" :class="[m.role, { error: m.error }]">
        <div v-if="m.role === 'assistant'" class="bubble markdown" v-html="render(m.content)" />
        <div v-else class="bubble">{{ m.content }}</div>
        <div v-if="m.sources?.length" class="sources">
          <span v-for="s in m.sources" :key="s" class="chip">📄 {{ s }}</span>
        </div>
      </div>

      <div v-if="loading" class="message assistant">
        <div class="bubble typing"><span /><span /><span /></div>
      </div>
    </div>

    <form class="composer" @submit.prevent="send">
      <textarea
        v-model="question"
        rows="1"
        placeholder="Ask a question about your documents…  (Enter to send, Shift+Enter for new line)"
        @keydown="onKeydown"
      />
      <button type="submit" :disabled="loading || !question.trim()">Send</button>
    </form>
  </section>
</template>
