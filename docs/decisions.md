# Ilmora – Architecture decisions

Status: accepted and implemented.

## Stack

| Layer | Technology | Purpose |
| --- | --- | --- |
| Frontend | Vue 3 + TypeScript + Ionic | School, teacher and parent interfaces |
| Android / iOS | Capacitor | Native packaging |
| Backend | Laravel REST API | Permissions, fees, payroll, approvals, integrations |
| Main database | PostgreSQL | Source of truth for records and money |
| Offline + sync | Local store on the device + Ilmora's own sync endpoints (D11) | Local work, upload queue, scoped download |
| Background work | Redis + Laravel queues / scheduler | Reminders, vouchers, payroll drafts, imports |
| Platform admin | Filament | Super admin, schools, subscriptions, modules |
| Documents | Local PDF + server copies | Offline slips; finalized copies stored centrally |
| Files | S3-compatible storage | Photos, attachments, generated documents |
| Hosting | Ubuntu VPS + Nginx | API, workers, scheduler |

## D1. Offline capability is defined per action, not per screen

Each feature declares four levels. Other devices' changes are never visible until both have synced.

| Level | Meaning | Examples |
| --- | --- | --- |
| View | Read what was synced to this device | Student list, results, own payslips |
| Draft | Create/edit locally, nobody else sees it | Exam paper, payroll draft, worksheet |
| Submit | Queued; takes effect when the server accepts it | Attendance, marks, cash payment, leave request |
| Finalize | Server only, needs synced and locked inputs | Payroll approval, result publish, voucher run, fee rule change |

## D2. Versioned records, no silent overwrite

- Every syncable row has `version` (int). Clients send the version they edited from.
- **Mergeable tables** (profiles, contact details, timetable): server accepts if version matches; on mismatch, field-level merge when fields don't overlap, else conflict.
- **Protected tables** (fee structures, discounts, marks, salary structures, approvals, payroll): a mismatch is never auto-resolved. A row goes to `sync_conflicts`; an authorized user chooses.
- **Append-only tables** (payments, ledger entries, biometric punches, audit log, attendance events): never updated; corrections are new reversal/correction rows.

## D3. Money is idempotent and ledger-based

- Every financial command carries a client-generated UUID (`id`) → the server ignores retries of the same id.
- Structure: `invoices` → `invoice_lines`; `payments` → `payment_allocations` (which invoice lines a payment settles); `reversals`; `ledger_entries` (double-entry per account); `reconciliation_items`.
- Balances are always derived from ledger entries, never stored as an editable number.
- If two offline devices collect against the same balance, both payments are accepted (money was physically received). Any overpayment becomes credit and a `reconciliation_items` row flags it for the accountant ("pending reconciliation").

## D4. Receipt numbers are device-scoped

- Format: `{BRANCH}-{DEVICE}-{SEQ}` e.g. `B1-D07-000123`.
- Each registered device has its own sequence (`device_sequences`), so offline devices never collide.
- A receipt is never deleted or renumbered; cancellation = a reversal row with reason, user and time.

## D5. Payroll: drafts offline, approval online

- Drafts can be calculated on any device from synced data.
- Approval requires: attendance for the month locked on the server, all corrections resolved, salary inputs validated, and the device fully synced. The server recalculates and its figures are final.

## D6. Offline access expiry

- A disconnected device cannot receive a suspension or role change instantly.
- Each device grant has `offline_valid_until` (default 7 days, configurable per school, `OFFLINE_ACCESS_DAYS`).
- After expiry the app becomes read-only until it reconnects and revalidates. Logout or device revoke wipes the local database on next contact.

## D7. Devices download only what the user may see

Sync rules are per role:
- Teacher: their branch's classes they teach, those students, their own staff records.
- Parent: their children only, their family's invoices/payments.
- Accountant: finance data of the branches they are assigned.
- Principal / owner: their branch(es) or whole school.
- No device ever receives another school's rows.

## D8. Biometric processing handles real-world punches

Before computing deductions the engine:
1. Removes duplicates (same staff, punches within 2 minutes).
2. Assigns punches to a **shift day** using the shift window, so overnight shifts (e.g. 22:00–06:00) belong to the start date.
3. Single punch in a window → status `incomplete`, needs a correction request; never auto-marked absent or present.
4. Missed punch corrections are separate rows; raw punches are never edited.
5. Approved leave, holidays and weekly offs are applied first, then late / half-day / absent rules.

## D9. Branches from day one

`branch_id` exists on every branch-scoped table and in every authorization check from the first migration. A school with one campus simply has one branch.

## D10. Multi-school SaaS, super admin creates schools

Only the platform super admin (Filament panel) creates schools, their first owner account, plans and module switches. Every tenant table carries `school_id`; policies and sync rules filter by it.

## D11. Ilmora's own sync instead of PowerSync

PowerSync was the first choice, on condition that it passed validation on real Android, iPhone and browser devices. Its Capacitor SDK was still in beta, so the application was not committed to it. The app now syncs through two endpoints of its own, which keeps one code path on web, Android and iOS and needs no extra service to host.

- **On the device** (`apps/mobile/src/lib/store.ts`): every screen reads and writes a local store kept in IndexedDB. Each change is also added to an outbox.
- **Upload** (`POST /sync/upload`, `App\Services\Sync\SyncWriter`): the outbox is sent in batches. Every row carries the version it was edited from; the server answers per row with applied, merged, conflict or rejected (D2). Retries are safe because rows keep their device-generated UUID (D3).
- **Download** (`GET /sync/pull?since=`, `App\Services\Sync\Puller`): the server returns only the rows this user may hold (D7) that changed since the device's cursor. Local changes still waiting in the outbox stay on top.
- **Server-only actions** (D1 Finalize): voucher run, payroll draft, approval and payment, leave decisions, user management and everything in the platform console are API calls, never sync rows.
- **When**: a few seconds after a local change, every minute, when the device comes online and when the app returns to the foreground. Each cycle also revalidates the offline grant (D6).

Limits to know: deletes are not synced (records are marked left, cancelled or reversed instead), and a device holds its school's data in memory, which suits schools up to a few thousand students per device. Move the store to SQLite if a single device must hold much more.
