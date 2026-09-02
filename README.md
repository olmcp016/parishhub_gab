# ⛪ PARISHHUB (PHP Edition)
### Web-Based Mass Intention & Parish Service Management System

This is the **PHP + MySQL/phpMyAdmin** version of PARISHHUB — a full-stack web application that digitizes parish administration: Mass Intentions, Baptism, Wedding, Funeral, Blessing, Confirmation, First Communion, announcements, calendar, online payments, official receipts, reports, and a built-in chatbot — for four user roles: **Parishioner, Secretary, Treasurer, and Admin (Parish Priest)**.

Theme: **Warm Gold & Dark Brown** — fully responsive (desktop, tablet, mobile).

Built for a standard **XAMPP / WAMP / MAMP + phpMyAdmin** stack — no frameworks, no Composer dependencies, no build step. Unzip it into your web server's document root and go.

---

## 🧱 Tech Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8+ (tested on 8.3) |
| Database | MySQL 8 / MariaDB, managed via **phpMyAdmin** |
| Data access | PDO with parameterized queries (no ORM) |
| Auth | PHP native sessions + `password_hash()`/`password_verify()` (bcrypt) |
| CSRF protection | Token-based, verified on every state-changing form |
| Styling | Hand-written CSS (no framework) — warm gold/dark brown theme |

This project has been **fully built and tested end-to-end** — registration → booking → approval → payment → verification → receipt generation → chatbot — running live against PHP 8.3 + MySQL 8 before being packaged.

---

## 📦 What's in this ZIP

```
parishhub-php/
├── admin/            # Admin (Parish Priest) pages — users, priests, services, logs, settings, reports
├── auth/              # login.php, register.php, logout.php
├── config/            # config.php — edit your DB credentials here
├── database/          # schema.sql, seed.sql, parishhub_full.sql, migration_scheduling.sql, hash-password.php
├── docs/              # Architecture, ERD, diagrams, API/page reference, security notes
├── errors/            # 403 / 404 / 500 pages
├── includes/          # auth.php (RBAC), functions.php, scheduling.php (fixed-schedule rules), header/footer/sidebar/topbar partials
├── parishioner/        # Parishioner pages — book, appointments, calendar, profile, etc.
├── public/            # css/style.css (theme), js/app.js, calendar.js, scheduling.js, uploads/
├── secretary/          # Secretary pages — appointments, calendar, announcements, services
├── treasurer/          # Treasurer pages — payments, reports
├── index.php           # Landing page
├── about.php
├── chatbot.php          # AJAX JSON endpoint for the chat widget
├── install.php          # ⭐ One-click web installer — run this first
└── .htaccess            # Apache hardening (protects config/, includes/)
```

---

## 🖼️ Adding Your Own Logo

Drop your logo image into `public/img/` as `logo.png` and it automatically appears on every crest across the entire site — every dashboard sidebar (Parishioner, Secretary, Treasurer, Admin), the homepage, About page, login/register pages, and the installer — with **no code changes needed**. If no logo is present, everything cleanly falls back to the default gold "P" crest. See `public/img/README.md` for image recommendations. Remove or rename the file to revert to the default crest at any time.

## 🐛 Fixed: Uploaded documents weren't viewable

