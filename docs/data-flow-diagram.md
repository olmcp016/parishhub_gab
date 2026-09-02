# PARISHHUB — Data Flow Diagram (Level 0 & 1)

## Level 0 (Context Diagram)

```mermaid
graph LR
    P[Parishioner] -->|Booking requests, payments| SYS((PARISHHUB System))
    S[Secretary] -->|Approvals, schedules| SYS
    T[Treasurer] -->|Payment verification| SYS
    A[Admin/Priest] -->|System configuration| SYS
    SYS -->|Confirmations, receipts, notifications| P
    SYS -->|Appointment queues| S
    SYS -->|Payment records| T
    SYS -->|Reports, logs| A
    SYS <-->|Persisted records| DB[(MySQL Database)]
```

## Level 1 (Major Processes)

```mermaid
graph TD
    P[Parishioner] -->|1. Submit booking| P1[Process 1.0<br/>Appointment Booking]
    P1 -->|writes| DBA[(appointments,<br/>mass_intentions)]
    P1 -->|notify| DBN[(notifications)]

    P1 -->|pending queue| P2[Process 2.0<br/>Appointment Review]
    S[Secretary] -->|approve/reject/assign| P2
    P2 -->|updates| DBA

    P2 -->|approved appointment| P3[Process 3.0<br/>Payment Processing]
    P[Parishioner] -->|submit payment| P3
    P3 -->|writes| DBP[(payments)]

    P3 -->|pending payment| P4[Process 4.0<br/>Payment Verification]
    T[Treasurer] -->|verify| P4
    P4 -->|updates| DBP
    P4 -->|generates| DBR[(official_receipts)]
    P4 -->|updates| DBA

    P5[Process 5.0<br/>Reporting Engine]
    A[Admin] -->|request reports| P5
    T -->|request reports| P5
    S -->|request reports| P5
    DBA -.read.-> P5
    DBP -.read.-> P5

    P6[Process 6.0<br/>Chatbot Engine]
    P -->|ask question| P6
    P6 -->|reads| DBS[(services, priests,<br/>settings)]
    P6 -->|logs| DBC[(chat_messages)]
```

## Process Descriptions

| Process | Input | Output | Implemented In |
|---|---|---|---|
| 1.0 Appointment Booking | Service selection, date/time, mass intention details, documents | New `appointments` row (status=Pending), notification | `parishioner/book.php` (POST handler) |
| 2.0 Appointment Review | Pending appointments | Approved/Rejected status, priest assignment, reschedule | `secretary/appointment-detail.php` (approve/reject/assign_priest/reschedule actions) |
| 3.0 Payment Processing | Approved appointment, payment details | New `payments` row (status=pending) | `parishioner/pay.php`, `treasurer/payments.php` (manual action) |
| 4.0 Payment Verification | Pending payment | Verified payment, official receipt, appointment status update | `treasurer/payment-detail.php` (verify action) |
| 5.0 Reporting Engine | Date ranges, filters | Aggregated revenue/appointment statistics | `*/reports.php` (per role) |
| 6.0 Chatbot Engine | Free-text question | Matched FAQ answer from live DB data | `chatbot.php` |
