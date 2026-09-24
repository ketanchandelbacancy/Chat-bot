# Document Chatbot (RAG) — Laravel + Vue + MySQL + Gemini

Upload PDF / DOCX / TXT / MD / CSV files, then chat with an AI that answers **only from your documents**.

```
Vue 3 (Vite :5173) ──/api proxy──► Laravel 12 API (:8000) ──► Gemini API (free tier)
                                        │                      • gemini-embedding-001  (text → vectors)
                                        ▼                      • gemini-2.5-flash      (answers)
                                      MySQL
                          documents · document_chunks · chat_messages
```

**Upload:** file → extract text → split into ~1500-char chunks → Gemini embeddings → saved in MySQL (queue job)
**Chat:** question → embedding → cosine similarity (PHP) → top 5 chunks + chat history → Gemini → answer + sources

---

## 1. One-time setup

### a) Gemini API key
Create a free key at <https://aistudio.google.com/apikey>.

> ⚠️ On the free tier Google may use your prompts/files to improve its products. Don't upload confidential data.

### b) MySQL database + user
Ubuntu's MySQL `root` uses socket auth, so create a dedicated user:

```bash
sudo mysql
```
```sql
CREATE DATABASE rag_chatbot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rag_user'@'localhost' IDENTIFIED BY 'choose_a_password';
GRANT ALL PRIVILEGES ON rag_chatbot.* TO 'rag_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### c) Backend `.env`
Edit `backend/.env`:
```env
DB_DATABASE=rag_chatbot
DB_USERNAME=rag_user
DB_PASSWORD=choose_a_password

GEMINI_API_KEY=your_key_here
GEMINI_CHAT_MODEL=gemini-2.5-flash
GEMINI_EMBED_MODEL=gemini-embedding-001
```
If Google renames a model, just change it here (check names in AI Studio).

### d) Create tables
```bash
cd backend
php artisan config:clear
php artisan migrate
```

---

## 2. Run (3 terminals)

```bash
# Terminal 1 — API (serve.sh = php artisan serve + 25 MB upload limit from backend/php/uploads.ini)
cd backend && ./serve.sh

# Terminal 2 — queue worker (processes uploaded files)
cd backend && php artisan queue:work --timeout=900

# Terminal 3 — frontend
cd frontend && npm run dev
```

Open <http://localhost:5173> → upload a file → wait for **ready** → ask questions.

> After changing PHP code, restart `queue:work` (it keeps old code in memory).

---

## 3. API

| Method | Endpoint | Body | Purpose |
|---|---|---|---|
| GET | `/api/documents` | – | List documents with status + chunk count |
| POST | `/api/documents` | `file` (multipart) | Upload & queue processing |
| POST | `/api/documents/{id}/reprocess` | – | Retry a failed document |
| DELETE | `/api/documents/{id}` | – | Delete document + its chunks |
| POST | `/api/chat` | `{ question, conversation_id? }` | Ask a question (returns `answer`, `sources`, `conversation_id`) |
| GET | `/api/chat/{conversation_id}` | – | Chat history |

---

## 4. Where things live

| File | What it does |
|---|---|
| `backend/app/Services/GeminiService.php` | Calls Gemini: batch embeddings + answer generation, retries on 429 |
| `backend/app/Services/DocumentProcessor.php` | Text extraction (PDF, DOCX, TXT…) and chunking |
| `backend/app/Services/VectorSearch.php` | Cosine similarity over embeddings stored as JSON |
| `backend/app/Jobs/ProcessDocument.php` | Queue job: extract → chunk → embed → store |
| `backend/app/Http/Controllers/ChatController.php` | RAG prompt, chat history, error handling |
| `backend/app/Http/Controllers/DocumentController.php` | Upload / list / delete |
| `frontend/src/components/DocumentPanel.vue` | Upload + document list (auto-refreshes while processing) |
| `frontend/src/components/ChatWindow.vue` | Chat UI (markdown answers, sources, new chat) |

Tuning knobs: chunk size/overlap in `DocumentProcessor::chunk()`, top-K and `minScore` in `VectorSearch::topK()`, prompt in `ChatController::SYSTEM_PROMPT`.

---

## 5. Troubleshooting

| Problem | Fix |
|---|---|
| Document stuck on **pending** | `php artisan queue:work` isn't running |
| **failed**: "No readable text found" | Scanned/image PDF — needs OCR first |
| "rate limit reached" / 429 | Free-tier limit; wait a minute. Large files are embedded in batches with pauses |
| `GEMINI_API_KEY is not set` | Add the key to `backend/.env`, then `php artisan config:clear` and restart servers |
| "The POST data is too large" | PHP's `post_max_size` (default 8 MB) is smaller than the file. Start the API with `./serve.sh` (not `php artisan serve`); limits live in `backend/php/uploads.ini` |
| `Access denied for user` | Check DB user/password in `backend/.env` |
| Search gets slow (10k+ chunks) | Move vectors to MariaDB 11.7+ vector index or Postgres + pgvector |
