# PowerSync validation spike

**Goal:** prove PowerSync + Vue + Capacitor works on a real Android phone, a real iPhone and a desktop browser before the rest of Ilmora depends on it.

**Time box:** 5 working days. **Decision owner:** Taimoor.

## Setup

1. `docker compose -f infra/docker-compose.yml up -d`
2. `cd apps/api && composer install && php artisan migrate --seed && php artisan serve`
3. `cd apps/mobile && npm install && npm run dev` (browser), then `npx cap run android` / `npx cap run ios`
4. In the app, open **Sync lab** (bottom of the menu) and sign in as `03000000002` / `password`, school `demo`.

## Tests

| # | Test | Pass when |
| --- | --- | --- |
| S1 | Install + first sync on Android, iPhone, Chrome, Safari | Local DB opens, demo rows appear, app restart keeps data |
| S2 | Airplane mode: mark attendance for 30 students, kill app, reopen | All 30 rows still there, queue shows 30 pending |
| S3 | Reconnect | Queue drains in < 10 s, server rows match, no duplicates |
| S4 | Streams per role (owner, principal, teacher, parent) | Each device has only the rows its role allows; teacher nested subquery stream deploys |
| S5 | Conflict: two devices offline edit same student phone vs same fee structure | Student merges or conflicts per D2; fee structure always lands in `sync_conflicts` |
| S6 | Duplicate upload: replay the same payment op 3× | One payment, one allocation set, results `applied`, then `ignored_duplicate` |
| S7 | Volume: 2,000 students, 60 days attendance, 12 months invoices | First sync < 60 s on 4G, local queries < 100 ms on a mid-range Android |
| S8 | Token expiry and offline grant | Expired PowerSync token refreshes; after `offline_valid_until` app turns read-only |
| S9 | Revoke device from server | Next contact returns 410 and app wipes local DB |
| S10 | iOS background / low storage | No data loss after iOS kills the app in background |

## Decision

- All of S1–S6 pass on all three platforms → adopt PowerSync.
- S4 nested teacher stream fails → denormalize `teacher_ids` onto `enrollments` / attendance rows, retest.
- S1, S2 or S10 fail on iOS → evaluate fallback: Capacitor SQLite + our own change queue against `/api/v1/sync/upload` (the upload contract stays the same, so the backend does not change).

Record results in this file (date, device, version, pass/fail, notes).
