# PARISHHUB (PHP Edition) — Page Reference

Since this edition has no router, every URL is a literal file path. All pages are server-rendered HTML except `chatbot.php`, which returns JSON. Every POST handler validates a CSRF token before making any database change.

## Public Pages

| Path | Description |
|---|---|
| `index.php` | Landing page (announcements + services preview) |
| `about.php` | About the parish |
| `auth/login.php` | Login form (GET) + authenticate (POST) |
| `auth/register.php` | Registration form (GET) + create account (POST) |
| `auth/logout.php` | Destroys session, redirects to login |
| `install.php` | One-click database installer (see README) |
| `database/hash-password.php` | Bcrypt hash generator utility (CLI or browser) |
| `chatbot.php` | POST-only JSON endpoint: `{"message": "..."}` → `{"reply": "..."}` |

## Parishioner Pages (`parishioner/`) — requires role `Parishioner`

| Path | Description |
|---|---|
| `dashboard.php` | Stats, upcoming appointments, announcements |
| `services.php` | Browse active services |
| `book.php` | Booking form (GET, optional `?service_id=`) + submit (POST, multipart) |
| `appointments.php` | List own appointments |
| `appointment-detail.php?id=` | Detail view (GET) + cancel action (POST `action=cancel`) |
| `pay.php` | POST-only: submit payment for an approved appointment |
| `calendar.php` | View parish events + blocked dates |
| `announcements.php` | View published announcements |
| `profile.php` | View (GET) + edit (POST) own profile |
| `notifications.php` | View + auto mark-read notifications |

## Secretary Pages (`secretary/`) — requires role `Secretary` or `Admin`

| Path | Description |
|---|---|
| `dashboard.php` | Pending/today/week counts, recent requests |
| `appointments.php` | Filterable/searchable appointment queue (`?status=`, `?search=`) |
| `appointment-detail.php?id=` | Detail + actions: `approve`, `reject`, `assign_priest`, `reschedule` (all POST `action=`) |
| `calendar.php` | Manage events (`action=add_event`) + blocked dates (`action=block_date`) |
| `announcements.php` | List/create (`action=create`)/delete (`action=delete`) announcements |
| `parishioners.php` | Search/browse parishioners (`?search=`) |
| `services.php` | List + inline-edit services |
| `reports.php` | Appointment reports (by service, by status) |

## Treasurer Pages (`treasurer/`) — requires role `Treasurer` or `Admin`

| Path | Description |
|---|---|
| `dashboard.php` | Daily/weekly/monthly/yearly revenue, pending count |
| `payments.php` | Filterable/searchable payment list + manual recording (`action=manual`) |
| `payment-detail.php?id=` | Detail + verify action (POST `action=verify`) → auto-generates Official Receipt |
| `reports.php` | Revenue by month/method/service |

## Admin Pages (`admin/`) — requires role `Admin`

| Path | Description |
|---|---|
| `dashboard.php` | System-wide stats, users by role, recent activity |
| `users.php` | List + create (`action=create`) + change role/status/delete |
| `priests.php` | List + add (`action=add`) + update status (`action=status`) |
| `services.php` | List + add new service |
| `activity-logs.php` | Full audit trail (latest 200) |
| `settings.php` | View + update parish/system settings (key-value upsert) |
| `reports.php` | System-wide revenue + top services + completion stats |
| `backup.php` | phpMyAdmin-based backup/restore instructions |

---

## Auth & Session Model
- Native PHP sessions (`session_start()` in `config/config.php`), 1 session per browser.
- Passwords hashed with `password_hash()` (bcrypt, `$2y$` format).
- `$_SESSION['user']` holds `{ user_id, firstname, lastname, email, role_id, role_name }`.
- `$_SESSION['csrf_token']` — one token per session, embedded via `csrfField()` in every form, checked via `verifyCsrf()` on every POST handler (returns HTTP 419 and stops execution if missing/invalid).
- RBAC enforced per-page via `requireRole(...)` called at the very top of each protected file, before any output or query runs.
