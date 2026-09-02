# PARISHHUB — Class Diagram

Since PARISHHUB uses raw SQL rather than an ORM, there are no formal "model classes" — but the same responsibilities are cleanly separated by controller. This diagram represents the logical entities and the operations available on each, as implemented across the controller layer.

```mermaid
classDiagram
    class User {
        +int user_id
        +int role_id
        +string firstname
        +string lastname
        +string email
        +string password
        +string status
        +login()
        +logout()
        +updateProfile()
    }

    class Parishioner {
        +int parishioner_id
        +int user_id
        +string marital_status
        +bookAppointment()
        +cancelAppointment()
        +payOnline()
        +viewAppointments()
        +useChatbot()
    }

    class Secretary {
        +approveAppointment()
        +rejectAppointment()
        +assignPriest()
        +rescheduleAppointment()
        +manageCalendar()
        +postAnnouncement()
        +manageServices()
        +generateReports()
    }

    class Treasurer {
        +verifyPayment()
        +recordManualPayment()
        +generateReceipt()
        +generateFinancialReports()
    }

    class Admin {
        +manageUsers()
        +manageRoles()
        +managePriests()
        +manageServices()
        +viewActivityLogs()
        +configureSettings()
        +backupDatabase()
    }

    class Appointment {
        +int appointment_id
        +int parishioner_id
        +int service_id
        +int priest_id
        +int status_id
        +date appointment_date
        +time appointment_time
    }

    class Service {
        +int service_id
        +string service_name
        +string category
        +decimal fee
        +int duration_minutes
    }

    class Payment {
        +int payment_id
        +int appointment_id
        +decimal amount
        +string payment_status
        +verify()
    }

    class OfficialReceipt {
        +int receipt_id
        +string receipt_number
        +datetime issue_date
    }

    class Chatbot {
        +matchIntent(text)
        +respond(intent)
        +logConversation()
    }

    User <|-- Parishioner
    User <|-- Secretary
    User <|-- Treasurer
    User <|-- Admin
    Admin --|> Secretary : inherits all actions
    Admin --|> Treasurer : inherits all actions

    Parishioner "1" --> "*" Appointment : books
    Appointment "*" --> "1" Service : requests
    Appointment "1" --> "0..1" Payment : paid via
    Payment "1" --> "0..1" OfficialReceipt : issues
    Parishioner ..> Chatbot : uses
```

## Notes
- `Admin` is modeled as inheriting all `Secretary` and `Treasurer` capabilities — enforced in code via `requireRole('Secretary','Admin')` and `requireRole('Treasurer','Admin')` guards at the top of each page file, rather than class inheritance (since this is a procedural PHP app, not an OOP domain model).
- Each "class" above corresponds to a role folder (`parishioner/`, `secretary/`, `treasurer/`, `admin/`) and its page files, which combine controller logic and view rendering per screen — see `docs/architecture.md`.
