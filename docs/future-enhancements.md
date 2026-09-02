# PARISHHUB (PHP Edition) — Future Enhancements

The current build is a fully working MVP covering every module in the original specification, running on plain PHP + MySQL/phpMyAdmin. Suggested next-phase enhancements, roughly ordered by impact:

## Near-term (high value, low effort)
- **Real email/SMS delivery** — wire the `notifications` table to actual providers (e.g. PHPMailer for email, a local SMS gateway API for SMS)
- **PDF receipts** — generate a downloadable/printable PDF for `official_receipts` (e.g. via the `dompdf` or `tcpdf` library), instead of the current print-friendly HTML view
- **Server-side upload validation** — verify MIME type via `finfo`, not just the client `accept` attribute
- **Pagination** on large tables (appointments, payments, activity logs) — currently capped with `LIMIT`
- **Composer + PHPMailer/dompdf** — introducing Composer for just these two libraries would unlock the two items above without adopting a full framework

## Mid-term
- **Real payment gateway integration** — GCash/PayMongo/Stripe API via the existing `transactions` table (already designed for this)
- **Calendar UI** — replace the current list-based calendar view with a full interactive month/week grid (e.g. FullCalendar.js), including drag-to-reschedule for Secretary
- **Priest self-service portal** — a 5th role allowing priests to log in and view/manage their own assigned schedule
- **Multi-parish / multi-tenant support** — add a `parishes` table and scope all data by `parish_id`

## Long-term
- **AI-powered chatbot upgrade** — replace the keyword-matcher in `chatbot.php` with a call to an LLM API, grounded in the parish's live `services`/`settings` data
- **Migrate to a lightweight framework** (e.g. Slim or Laravel) if the page count keeps growing — the current one-file-per-page pattern scales well up to a few dozen pages but benefits from proper routing beyond that
- **Analytics dashboard** — trend charts (Chart.js) for revenue, service demand, and no-show rates over time
- **Document e-signature** — for wedding/baptism consent forms
- **REST API layer** — extract the current page logic into an API (returning JSON) to support a future mobile app, while keeping these PHP pages as the reference web client
