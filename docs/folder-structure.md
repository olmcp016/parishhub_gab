# PARISHHUB (PHP Edition) — Folder Structure

```
parishhub-php/
│
├── admin/
│   ├── dashboard.php
│   ├── users.php
│   ├── priests.php
│   ├── services.php
│   ├── activity-logs.php
│   ├── settings.php
│   ├── reports.php
│   └── backup.php
│
├── auth/
│   ├── login.php
│   ├── register.php
│   └── logout.php
│
├── config/
│   ├── config.php          # DB credentials, session bootstrap, db() PDO helper
│   └── .htaccess           # blocks direct web access to this folder
│
├── database/
│   ├── schema.sql          # 22 tables, PK/FK, indexes, ENUMs
│   ├── seed.sql            # lookup data + sample services/priests/staff
│   └── hash-password.php   # bcrypt hash generator (CLI or browser)
│
├── docs/
│   ├── architecture.md
│   ├── erd.md
│   ├── use-case-diagram.md
│   ├── data-flow-diagram.md
│   ├── sequence-diagram.md
│   ├── class-diagram.md
│   ├── page-reference.md   # full list of pages/actions (replaces API docs)
│   ├── security.md
│   ├── folder-structure.md # (this file)
│   └── future-enhancements.md
│
├── errors/
│   ├── 403.php
│   ├── 404.php
│   └── 500.php
│
├── includes/
│   ├── auth.php            # session helpers, RBAC guards, url()/e()
│   ├── functions.php       # logActivity(), formatters, CSRF helpers
│   ├── header.php          # <head>, opens <body>
│   ├── footer.php          # chatbot widget markup, closes <body>
│   ├── sidebar.php         # role-aware nav
│   ├── topbar.php
│   ├── flash.php
│   ├── dash-start.php      # opens app-shell + sidebar + topbar + page-body
│   ├── dash-end.php        # closes the above
│   └── .htaccess           # blocks direct web access to this folder
│
├── parishioner/
│   ├── dashboard.php, services.php, book.php, appointments.php,
│   │   appointment-detail.php, pay.php, calendar.php, announcements.php,
│   │   profile.php, notifications.php
│
├── public/
│   ├── css/style.css       # warm gold & dark brown theme (CSS variables)
│   ├── js/app.js           # sidebar toggle, flash auto-dismiss, chatbot widget
│   └── uploads/            # uploaded requirement documents (.htaccess disables PHP execution)
│
├── secretary/
│   ├── dashboard.php, appointments.php, appointment-detail.php, calendar.php,
│   │   announcements.php, parishioners.php, services.php, reports.php
│
├── treasurer/
│   ├── dashboard.php, payments.php, payment-detail.php, reports.php
│
├── index.php                # Landing page
├── about.php
├── chatbot.php               # AJAX JSON endpoint
├── install.php                # ⭐ One-click web installer
├── .htaccess                  # Apache hardening (root)
├── README.md
└── .gitignore
```

## Naming Conventions Used
- **Pages**: `kebab-case.php`, one file per screen, grouped by role in matching folders
- **Actions**: multiple actions on one page are distinguished by a hidden `action` POST field (e.g. `secretary/appointment-detail.php` handles `approve`, `reject`, `assign_priest`, `reschedule` all through one file)
- **Database**: `snake_case` table and column names throughout — identical schema to the Node.js edition, so the same `.sql` files work for either
- **Includes**: every partial is a plain `.php` file included via `include __DIR__ . '/../includes/xyz.php'` — no templating engine, no magic
