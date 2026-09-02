# PARISHHUB (PHP Edition) — System Architecture

## 1. Architectural Pattern

PARISHHUB (PHP Edition) is a classic **procedural PHP** application — no framework, no router, no build step. Each URL maps directly to a `.php` file on disk, which handles both the page logic (GET) and form submission (POST) for that screen.

```
Browser (HTML/CSS/JS)
     │  HTTP requests
     ▼
Apache/PHP  (index.php, admin/dashboard.php, etc.)
     │
     ├── config/config.php   — DB connection (PDO), constants, session bootstrap
     ├── includes/auth.php   — RBAC guards (requireAuth, requireRole), URL helpers
     ├── includes/functions.php — activity logging, formatting, CSRF helpers
     ├── includes/scheduling.php — fixed-schedule business rules (Baptism/Wedding/Funeral dates, Mass times, staff day-off)
     ├── includes/*.php      — header/footer/sidebar/topbar/flash partials (included, not templated)
     └── MySQL 8 (via phpMyAdmin or CLI) — 22 tables, accessed via PDO
```

There is no MVC framework separating routes/controllers/views into distinct layers — each page file *is* its controller and its view combined, which is the idiomatic pattern for small-to-medium PHP applications and keeps everything readable top-to-bottom in a single file per screen.

---

## 2. Request Lifecycle (typical page)

Every protected page follows this same shape:

```php
<?php
require_once __DIR__ . '/../includes/auth.php';      // session, url(), e(), requireRole()
require_once __DIR__ . '/../includes/functions.php';  // logActivity(), csrfField(), etc.
requireRole('Secretary', 'Admin');                     // 1. Auth/RBAC guard

if ($_SERVER['REQUEST_METHOD'] === 'POST') {           // 2. Handle form submission (if any)
    verifyCsrf();
    // ... validate, run PDO queries, logActivity(), flash(), redirect() ...
}

// 3. Fetch data needed for the page
$rows = db()->query("SELECT ...")->fetchAll();

// 4. Render
$pageTitle = 'Page Title';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dash-start.php';  // sidebar + topbar + flash
?>
<!-- page HTML here, using <?= e($value) ?> for safe output -->
<?php
include __DIR__ . '/../includes/dash-end.php';
include __DIR__ . '/../includes/footer.php';
```

This pattern repeats across all ~35 page files (parishioner, secretary, treasurer, admin folders).

---

## 3. Module Breakdown

### 3.1 `config/config.php`
Defines DB connection constants, starts the PHP session, and exposes a `db()` function returning a shared PDO instance (singleton pattern via a static variable) — mirrors a connection pool without needing a real pooling library, since PHP's request lifecycle is single-request-per-process anyway.

### 3.2 `includes/auth.php`
- `currentUser()`, `isLoggedIn()` — read `$_SESSION['user']`
- `requireLogin()` / `requireRole(...$roles)` — guards, redirect or 403 as needed
- `guestOnly()` — blocks already-logged-in users from login/register pages
- `redirectForRole($role)` — where each role lands after login
- `url($path)` / `baseUrl()` — builds correct links regardless of what subfolder PARISHHUB is installed in (handles both `http://localhost/parishhub/` and `http://localhost/` root installs)
- `e($value)` — `htmlspecialchars()` shorthand used in every view for XSS-safe output

### 3.3 `includes/functions.php`
- `logActivity()` — writes to `activity_logs` (audit trail)
- `badgeClass()`, `money()`, `formatDate()`, `formatDateTime()` — view helpers
- `csrfToken()` / `csrfField()` / `verifyCsrf()` — CSRF protection, session-token based

### 3.4 `includes/header.php` / `footer.php` / `sidebar.php` / `topbar.php` / `flash.php` / `dash-start.php` / `dash-end.php`
PHP's answer to a templating engine's "layout" — plain `include()` calls that share the `$pageTitle` and `$active` variables set by the calling page. `dash-start.php`/`dash-end.php` wrap the sidebar+topbar+content shell so every authenticated page only needs two extra `include()` lines.

### 3.5 Role folders (`parishioner/`, `secretary/`, `treasurer/`, `admin/`)
One file per screen. Each file is self-contained: guard → (optional) POST handler → data fetch → render. This mirrors the Node.js edition's controller+route+view split, just collapsed into a single file per page for PHP's simpler request model.

### 3.6 `chatbot.php`
A single JSON endpoint (no page render) — reads `$_SESSION['chat_session_id']`, matches the incoming message against keyword-based intents, queries live data (`services`, `priests`, `settings` tables) for the answer, logs the exchange to `chat_messages`, and returns `{"reply": "..."}`.

### 3.7 `install.php`
A **web-based installer**, since phpMyAdmin users typically don't have shell/CLI access to run migration scripts. Connects via `mysqli` (supports multi-statement SQL execution needed to run the full `schema.sql` + `seed.sql` files in one shot), then switches to PDO to set real bcrypt password hashes on the 3 demo accounts. Designed to be safely re-runnable (always resets to a fresh seeded state — the schema uses `DROP TABLE IF EXISTS` throughout).

### 3.8 `database/`
- `schema.sql` — all 22 tables, identical structure to the Node.js edition (same MySQL schema works for both — this is plain, portable SQL)
- `seed.sql` — lookup data, sample services/priests, demo staff accounts (placeholder password hash, overwritten by `install.php`)
- `hash-password.php` — standalone CLI/browser utility for anyone doing a fully manual phpMyAdmin import

---

## 4. Why plain PHP instead of a framework (Laravel/Symfony)?

For a project meant to be unzipped straight into a shared-hosting or XAMPP environment with zero `composer install` step, plain PHP with PDO keeps the barrier to entry at "unzip and configure one file." Every query is visible and traceable directly in the page that uses it — there's no routing table, service container, or ORM abstraction to learn before a developer can find where a given page's logic lives.
