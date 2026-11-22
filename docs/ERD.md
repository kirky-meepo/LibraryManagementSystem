# Entity relationship diagram

The 15 tables of the LibraryInventorySystem (schema `lms`). docs/SPEC.md is the source of truth; this diagram follows it.

```mermaid
erDiagram
    staff {
        int staff_id PK
        varchar first_name
        varchar middle_name
        varchar last_name
        varchar email UK
        text password_hash
        varchar position "librarian | admin"
        varchar contact_number
        date date_hired
        boolean is_active
        boolean must_change_password
    }
    student {
        int student_id PK
        varchar first_name
        varchar middle_name
        varchar last_name
        varchar course
        smallint year_level "1 to 6"
        varchar contact_number
        varchar email UK
        text password_hash
        boolean is_active
        boolean must_change_password
        date date_registered
    }
    teacher {
        int teacher_id PK
        varchar first_name
        varchar middle_name
        varchar last_name
        varchar department
        varchar contact_number
        varchar email UK
        text password_hash
        boolean is_active
        boolean must_change_password
        date date_registered
    }
    book {
        int book_id PK
        varchar isbn UK
        varchar title
        varchar author
        varchar category
        int copies_total
        int copies_available
        numeric replacement_cost
        int added_by_id FK
        date date_added
        boolean is_archived
        int copy_limit "1..999, default 5, copies_total at most this"
    }
    book_request {
        int request_id PK
        int student_id FK "one borrower"
        int teacher_id FK "one borrower"
        int book_id FK
        timestamptz date_requested
        varchar status
        int decided_by_id FK
        timestamptz date_decided
        date pickup_date
        time pickup_time
        date pickup_deadline
        text decision_note
    }
    transaction {
        int transaction_id PK
        int student_id FK "one borrower"
        int teacher_id FK "one borrower"
        int book_id FK
        int staff_id FK
        int request_id FK,UK
        date date_borrowed
        date due_date
        date date_returned
        varchar status
    }
    fine {
        int fine_id PK
        int transaction_id FK
        varchar fine_type "overdue | lost | damaged"
        numeric amount
        date date_issued
        date date_paid
        varchar status "unpaid | paid | waived"
        text waived_reason
    }
    message {
        int message_id PK
        int sender_staff_id FK "null = system"
        int recipient_student_id FK "one recipient"
        int recipient_teacher_id FK "one recipient"
        int request_id FK
        varchar kind
        varchar subject
        text body
        timestamptz sent_at
        timestamptz read_at
    }
    message_template {
        int template_id PK
        varchar kind
        varchar label
        varchar subject
        text body
        boolean is_active
        int sort_order
    }
    library_setting {
        text setting_key PK
        text setting_value
        text description
    }
    audit_log {
        bigint audit_id PK
        varchar actor_type
        int actor_id
        text action
        text table_name
        text record_id
        jsonb detail
        timestamptz created_at
    }
    login_attempt {
        bigint attempt_id PK
        varchar email
        varchar user_type
        boolean success
        inet ip
        timestamptz attempted_at
    }
    password_reset {
        bigint reset_id PK
        int student_id FK "one owner"
        int teacher_id FK "one owner"
        int staff_id FK "one owner"
        char token_hash UK "sha256 of the emailed token"
        timestamptz requested_at
        timestamptz expires_at "requested_at + 30 minutes"
        timestamptz used_at
        inet ip
    }
    app_session {
        varchar id PK
        text data
        timestamptz updated_at
    }
    account_presence {
        varchar user_type PK "staff | student | teacher"
        int user_id PK
        boolean signed_in
        timestamptz last_seen
    }

    staff        ||--o{ book         : "adds"
    staff        ||--o{ book_request : "decides"
    staff        ||--o{ transaction  : "hands over"
    staff        |o--o{ message      : "sends"
    student      |o--o{ book_request : "makes"
    teacher      |o--o{ book_request : "makes"
    book         ||--o{ book_request : "is requested in"
    student      |o--o{ transaction  : "borrows"
    teacher      |o--o{ transaction  : "borrows"
    book         ||--o{ transaction  : "is lent in"
    book_request |o--o| transaction  : "is fulfilled by"
    transaction  ||--o{ fine         : "creates"
    student      |o--o{ message      : "receives"
    teacher      |o--o{ message      : "receives"
    book_request |o--o{ message      : "is answered by"
    student      |o--o{ password_reset : "asks to reset"
    teacher      |o--o{ password_reset : "asks to reset"
    staff        |o--o{ password_reset : "asks to reset"
```

`message_template`, `library_setting`, `audit_log`, `login_attempt` and `app_session` (sessions when SESSION_DRIVER=db) and `account_presence` (who is signed in now) stand alone. Routines read the templates and settings by key, the audit trigger writes `audit_log`, and the sign-in routines in `04b_auth.sql` write `login_attempt`. `password_reset` belongs to exactly one student, teacher or staff member and is deleted with them; it has no audit trigger, because the trigger would copy the token hash.

## How the request, loan and fine flow connects the tables

A student or teacher creates a `book_request` for a `book`. A staff member approves it, which reserves one copy (`copies_available` goes down by one) and sends a `message` with the pickup date and time, or rejects it with a reason and a message. When the borrower comes to the desk, the approved request is issued as a `transaction` (the loan) that points back to the request, and the request becomes fulfilled. Walk-in loans are a `transaction` with no request. When the book comes back, the loan is closed as returned or lost, and any late days, damage or loss create rows in `fine` linked to that loan. Every table in this chain points to exactly one borrower: a student or a teacher, never both. History rows use ON DELETE RESTRICT, so a person or book with history is deactivated or archived instead of deleted.
