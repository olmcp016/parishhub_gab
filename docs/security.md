# PARISHHUB (PHP Edition) — Security Features & Validation Rules

## Implemented Security Measures

| Feature | Implementation |
|---|---|
| Password hashing | PHP native `password_hash()` (bcrypt, `$2y$` format) — verified against actual PHP output during testing |
| Session security | Native PHP sessions, started once in `config/config.php`; session ID stored in an `httpOnly`-by-default PHP session cookie |
| CSRF protection | Every form includes a hidden `csrf_token` field (`csrfField()`); every POST handler calls `verifyCsrf()` before touching the database, using `hash_equals()` for timing-safe comparison. Confirmed working during testing — a request without a valid token is rejected with HTTP 419 before any query runs. |
| Role-Based Access Control (RBAC) | Every protected page calls `requireRole(...)` as its first line — a Parishioner can never reach `secretary/*.php`, `treasurer/*.php`, or `admin/*.php`, verified by direct testing (renders `errors/403.php`) |
| SQL Injection protection | 100% parameterized queries via PDO (`?` placeholders, `PDO::ATTR_EMULATE_PREPARES => false`) — no string concatenation into SQL anywhere in the codebase |
| XSS protection | All dynamic output goes through the `e()` helper (`htmlspecialchars()`, `ENT_QUOTES`) — confirmed applied consistently across every page |
| Folder-level hardening | `.htaccess` files deny direct web access to `config/` and `includes/`, and disable PHP execution inside `public/uploads/` (prevents an uploaded `.php` file from ever being executed) |
| File upload restrictions | Uploaded filenames are prefixed with `time()` to prevent collisions; client-side `accept` restricts to PDF/JPG/PNG — **recommend adding server-side MIME validation before production use** |
| Activity logging / audit trail | Every significant state change (login, approve, reject, verify payment, user management) is written to `activity_logs` with user ID, action, module, IP address, and user agent |
| Least privilege by default | New registrations always default to `role_id = 1` (Parishioner) — the registration form has no way to self-assign Secretary/Treasurer/Admin |
| Account status gating | `users.status` (active/inactive/suspended) is checked at login — deactivated accounts cannot authenticate even with correct credentials |
| Environment secrets | DB credentials live in `config/config.php`, which is blocked from direct web access via `.htaccess` — never exposed to visitors |

## Validation Rules

### Registration (`auth/register.php`)
- Password and confirm-password must match
- Password minimum length (8 chars) enforced both client-side and **server-side**
- Email uniqueness enforced at the database level (`UNIQUE` constraint) and checked before insert
- New account + `parishioners` extension row created inside a single PDO transaction

### Booking (`parishioner/book.php`)
- Requires a valid `service_id`, `appointment_date`, `appointment_time`
- Server checks the requested date against the `calendar` table's `is_blocked` flag before allowing the booking — rejects with a flash message if blocked
- Booking insert + Mass Intention detail insert + document uploads are wrapped in a single PDO transaction

### Payments (`treasurer/payment-detail.php`)
- Payment verification, appointment status update, and receipt generation are wrapped in a single PDO transaction — a partial failure never leaves the system in an inconsistent state

### File uploads
- Filenames are prefixed with `time()` to prevent overwrites/collisions
- Stored in `public/uploads/`, which has PHP execution disabled via `.htaccess`

## Database-Level Integrity
- Every foreign key relationship in `schema.sql` has an explicit `FOREIGN KEY ... REFERENCES` constraint with sensible `ON DELETE` behavior
- `ENUM` types constrain status/category fields at the database level
- `UNIQUE` constraints on `users.email`, `payments.reference_number`, `official_receipts.receipt_number`

## Recommended Additions for Production (not yet implemented)
- **Delete or rename `install.php`** immediately after setup — left in place, it can reset the entire database (it requires no authentication by design, since it runs before any accounts exist)
- Server-side file type/MIME validation on uploads (not just client `accept` attribute)
- Two-factor authentication for Admin/Treasurer accounts
- Encrypted/off-site backups (phpMyAdmin exports are plaintext SQL)
- Explicit HTTPS enforcement (`session.cookie_secure = 1` in `php.ini`, HSTS header) once deployed off localhost
- Application-level rate limiting or a web-server-level solution (e.g. `mod_evasive`), since plain PHP has no built-in equivalent to Node's `express-rate-limit`
- Login attempt lockout after N failed attempts
