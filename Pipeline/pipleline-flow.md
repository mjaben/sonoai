# SonoAI — Full Pipeline Review

> Last reviewed: 2026-04-07 | Status: Live & Operational

---

## Pipeline Overview

```
[Trainer]  →  [Knowledge Base Admin]  →  [Embedding Engine]  →  [MySQL + Redis]
                                                                        ↓
[User]  →  [Chat Frontend]  →  [REST API]  →  [RAG Engine]  →  [AI Provider]
                                                                        ↓
                                                              [Streaming Response]
                                                              [Lightbox Image Viewer]
```

---

## Phase 1 — Data Ingestion (Admin / Trainer)

**File:** `includes/admin/KnowledgeBase.php` + `KnowledgeBaseAjax.php`

The trainer feeds clinical knowledge into the system via the Admin Knowledge Base panel.

### What happens:
1. Trainer fills out a structured form: text content, source name, country, topic slug, mode (`guideline` or `research`), and optionally attaches clinical images.
2. When a **clinical image** is attached:
   - The trainer provides a **Clinical Label** (e.g. *"Gallbladder Sludge"*) before uploading.
   - A custom AJAX handler (`sonoai_kb_upload_img`) intercepts the file.
   - The file is **renamed** to the sanitized label (e.g. `gallbladder-sludge.jpg`).
   - It is stored in a **structured, isolated directory**: `wp-content/uploads/sonoai-Clinical-img-lib/YYYY/MM/`.
   - The returned URL is stored in the training data as `{ url, label }`.
3. On save, the content (text + image metadata) is passed to the Embedding Engine.

---

## Phase 2 — Embedding & Storage

**File:** `includes/Embedding.php` + `includes/RedisManager.php`

### Vector Generation:
1. The content text is passed to `Embedding::split_into_chunks()`.
2. **Medical-aware structural chunking** is applied — content is split at logical block boundaries (`Procedure:`, `SITM:`, `Level N`, `Required For:`) rather than by raw character count. Overlap of 100 chars is maintained across chunks.
3. Each chunk is sent to `AIProvider::generate_embedding()` which calls the configured AI provider (Gemini/OpenAI) to generate a float vector.

### Dual Storage:
4. Each chunk + its vector is written to **MySQL** (`wp_sonoai_embeddings`) with full metadata: `post_id`, `post_type`, `chunk_text`, `embedding` (JSON), `mode`, `topic_slug`, `country`, `source_title`, `source_url`, `image_urls`.
5. Simultaneously, the vector is **cached in Redis** under the key `sonoai:vector:{mode}:{knowledge_id}:{chunk_index}` with a 7-day TTL for high-speed retrieval.
6. Old chunks for the same post (different `knowledge_id`) are automatically pruned from MySQL.

---

## Phase 3 — Chat Request (Frontend)

**File:** `assets/js/chat.js` + `templates/chat.php`

1. The user types a query in the chat interface and submits it.
2. The current **Mode** (`Guideline` or `Research`) is included in the request.
3. The request is sent as a `POST` to the REST API endpoint `/wp-json/sonoai/v1/chat` with streaming enabled (`stream=1`).
4. The frontend opens a streaming reader (`ReadableStream`) to handle SSE events.

---

## Phase 4 — RAG Context Assembly

**File:** `includes/RAG.php`

This is the intelligence layer. Before the AI generates a response, RAG builds the full context.

### Steps:
1. **Memory Recall** — If a `session_uuid` is provided, the last 3 messages are retrieved from Redis (`sonoai:memory:{uuid}`). For short/ambiguous queries (< 30 chars), the query is expanded using the last conversation turn.
2. **Semantic Vector Search (Phase 1: Redis)** — The query is embedded and compared against all cached vectors in Redis for the current mode. Returns results immediately if found.
3. **MySQL Fallback (Phase 2)** — If Redis is empty or unavailable, all embeddings for the current provider/mode are loaded from MySQL and cosine similarity is computed in PHP.
4. **Image Mapping** — Each matching chunk's `image_urls` array is iterated. Each image gets a deterministic ID (`IMG_01`, `IMG_02`, etc.) which the AI is instructed to use via the `:::image|IMG_ID|Label:::` citation tag.
5. **Prompt Assembly** — A structured system prompt is built with:
   - Base persona + out-of-domain guardrails
   - Mode-specific preamble (Research = evidence + citations / Guideline = protocols + country)
   - Conversation history block (`<CONVERSATION_CONTEXT>`)
   - `<KNOWLEDGE_BASE>` block with source headings, chunk text, image references
   - Mandatory `:::sources` block format instruction

