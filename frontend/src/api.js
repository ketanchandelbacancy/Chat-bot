import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

/** Turn an axios error into a readable message. */
export function errorMessage(error) {
  const data = error.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || error.message || 'Something went wrong'
}

export const listDocuments = () => api.get('/documents').then((r) => r.data)

export const uploadDocument = (file, onProgress) => {
  const form = new FormData()
  form.append('file', file)
  return api
    .post('/documents', form, {
      onUploadProgress: (e) => onProgress?.(Math.round((e.loaded * 100) / (e.total || 1))),
    })
    .then((r) => r.data)
}

export const deleteDocument = (id) => api.delete(`/documents/${id}`)

export const reprocessDocument = (id) => api.post(`/documents/${id}/reprocess`).then((r) => r.data)

export const askQuestion = (question, conversationId) =>
  api.post('/chat', { question, conversation_id: conversationId }).then((r) => r.data)

export const chatHistory = (conversationId) => api.get(`/chat/${conversationId}`).then((r) => r.data)
