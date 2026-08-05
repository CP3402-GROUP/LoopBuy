# LoopBuy

LoopBuy is a second-hand marketplace with a WordPress presentation layer and a standalone Go application backend. Marketplace data no longer belongs to the WordPress database: the Go API owns a separate MySQL 8.4 schema, Qdrant stores listing vectors, and a small FastAPI service provides transparent scam scoring and recommendation reranking.

## Architecture

```text
Browser
  -> WordPress theme + same-origin marketplace session bridge
       -> Go API
            -> MySQL 8.4 (accounts and marketplace source of truth)
            -> FastAPI / scikit-learn (scam score and reranking)
            -> OpenAI embeddings -> Qdrant
            -> Qwen chat completion over retrieved, revalidated listings
```

The WordPress/MariaDB database remains separate and is used only for WordPress itself. The supplied legacy `loopbuy_db.sql` is not imported over the application database; the reviewed, forward-only migrations under `backend/migrations` create the current schema.

## Implemented backend

- Go HTTP API with 61 documented operations for password/email-verification and Google OAuth login, refresh/logout, profiles, categories, listings and local image uploads, favourites, carts, conversations/messages, moderation, recommendations, and persisted or stateless AI chat.
- MySQL constraints, transactional writes, refresh-token rotation, ownership/role checks, privacy-safe account deletion (including owned listing media and DB-gated media reads), outbox-driven vector indexing, and optimistic listing revisions. A stale listing edit returns `409` instead of overwriting newer content or a moderation decision.
- Scam screening with a versioned TF-IDF/logistic-regression baseline plus explicit lexical risk signals. Only a contract-valid low-risk result may publish automatically; every other state is held for review.
- Recommendation retrieval from Qdrant with a content reranker and deterministic recent-listing fallback when embeddings are unavailable.
- RAG assistant that treats vector text as untrusted context and rehydrates only active, approved listings from MySQL before producing sources. Qwen is used when configured; otherwise the API can return a clearly marked deterministic fallback.
- OpenAPI 3.1 contract: [`backend/openapi.yaml`](backend/openapi.yaml). Detailed backend setup and endpoint map: [`backend/README.md`](backend/README.md).

## WordPress connection

The tracked MU plugins provide two explicit bridges:

- `loopbuy-backend-bridge.php` makes the Go catalogue authoritative for browse and product-detail pages, with demo fixtures used only when the backend is unreachable or malformed—not when a healthy catalogue is empty. API-owned `/media/...` images are exposed through a MIME-checked, bounded, streaming same-origin proxy.
- `loopbuy-marketplace-session.php` sends registration, login, refresh, profile, logout, and AI-assistant requests server-to-server. Marketplace access/refresh tokens are confined to bounded host-only `HttpOnly` cookies (`Secure` on HTTPS, `SameSite=Lax`); mutations also require a per-browser CSRF token and matching Origin/Referer. WordPress administrator authentication and capabilities remain independent.

The header, registration, login, profile, sell, and `/ai-assistant/` templates use the Go account. The AI Finder page calls the same-origin WordPress BFF, renders grounded listing sources without exposing JWTs to JavaScript, and explicitly labels deterministic fallback answers when Qwen or embeddings are unavailable. Sell creates listings and uploads up to ten images through the same server-side boundary. Cart, saved-items, messages, orders, and my-listings have not all been migrated yet; some still use demo/localStorage behavior. Orders, reviews, password recovery, and avatar upload are shown as unavailable rather than faked.

`deployment/provision-wordpress.php` idempotently creates the 15 required WordPress page records (including AI Finder, Privacy, and Terms), activates the LoopBuy theme, and configures permalinks. `deployment/deploy.sh` runs it after the containers are healthy and records a separate provisioning signature, so template routes do not require repeated wp-admin work. The WordPress installation itself must already have completed its one-time core installer.

## Local startup

Requirements: Docker Desktop with Compose v2.

```powershell
Copy-Item .env.example .env
# Replace every password/key placeholder in .env. JWT_SECRET must be at least 32 characters.
docker compose up -d --build
```

Defaults:

- WordPress: `http://localhost:8080`
- Go API: `http://localhost:8090`
- API readiness: `http://localhost:8090/health/ready`

The API is bound to host loopback and is reached publicly through the WordPress BFF; MySQL and Qdrant are internal-only. For loopback diagnostics only:

```powershell
docker compose -f compose.yaml -f compose.debug.yaml up -d
```

This exposes backend MySQL at `127.0.0.1:3307` and Qdrant at `127.0.0.1:6333` by default.

Provider configuration lives only in `.env`/server environment:

- `OPENAI_API_KEY`, embedding model/dimensions, and MySQL-backed global-hour/per-user-day request budgets;
- `QDRANT_API_KEY`, the private internal password shared by the API and Qdrant, plus collection/vector settings;
- `QWEN_API_KEY` for Qwen 3.5 Flash; the international HTTPS base URL and `qwen3.5-flash` model are defaults, with optional regional/model overrides;
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and an exact `GOOGLE_REDIRECT_URIS` allowlist;
- independent `BFF_SHARED_SECRET` for signed, privacy-preserving per-client rate-limit buckets between WordPress and Go;
- `RESEND_API_KEY`, verified `RESEND_FROM`, verification URL/TTL, and `RESEND_MAX_EMAILS_PER_HOUR`.

Listing images persist in the `listing_media_data` volume on the host server. `DEMO_SEED_ENABLED=true` idempotently creates three sample sellers and twelve listings with repository-owned local photos; repeated starts do not duplicate them or enqueue duplicate embedding work.

On a fresh host the normal deployment performs that seed automatically. To add or verify the catalogue later, run `bash ./deployment/seed-demo.sh`; see [`deployment/README.md`](deployment/README.md) for the guarded one-command workflow.

Never expose provider keys to WordPress JavaScript or commit `.env`.

## Verification

```powershell
Set-Location backend
gofmt -d ./cmd ./internal
go test ./...
go vet ./...

Set-Location ..
docker build --target test -t loopbuy-ml-test ./ml-service
docker run --rm --network none loopbuy-ml-test
npx --yes @redocly/cli@2.44.1 lint backend/openapi.yaml
docker compose --env-file .env.example config --quiet
```

Production deployment uses `deployment/deploy.sh`. It validates non-placeholder secrets, requires Qwen and OpenAI configuration, builds the two local images, waits for services, provisions WordPress routes, and records deployment state. Set `WORDPRESS_DEBUG=0` in production.
