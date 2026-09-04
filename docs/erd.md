# PARISHHUB — Entity Relationship Diagram

> Paste the Mermaid block below into [mermaid.live](https://mermaid.live) or view it directly in VS Code with the "Markdown Preview Mermaid Support" extension.

```mermaid
erDiagram
    ROLES ||--o{ USERS : has
    USERS ||--o| PARISHIONERS : "extends (if role=Parishioner)"
    USERS ||--o| PRIESTS : "optional login"
    PARISHIONERS ||--o{ APPOINTMENTS : books
    SERVICES ||--o{ APPOINTMENTS : "requested via"
    PRIESTS ||--o{ APPOINTMENTS : assigned
    APPOINTMENT_STATUS ||--o{ APPOINTMENTS : "current status"
    APPOINTMENTS ||--o| MASS_INTENTIONS : "detail (if Mass Intention)"
    APPOINTMENTS ||--o{ UPLOADED_DOCUMENTS : has
    REQUIREMENTS ||--o{ UPLOADED_DOCUMENTS : satisfies
    SERVICES ||--o{ REQUIREMENTS : defines
    APPOINTMENTS ||--o{ PAYMENTS : "paid via"
    PAYMENT_METHODS ||--o{ PAYMENTS : "method used"
    PAYMENTS ||--o| OFFICIAL_RECEIPTS : "issues"
    PAYMENTS ||--o{ TRANSACTIONS : "gateway log"
    USERS ||--o{ ANNOUNCEMENTS : posts
    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ CHAT_MESSAGES : sends
    USERS ||--o{ ACTIVITY_LOGS : performs
    USERS ||--o{ REPORTS : generates
    CALENDAR ||--o{ EVENTS : contains

    ROLES {
        int role_id PK
        varchar role_name
    }
    USERS {
        int user_id PK
        int role_id FK
        varchar firstname
        varchar lastname
        varchar email
        varchar password
        varchar phone
        enum status
    }
    PARISHIONERS {
        int parishioner_id PK
        int user_id FK
        date baptism_date
        enum marital_status
    }
    PRIESTS {
        int priest_id PK
        int user_id FK
        varchar full_name
        enum status
    }
    SERVICES {
        int service_id PK
        varchar service_name
        enum category
        decimal fee
        int duration_minutes
    }
    APPOINTMENTS {
        int appointment_id PK
        int parishioner_id FK
        int service_id FK
        int priest_id FK
        int status_id FK
        date appointment_date
        time appointment_time
    }
    MASS_INTENTIONS {
        int intention_id PK
        int appointment_id FK
        enum intention_type
        varchar offerer_name
    }
    PAYMENTS {
        int payment_id PK
        int appointment_id FK
        int method_id FK
        decimal amount
        enum payment_status
    }
    OFFICIAL_RECEIPTS {
        int receipt_id PK
        int payment_id FK
        varchar receipt_number
        int issued_by FK
    }
    ANNOUNCEMENTS {
        int announcement_id PK
        int posted_by FK
        varchar title
    }
    NOTIFICATIONS {
        int notification_id PK
        int user_id FK
        enum type
        varchar title
    }
    ACTIVITY_LOGS {
        int log_id PK
        int user_id FK
        varchar action
    }
```

## Full Table List (22 tables)

| # | Table | Purpose |
|---|---|---|
| 1 | `roles` | Parishioner / Secretary / Treasurer / Admin |
| 2 | `permissions` + `role_permissions` | Fine-grained permission keys, optional extension point beyond role-based checks |
| 3 | `users` | Base account for every human in the system |
| 4 | `parishioners` | Extends `users` with sacrament/marital data (1:1) |
| 5 | `priests` | Roster of priests (may or may not have a login) |
| 6 | `services` | Catalog of bookable services (Mass Intention types, Sacraments, Blessings) with fee & duration |
| 7 | `requirements` | Per-service list of required documents |
| 8 | `appointment_status` | Lookup: Pending, Approved, Rejected, Payment Verified, Confirmed, Completed, Cancelled |
| 9 | `appointments` | Central booking record — links parishioner, service, priest, status, date/time |
| 10 | `mass_intentions` | Detail row specific to Mass Intention bookings (offerer, intention type, message) |
| 11 | `uploaded_documents` | Files uploaded against an appointment/requirement |
| 12 | `calendar` | Blockable dates (e.g. diocesan holidays — no bookings allowed) |
| 13 | `events` | Parish activity calendar (feasts, fiestas, seminars) |
| 14 | `announcements` | Parish-wide announcements, pinnable |
| 15 | `payment_methods` | Cash, GCash, Maya, Bank Transfer, Card |
| 16 | `payments` | Payment attempts/records tied to an appointment |
| 17 | `transactions` | Raw gateway log (for future online payment gateway integration) |
| 18 | `official_receipts` | Auto-generated OR upon payment verification |
| 19 | `notifications` | In-app (and future email/SMS) notifications per user |
| 20 | `chat_messages` | Chatbot conversation log (user + bot turns, matched intent) |
| 21 | `activity_logs` | Full audit trail of state-changing actions |
| 22 | `reports` | Metadata for generated report exports |
| — | `settings` | Key-value store for parish info & branding (name, address, mass schedule, theme colors) |

## Key Design Decisions
- **`appointments` is the hub** — nearly everything (payments, documents, mass intention detail) hangs off an `appointment_id`, mirroring the real-world workflow where a parishioner requests *one* service at a time.
- **Soft role extension**: rather than separate `parishioner_users`, `secretary_users` tables, all humans live in one `users` table with a `role_id`; `parishioners` and `priests` are optional 1:1 extension tables holding role-specific fields. This keeps auth logic in one place.
- **Status as a lookup table**, not an ENUM on `appointments`, so the workflow states can be extended (e.g. add "Awaiting Documents") without a schema migration touching the ENUM definition.
