# LoopBuy deployment helpers

## Demo catalogue on a fresh host

The normal deployment already seeds the presentation catalogue when
`DEMO_SEED_ENABLED=true` (the Compose default). During API startup it:

1. runs all backend MySQL migrations;
2. copies the 12 repository-owned product photos from
   `wordpress/wp-content/themes/LoopBuy/images` into the persistent
   `listing_media_data` volume;
3. creates three non-login demo sellers and 12 active listings; and
4. registers each listing by a stable key in `demo_seed_listings`.

Therefore, a new server only needs this value in `.env` before the first normal
deployment:

```dotenv
DEMO_SEED_ENABLED=true
```

Then run:

```bash
./deployment/deploy.sh
```

If the stack was initially deployed with seeding disabled, or if only `.env`
was changed after the last successful deployment, use the explicit helper:

```bash
bash ./deployment/seed-demo.sh
```

The helper briefly recreates only the `api` container with seeding enabled and
then verifies the seed registry, listing rows, and local image URLs in backend
MySQL. It requires an already-running stack because it deliberately does not
start or change the database, Qdrant, ML, or WordPress services.

The verification expects the current 12-listing fixture set. If that fixture
set is intentionally expanded in code, update the script default at the same
time (or pass `EXPECTED_DEMO_LISTINGS` for that rollout).

Repeated runs are safe. Users and carts use unique keys, listings use the
`demo_seed_listings.seed_key` primary key, existing versioned media objects are
left untouched, and embedding outbox work is created only for a newly-created
demo listing. A normal restart with `DEMO_SEED_ENABLED=true` therefore does not
create duplicate users, listings, images, carts, or embedding events.

To keep a real production catalogue empty, set `DEMO_SEED_ENABLED=false` before
the first deployment. Disabling the flag later stops future seeding but does not
delete existing sample content.

Demo media keys are immutable and versioned. If a bundled photo is replaced in
the repository, its destination key in `backend/internal/media/demo_assets.go`
must also receive a new version suffix so the new bytes are copied safely.
