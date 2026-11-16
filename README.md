<p align="center"><img src="brand/ilmora-logo-full.png" alt="Ilmora" width="320"></p>

# Ilmora

Paperless school management for Pakistani schools, online and offline. One platform serves many schools: the super admin creates each school, and the school's staff and parents use the same app on web, Android and iOS.

## What is in it

| Area | Screens |
| --- | --- |
| Platform (super admin) | Schools, plans, create school, suspend / read-only / activate |
| Students | Admissions, families and siblings, student profile, classes and sections |
| Attendance | Daily marking (offline), monthly sheet, absence messages to parents |
| Fees | Monthly voucher run, sibling discount, scholarships, late fee, collection with device receipt numbers, reversals, defaulters, day book |
| Exams | Exams, marks entry, class result with positions, result cards |
| Exam papers | Teacher writes, examiner reviews, principal approves, then print |
| Academics | Syllabus coverage, homework, worksheets and diary |
| Staff | Staff records, attendance (biometric or manual), leave requests and approval |
| Payroll | Draft from attendance, approval that locks the month, payslips |
| Communication | WhatsApp / SMS queue, notices, one-tap WhatsApp links |
| Parent app | Children, attendance, fee vouchers and receipts, results, homework, notices |
| Settings | Fee setup, logins and roles, holidays, device and sync status |

Roles: owner, principal, admin officer, accountant, examiner, teacher, parent. A teacher's device holds only their own classes; a parent's only their own children (`docs/decisions.md` D7).

## Repository

| Path | What |
| --- | --- |
| `apps/api` | Laravel 13 API on PostgreSQL: auth and devices, sync, fees ledger, payroll, messaging, platform console, Filament admin at `/admin` |
| `apps/mobile` | Vue 3 + TypeScript + Ionic app, packaged with Capacitor for Android and iOS |
| `infra` | Docker Compose for local PostgreSQL and Redis |
| `docs/decisions.md` | Architecture decisions D1–D11 |
| `docs/deploy.md` | Putting it on an Ubuntu VPS with Nginx |
| `brand` | Logo files |

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+, PostgreSQL 16 (or Docker).

```bash
# 1. Database
docker compose -f infra/docker-compose.yml up -d

# 2. API
cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder   # full demo school
php artisan serve                                # http://localhost:8000

# 3. App in the browser
cd ../mobile
cp .env.example .env                             # VITE_API_URL=http://localhost:8000/api/v1
npm install
npm run dev                                      # http://localhost:5173

# 4. Android / iOS
npm run cap:sync
npx cap open android                             # Android Studio
npx cap add ios && npx cap open ios              # needs a Mac with Xcode
```

### Demo logins

School code `demo`, password `password`.

| Role | Login |
| --- | --- |
| Owner | 03000000001 |
| Principal | 03000000003 |
| Accountant | 03000000004 |
| Admin officer | 03000000005 |
| Examiner | 03000000006 |
| Teacher | 03000000002 |
| Parent | 03000000007 |
| Super admin | `admin@ilmora.test` (Super admin tab on the login screen, or `/admin`) |

The demo school has 130 students in 87 families, 18 staff, four months of vouchers and payments, two exams, a paid payroll and message history. A second school, `alnoor`, is empty.

## Demo without a server

```bash
cd apps/mobile
npm run build:demo        # dist-demo/index.html: one file, sample data, no server
```

Open the file in any browser, or host it anywhere. Everything runs on the device with the same screens and rules; nothing is sent to a server. `npm run demo:export` writes the same data set for the Laravel `DemoSeeder`, so the demo and a seeded server show the same school.

## Tests

```bash
cd apps/api && createdb ilmora_test && vendor/bin/phpunit   # 42 tests on PostgreSQL
cd apps/mobile && npm test && npm run typecheck             # 10 rule tests + type check
```

## Production

See `docs/deploy.md`. In short: `php artisan migrate --force`, then `php artisan ilmora:install --email=... --password=...` to create the plans and your super admin, then create schools from the platform console.