Secretaries reviewing a request could see the uploaded file's *name* but had no way to actually open or view it — and even the filename alone often wasn't useful (e.g. a phone camera's auto-generated name like `74a39ea8-ca00-4127-9336-fd98fdc817f7.jpg`). Underneath that, there was also a real path bug: uploaded files are physically saved to `public/uploads/`, but the database was storing the path as just `uploads/...` — missing the `public/` segment — so even a naive link would have 404'd.

Both are now fixed:
- Every uploaded document is now a clickable link that opens the real file in a new tab, with an actual image thumbnail shown inline for photo uploads (PDFs show a document icon instead).
- A `documentUrl()` helper (`includes/functions.php`) normalizes both old-style and new-style stored paths, so this works correctly for files that were already uploaded before this fix, not just new ones.
- This applies to both the secretary's review page and the parishioner's own appointment detail page.

## 🎨 Design

The public-facing pages (landing page, About, login, register) use a considered editorial design system, not a generic template:

- **Typography**: [Cormorant Garamond](https://fonts.google.com/specimen/Cormorant+Garamond) (a manuscript-feeling display serif, evoking old missals/hymnals) paired with [Inter](https://fonts.google.com/specimen/Inter) for body text and every form/table in the app, loaded from Google Fonts in `includes/header.php`.
- **Signature motif**: a row of thin pointed arches (`includes/arch-divider.php`) marks the seam between the hero and page content — original SVG line-work evoking a church nave arcade, not a stock image.
- **Real content, not filler**: the homepage's stats (services offered, priests serving) and the About page's Sacrament/Mass schedule reference table both pull live from the same `includes/scheduling.php` rules and database that drive actual booking validation — so the public site can never drift out of sync with what the system actually enforces.
- **Auth pages** use a split-screen layout (scripture quote + branding on one side, the form on the other) instead of a plain centered card.

If you're offline or Google Fonts is blocked on your network, the pages gracefully fall back to system serif/sans-serif fonts — nothing breaks.

## 🐛 Fixed: "Submit Request" silently failing on file upload

If parishioners' appointment requests were failing with a confusing "Session expired" error when they attached document photos, this was a real PHP behavior, not a UI bug: when the total upload size exceeds `post_max_size` (8MB by default), **PHP silently empties `$_POST` and `$_FILES` entirely**, even though the browser sent real data. The form would then read the CSRF token as missing and report an unrelated "session expired" error.

This is now fixed:
- `parishioner/book.php` detects this exact condition (empty `$_POST` + non-zero `Content-Length`) *before* checking the CSRF token, and shows an accurate message telling the parishioner their files were too large — not a session error.
- Individual oversized files are now reported by name instead of silently dropped.
- The upload field shows the *real* PHP limits (`upload_max_filesize` / `post_max_size`) dynamically, instead of a constant that didn't match the server's actual configuration.
- Parishioners can now genuinely upload documents later from the appointment detail page (this was referenced in the help text before but didn't actually exist).

If your parishioners regularly submit large phone-camera photos, consider raising `upload_max_filesize` and `post_max_size` in your `php.ini` (common values: `10M` / `12M`).

---

## 📋 Fixed Scheduling Rules (Secretary / Treasurer / Priest Workflow)

PARISHHUB enforces the parish's actual scheduling policy in code, not just as a suggestion — every booking (whether made by a parishioner online or rescheduled by the secretary) is checked against these rules before it's saved:

| Service | Rule |
|---|---|
| **Baptism** | 1st and 3rd Saturday of the month only, fixed at 9:00 AM |
| **Wedding** | 4th Saturday of the month only, fixed at 8:00 AM |
| **Confirmation** | No fixed rule — depends on the Bishop's availability; the office coordinates the exact date directly |
| **Funeral Mass** | Must fall on or after the 9-day mourning period from the date of death; fixed at 1:00 PM |
| **House Blessing** | No fixed rule — arranged directly between the parishioner and the priest |
| **Mass Intention** | Must land on an actual Mass time: daily 6:00 AM (5:15 PM on Wednesdays), or Sunday 6:00 AM / 9:00 AM / 4:30 PM |
| **Staff day off** | Every Monday from 12:00 PM onward, and all day Tuesday — no bookings accepted in this window, for any service |

The full rule set lives in one place — `includes/scheduling.php` — and is mirrored in `public/js/scheduling.js` for instant client-side feedback on the booking form (disabled calendar dates, auto-filled fixed times, a live-populated Mass-time dropdown). The server always re-validates independently before saving, regardless of what the browser sent.

**The three-role workflow this implements:**
- **Secretary** — reviews each request, checks the required documents against what was uploaded, marks documents as verified, and only then can approve. Assigning a priest checks his existing schedule automatically and blocks double-booking; a "Priest availability" panel on the appointment page shows each priest's next 5 upcoming appointments before you assign.
- **Treasurer** — verifies payment once the secretary has approved; this auto-generates the Official Receipt. The appointment then waits in "Payment Verified" status.
- **Secretary (again)** — confirms the appointment once it's Payment Verified, and marks it Completed after the service has taken place. Parishioners see status-aware guidance messages at each step ("awaiting document review," "please wait for confirmation," etc).

### Applying this to your existing database
If you already have PARISHHUB installed, run `database/migration_scheduling.sql` once via phpMyAdmin's Import (or SQL) tab. It only adds one new nullable column (`appointments.date_of_death`, used for the funeral 9-day calculation) and does not touch or delete any existing data. It's safe to run more than once — it checks first and does nothing if already applied.

---

## 🗓️ Interactive Calendar (powered by FullCalendar.js)

The Parish Calendar (`parishioner/calendar.php`), Manage Calendar (`secretary/calendar.php`), and the date picker on the Book Appointment page all use **[FullCalendar.js](https://fullcalendar.io)** — a real, widely-used JavaScript calendar library — loaded from CDN (`https://cdn.jsdelivr.net/npm/fullcalendar@6.1.21/index.global.min.js`), not a hand-rolled substitute.

- **The displayed day, month, and year are always correct for the visitor's own local time zone.** FullCalendar derives "today" from the browser's own `Date` object by default — there's no server-side timezone configuration involved, so a visitor in a different timezone from the server always sees their own correct local date highlighted.
- `public/js/calendar.js` is a small wrapper (`renderParishCalendar()`) around FullCalendar that feeds it your parish's `events` and blocked `calendar` dates from the database and re-skins its built-in CSS variables to match the gold/dark-brown theme.
- Days with parish events or blocked dates are marked directly on the grid using FullCalendar's real event-rendering system.
- Parishioners can click any open date to jump straight into booking an appointment for that day; the booking page also embeds a mini FullCalendar instance as a date picker, with blocked dates disabled.
- Secretaries can click any date to instantly load it into the "Add Event" / "Block Date" forms, and can unblock dates or remove events directly from the page.
- Built-in FullCalendar navigation (prev / next / today buttons) — fully responsive.

---

## 🚀 Getting Started in VS Code (with XAMPP + phpMyAdmin)

### 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) (or WAMP/MAMP) with **PHP 8+**, **MySQL/MariaDB**, and **phpMyAdmin**
- Start **Apache** and **MySQL** from the XAMPP Control Panel

### 2. Unzip into your server's document root
Unzip this file directly into:
- **Windows (XAMPP):** `C:\xampp\htdocs\parishhub`
- **macOS (XAMPP/MAMP):** `/Applications/XAMPP/htdocs/parishhub`
- **Linux:** `/opt/lampp/htdocs/parishhub`

Then open that folder in VS Code (`File → Open Folder…`).

### 3. Configure database credentials
Open `config/config.php` and confirm these match your setup (XAMPP defaults shown — usually no changes needed):
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default: empty password
define('DB_NAME', 'parishhub');
```
Also update `APP_URL` if your folder name differs from `parishhub`.

### 4. Install the database — pick ONE method

**Option A — One-click installer (recommended):**
Open your browser to:
```
http://localhost/parishhub/install.php
```
Click **"Install / Reset Database."** This creates the `parishhub` database, all 22 tables, seed data, and working demo passwords automatically. Done!

**Option B — Manual import via phpMyAdmin (single file, easiest):**
1. Open phpMyAdmin (`http://localhost/phpmyadmin`)
2. Click **Import** (no need to pre-create the database — the file creates it for you)
3. Choose `database/parishhub_full.sql` and click **Go**

That's it — this single file contains the full schema *and* seed data, **with real working password hashes already baked in** (`Password@123` for all 3 demo accounts), so you can log in immediately after import with no extra steps.

**Option C — Manual import, schema and seed as separate files:**
1. Open phpMyAdmin, create a database named `parishhub`
2. Import `database/schema.sql`, then import `database/seed.sql`
3. The seeded demo accounts will have a placeholder password — set real ones by either:
   - Visiting `install.php` afterward anyway (it only overwrites the 3 demo passwords, safe to run after a manual import), **or**
   - Generating a hash yourself: `http://localhost/parishhub/database/hash-password.php?password=YourPassword` and pasting the result into the `password` column for that user row in phpMyAdmin

### 5. Visit the site
```
http://localhost/parishhub/
```

### 6. Log in
| Role | Email | Password |
|---|---|---|
| Admin (Parish Priest) | admin@parishhub.local | Password@123 |
| Secretary | secretary@parishhub.local | Password@123 |
| Treasurer | treasurer@parishhub.local | Password@123 |
| Parishioner | *Register your own at `/parishhub/auth/register.php`* | — |

**⚠️ Change these default passwords immediately in production**, and **delete or rename `install.php`** once set up (it can reset your database if left accessible).

---

## 🔄 End-to-End Workflow (as implemented & tested)

```
Parishioner registers/logs in
   → Browses Services → Books Appointment (+ Mass Intention details if applicable)
   → Uploads requirement documents (optional)
   → Secretary reviews → Approves / Rejects / Assigns Priest / Reschedules
   → Parishioner pays online (GCash/Maya/Bank/Card) or in person (Cash)
   → Treasurer verifies payment → Official Receipt auto-generated (OR-YYYY-000001)
   → Notifications sent to parishioner at each step
   → All actions logged in activity_logs for audit
```

This exact chain was tested live: a real booking was approved, paid, verified, and issued receipt `OR-2026-000002` during development.

---

## 🎨 Customizing the Theme

All colors live as CSS variables at the top of `public/css/style.css`:
```css
:root {
  --gold: #c99b2f;
  --gold-light: #e8c568;
  --brown-darkest: #241611;
  --brown-dark: #3e2723;
  --cream: #faf3e6;
  ...
}
```
Change these to instantly re-theme the entire application.

---

## 🔐 Security Notes
- Every state-changing form includes a **CSRF token** (`includes/functions.php::csrfField()` / `verifyCsrf()`), verified server-side before any database write.
- Passwords are hashed with PHP's native `password_hash()` (bcrypt).
- All SQL uses **parameterized PDO queries** — no string concatenation into SQL anywhere.
- `.htaccess` files block direct web access to `config/`, `includes/`, and disable PHP execution inside `public/uploads/`.
- See `docs/security.md` for the full list.

---

## 📚 Further Documentation
See the `/docs` folder for architecture notes, ERD, use-case/DFD/sequence/class diagrams, a full page/route reference, and security details — all updated for this PHP edition.

---

## 🛠 Suggested Next Steps / Production Hardening
- Delete/rename `install.php` after setup
- Add real SMS/Email sending for the `notifications` table
- Move file uploads to cloud storage for production deployments
- Serve over HTTPS in production
- Consider adding rate-limiting at the web server level (e.g. via Apache `mod_evasive` or a WAF) since PHP has no built-in equivalent to Node's `express-rate-limit`

---

Built for parish communities. ✝️
