<p align="center"><img src="brand/ilmora-logo-full.png" alt="Ilmora" width="320"></p>

# Ilmora

Paperless, offline-first school management for Pakistani schools: multi-school SaaS with branches, attendance, fees with sibling discounts, exams, biometric staff attendance and payroll, on Android, iOS and web.

## Repository

| Path | What |
| --- | --- |
| `apps/api` | Laravel REST API: auth, devices, sync upload, finance, payroll, schedulers |
| `apps/mobile` | Vue 3 + TypeScript + Ionic app, packaged with Capacitor (Android/iOS) |
| `infra` | Docker Compose for Postgres, Redis, PowerSync; PowerSync config and sync streams |
| `docs/decisions.md` | Architecture decisions D1–D10 (offline levels, conflicts, money, devices, punches) |
| `docs/powersync-spike.md` | Validation tests S1–S10 that gate the PowerSync choice |
| `brand` | Logo files |

## Stack

Vue 3 + Ionic · Capacitor · Laravel · PostgreSQL · PowerSync (local SQLite) · Redis queues · Filament (super admin) · S3-compatible storage · Ubuntu VPS + Nginx.

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+, Docker.

```bash
# 1. Database, Redis, PowerSync
export POWERSYNC_JWT_SECRET=$(openssl rand -base64 32 | tr '+/' '-_' | tr -d '=')
docker compose -f infra/docker-compose.yml up -d

# 2. API
cd apps/api
cp .env.example .env            # put the same POWERSYNC_JWT_SECRET in .env
composer install
composer require laravel/sanctum
php artisan key:generate
php artisan migrate --seed      # do NOT publish Sanctum's migration; ours uses UUID owners
php artisan serve               # http://localhost:8000

# 3. App (browser)
cd ../mobile
cp .env.example .env
npm install
npm run dev                     # http://localhost:5173

# 4. Android / iOS
npm run cap:sync
npx cap open android            # Android Studio
npx cap add ios && npx cap open ios   # needs a Mac with Xcode
```

Demo logins (school code `demo`, password `password`): owner `03000000001`, teacher `03000000002`.
Super admin: `admin@ilmora.test` (Filament panel, next milestone).

## Tests

```bash
cd apps/api && php artisan test       # domain tests: punches, payment allocation, receipts, payroll
cd apps/mobile && npm run typecheck
```

## Status

Milestone 0 (foundation) is in this commit. The PowerSync spike (`docs/powersync-spike.md`) must pass on a real Android phone, iPhone and browser before feature modules are built on it. `@powersync/capacitor` is a beta package, which is one more reason to run the spike first.