---

## Phase 5 — AI Response (Streaming)

**File:** `includes/AIProvider.php` + `includes/api/RestAPI.php`

1. The assembled prompt + conversation history are sent to the configured AI provider (Gemini or OpenAI).
2. The response is **streamed token-by-token** via SSE back to the client using `event: chunk` payloads.
3. A `event: meta` event is emitted first, containing:
   - `session_uuid`
   - `mode`
   - `context_images` (the `IMG_ID → {url, label}` map for the frontend lightbox)
   - `is_new_session` flag
4. After the stream completes, the full response is stored in MySQL (`Chat::add_message`) and the user/assistant turns are appended to Redis memory (`store_memory`).
5. Unanswered queries (matching the fallback phrase) are logged to `sonoai_query_logs` for trainer review.

---

## Phase 6 — Response Rendering (Frontend)

**File:** `assets/js/chat.js` + `assets/css/chat.css`

1. The frontend's `markdownToHtml()` parser processes the AI's response.
2. **Custom fence blocks** are parsed and rendered:
   - `:::grid ... :::` → Clinical metric cards (2-column layout)
   - `:::checklist ... :::` → Checkmarked protocol steps
   - `:::sources ... :::` → Clickable citation pills
   - `:::image|URL|Label:::` → **Interactive clinical image cards** with `sonoai-zoomable-img` class
3. `## Headings` are rendered as `sonoai-section` cards with a labelled header bar.
4. When the user clicks any `sonoai-zoomable-img`, the **Clinical Diagnostic Lightbox** opens:
   - All images cited in the current conversation are indexed at click-time.
   - The lightbox shows the image full-screen with a clinical label and position counter (e.g. *3 / 12*).
   - Users can navigate with `← →` keyboard arrows or on-screen `‹ ›` buttons.
   - Pressing `Escape` or clicking the backdrop closes it.

---

## Data Architecture Summary

| Layer | Technology | Purpose |
|---|---|---|
| **File Storage** | WP Uploads / `sonoai-Clinical-img-lib` | Clinical image binary storage |
| **Vector DB (Primary)** | Redis (Predis) | Sub-ms semantic search, conversation memory |
| **Vector DB (Fallback)** | MySQL (`sonoai_embeddings`) | Persistent vector + metadata store |
| **Sessions** | MySQL (`sonoai_sessions`) | Chat history, user ownership |
| **Saved Responses** | MySQL (`sonoai_saved_responses`) | Clinician bookmarks |
| **Feedback** | MySQL (`sonoai_feedback`) | Up/down votes + comments |
| **Query Logs** | MySQL (`sonoai_query_logs`) | Unanswerable queries for re-training |
| **AI Provider** | Gemini / OpenAI (configurable) | Embeddings + chat completions |

---

## Known Gaps & Improvement Areas

| Area | Issue | Priority |
|---|---|---|
| **Image Citation** | `:::image|IMG_ID|Label:::` uses an ID, but the ID→URL map is rebuilt per-session. Images stored to disk are stable, but session images object could be persisted to the DB message. | Medium |
| **Redis Search Scale** | `search_vectors()` uses a brute-force `KEYS` scan + loop — not suitable beyond ~5k vectors. Should migrate to RediSearch `FT.SEARCH` for vector indexing at scale. | High |
| **Image Deletion** | No "Delete Image" button in the KB repeater. Orphaned files may accumulate in `sonoai-Clinical-img-lib`. | Low |
| **Re-training Trigger** | After saving KB items, trainers must manually trigger re-embedding. An auto-embed-on-save hook would streamline this. | Medium |
| **Lightbox Persistence** | The lightbox image list is built from DOM at click-time and resets between page loads. If the session is re-opened, images already rendered in the bubble are correctly captured. | Low |