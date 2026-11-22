# SPEC part 1: tables and rules
Conventions: snake_case names. Integer keys are GENERATED ALWAYS AS IDENTITY. Timestamps are timestamptz.
Emails are stored lower-case. Money is numeric(10,2) in pesos.
transaction is allowed as a table name in PostgreSQL (it is not a reserved word). Alias it as t in queries.
Pattern 'one borrower': a row points to EXACTLY ONE of student_id / teacher_id, enforced with
CHECK ((student_id IS NOT NULL)::int + (teacher_id IS NOT NULL)::int = 1).

TABLES (14)
staff           staff_id PK; first_name; middle_name null; last_name; email unique; password_hash;
                position CHECK in (librarian, admin); contact_number varchar(20); date_hired date;
                is_active bool default true; must_change_password bool default false
student         student_id PK; first_name; middle_name null; last_name; course; year_level smallint CHECK 1..6;
                contact_number varchar(20); email unique; password_hash; is_active default true;
                must_change_password default false; date_registered date default current_date
teacher         teacher_id PK; first_name; middle_name null; last_name; department; contact_number varchar(20);
                email unique; password_hash; is_active; must_change_password; date_registered
                Rule: a student email can never equal a teacher email (trigger, case-insensitive),
                because members sign in with one form.
book            book_id PK; isbn varchar(17) unique null; title; author; category;
                copies_total int CHECK >= 0; copies_available int CHECK between 0 and copies_total;
                replacement_cost numeric(10,2) CHECK >= 0; added_by_id FK staff; date_added date default current_date;
                is_archived bool default false; copy_limit int default 5 CHECK 1..999 (ck_book_copy_limit);
                CHECK copies_total <= copy_limit (ck_book_within_limit). Rare titles get a limit of 1 or 2.
                Only an admin changes copy_limit, through fn_set_copy_limit. The site cannot insert it.
book_request    request_id PK; student_id FK null; teacher_id FK null; book_id FK; date_requested timestamptz default now();
                status CHECK in (pending, approved, rejected, fulfilled, expired, cancelled) default pending;
                decided_by_id FK staff null; date_decided null; pickup_date date null; pickup_time time null;
                pickup_deadline date null; decision_note text null
                Rules: one borrower. Partial unique index: one open (pending or approved) request per borrower per book.
transaction     transaction_id PK; student_id FK null; teacher_id FK null; book_id FK; staff_id FK (who handed it over);
                request_id FK null unique; date_borrowed date; due_date date CHECK >= date_borrowed; date_returned date null;
                status CHECK in (borrowed, overdue, returned, lost)
                Rules: one borrower. status = returned if and only if date_returned is not null.
fine            fine_id PK; transaction_id FK; fine_type CHECK in (overdue, lost, damaged); amount numeric(10,2) CHECK >= 0;
                date_issued date default current_date; date_paid date null;
                status CHECK in (unpaid, paid, waived) default unpaid; waived_reason text null
                Rules: paid if and only if date_paid is not null. waived needs a reason.
                At most one overdue fine per transaction (partial unique index).
message         message_id PK; sender_staff_id FK staff null (null = system message);
                recipient_student_id FK null; recipient_teacher_id FK null; request_id FK null;
                kind CHECK in (request_approved, request_rejected, reminder, overdue, general);
                subject varchar(150); body text; sent_at timestamptz default now(); read_at timestamptz null
                Rule: exactly one recipient.
message_template template_id PK; kind CHECK in (request_approved, request_rejected, reminder, overdue);
                label varchar(60); subject varchar(150); body text; is_active bool default true; sort_order int
library_setting setting_key text PK; setting_value text; description text
                Seeds: library_name='School Library'; library_hours='Mon-Fri 8:00 AM-5:00 PM';
                loan_days_student=14; loan_days_teacher=14; max_loans_student=5; max_loans_teacher=8;
                fine_per_day=10.00; pickup_window_days=2
audit_log       audit_id PK; actor_type CHECK in (staff, student, teacher, system); actor_id int null; action text;
                table_name text; record_id text; detail jsonb; created_at timestamptz default now()
login_attempt   attempt_id PK; email; user_type; success bool; ip inet; attempted_at timestamptz default now()
                Index on (email, ip, attempted_at). Written only by fn_record_login.
password_reset  reset_id bigint PK; student_id FK null; teacher_id FK null; staff_id FK null (ON DELETE CASCADE);
                token_hash char(64) unique (sha256 hex of the emailed token, never the token); requested_at default now();
                expires_at; used_at null; ip inet null
                Rules: exactly one owner; expires_at > requested_at. Indexes on each owner and on (ip, requested_at).
                No audit trigger (it would copy token_hash); the routines write their own audit rows.
app_session     id varchar(256) PK (letters, digits, comma, hyphen; 16 or more); data text; updated_at timestamptz
                PHP sessions when SESSION_DRIVER=db. Only fn_session_* touch it; no audit trigger.

DELETE RULES
History tables (transaction, fine, book_request, message) reference people and books with ON DELETE RESTRICT.
A person or book with history is deactivated or archived, never deleted.

# SPEC part 2: routines, views, roles
ROUTINES (PL/pgSQL). Each runs in one transaction, locks the book row with SELECT ... FOR UPDATE before changing
copies, and raises a readable error message when a rule is broken. Only these routines change copies_available.
fn_create_request(student_id, teacher_id, book_id) -> request_id
   Fails if: the person is inactive; has an unpaid fine; has an overdue loan; active loans + open requests >= their limit;
   already has an open request for this book; the book is archived; the book has no copies left at all
   (copies_total = 0, every copy lost). A book whose copies are all on loan can be requested: the request waits,
   and fn_approve_request refuses it (LM003) until a copy is free.
fn_cancel_request(request_id, actor_type, actor_id, note)
   The owner or any staff member, only while pending or approved. If approved, copies_available + 1. When staff cancel, a general message with the note goes to the borrower.
fn_approve_request(request_id, staff_id, pickup_date, pickup_time, subject, body) -> message_id
   Only pending. Needs copies_available >= 1. copies_available - 1 (the copy is reserved). status approved.
   pickup_deadline = pickup_date + pickup_window_days. Inserts a request_approved message.
fn_reject_request(request_id, staff_id, reason, subject, body) -> message_id
   Only pending. reason is required and saved in decision_note. Inserts a request_rejected message.
fn_issue_book(request_id, staff_id) -> transaction_id
   Only approved. No copy change (already reserved). due_date = today + loan days for that borrower type.
   The request becomes fulfilled.
fn_issue_book_direct(student_id, teacher_id, book_id, staff_id) -> transaction_id
   Walk-in. Same checks as fn_create_request. copies_available - 1.
fn_return_book(transaction_id, staff_id, condition, damage_amount) -> fine_id or null
   condition is good, damaged or lost. good and damaged: status returned, date_returned today, copies_available + 1.
   lost: status lost, copies_total - 1, a lost fine of replacement_cost.
   Late: one overdue fine of days_late * fine_per_day. damaged adds a damaged fine of damage_amount.
fn_pay_fine(fine_id, staff_id)
fn_waive_fine(fine_id, staff_id, reason)
fn_set_copies_total(book_id, new_total, staff_id)
   copies_available changes by the same difference. Error if it would go below 0
   or above the title's copy_limit ("“Title” can have at most N copies.").
fn_set_copy_limit(book_id, new_limit, staff_id)
   Admin only. new_limit 1..999 and never below copies_total ("Remove N copies first").
fn_set_user_active(user_type, user_id, active, staff_id)
   Deactivating fails while the person has borrowed or overdue loans or approved (reserved) requests.
   On deactivate, their pending requests are cancelled.
fn_delete_user(user_type, user_id, staff_id)
   Only when there are no rows in transaction, book_request or message. Admin only. Never yourself.
fn_mark_message_read(message_id, student_id, teacher_id)
   Sets read_at only when the message belongs to that person.
fn_broadcast_message(audience, subject, body, staff_id) -> count
   audience is all_students, all_teachers or everyone. Inserts one general message per active recipient.
fn_send_message(staff_id, student_id, teacher_id, kind, subject, body) -> message_id
   One borrower. kind is reminder, overdue or general. Subject and body are required.
fn_render_template(template_id, request_id, transaction_id, pickup_date, pickup_time) -> (subject, body)
   Use request_id for approval and rejection templates, transaction_id for reminder and overdue templates.
   Replaces the placeholders listed under Message presets. Rendering lives only here so PHP and tests agree.
sp_mark_overdue()        borrowed loans past due_date become overdue.
sp_expire_reservations() approved requests past pickup_deadline become expired, copies_available + 1,
                         and a system message goes to the borrower.

AUTH ROUTINES (04b_auth.sql; PHP keeps password_hash, password_verify and password_needs_rehash)
fn_login_lookup(email, ip, kind member|staff) -> one row: locked, tries_left, lock_minutes, and when not locked
   user_type, user_id, role, display_name, password_hash, is_active, must_change_password.
   Lock-out: 5 failures in 15 minutes since the last success, counted per email and per IP.
   Members: student, then teacher. Staff: staff.
fn_record_login(email, ip, kind, success, user_type, user_id)
   Inserts login_attempt. A success must name the active account with that email, and writes audit 'login'.
fn_logout(user_type, user_id)   audit 'logout'.
fn_set_password(user_type, user_id, new_hash, must_change)
   Your own password only (the transaction's actor must be that account). Refuses anything that is not
   password_hash() output ($2y$ bcrypt or $argon2). Audit 'password_changed'.
fn_staff_reset_password(staff_id, user_type, user_id, temp_hash)
   Any librarian for students and teachers, only an admin for staff, never yourself. must_change_password = true.
   Audit 'password_reset_by_staff'.
fn_register_member(user_type student|teacher, first, middle, last, email, hash, course, year_level, department,
                   contact_number, ip) -> id
   Never staff. One email across student, teacher and staff. Real hash only. At most 5 sign-ups per IP per hour
   (audit rows 'sign_up' with detail ip). Active, must_change_password false. Audit 'sign_up'.
fn_request_password_reset(email, ip, token_hash) -> user_type, user_id, first_name, or no row
   Active accounts of all three kinds. Link valid 30 minutes. At most 3 open links per account and 5 per IP per hour.
   Unknown, inactive or over the limit: no row and no error. Audit 'password_reset_requested'.
fn_check_reset_token(token_hash) -> the account if the link is unused, unexpired and the account active.
fn_reset_password(token_hash, new_hash) -> user_type, user_id, first_name, email
   Same hash check, must_change_password false, marks this link and every other open link of the account used.
   Otherwise LM001 "This reset link has expired or was already used. Ask for a new one." Audit 'password_reset'.
Audit detail never holds a password, a hash or a token. The password routines set lms.audit_quiet so the
trigger does not add a second, password-only "update" row.
fn_session_read(id) -> data or NULL; fn_session_write(id, data) (insert or replace); fn_session_destroy(id);
fn_session_gc(max_seconds) -> rows removed. Used by src/session.php when SESSION_DRIVER=db.

CREATE AND READ (04c_crud.sql; owners and pages in docs/MEMBER_TASKS.md)
Lists take optional filters plus p_limit (default 25) and p_offset (default 0) and return total_count on every row.
None returns password_hash.
Book         fn_create_book(isbn, title, author, category, copies_total, replacement_cost, staff_id) -> book_id
               ISBN-10/13 check digit, stored as ISBN-13; duplicate ISBN refused; 1 to 5 copies; category tidied.
             fn_update_book_details(book_id, isbn, title, author, category, replacement_cost, staff_id)
             fn_list_books(search, category, only_available, include_archived, limit, offset); fn_get_book(book_id);
             fn_list_categories(include_archived)
Student      fn_create_student(first, middle, last, course, year_level, contact, email, password_hash, staff_id) -> id
             (must_change_password = true); fn_update_student(...); fn_list_students(search, active, limit, offset);
             fn_get_student(id); fn_list_courses()
Teacher      fn_create_teacher, fn_update_teacher, fn_list_teachers, fn_get_teacher, fn_list_departments (the same shape)
Staff        fn_create_staff(first, middle, last, email, password_hash, position, contact, acting_staff_id, date_hired)
             -> id, admin only; fn_update_staff(...) admin only, never the last active admin;
             fn_list_staff(search, active, limit, offset); fn_get_staff(id)
Own details  fn_update_my_profile(user_type, user_id, first, middle, last, contact, email) - the actor must be that
             account; staff emails are changed by an admin.
Transaction  create = fn_issue_book, fn_issue_book_direct. fn_list_transactions(status, search, date_from, date_to,
             student_id, teacher_id, limit, offset) - status borrowed, overdue, returned, lost (as displayed), active,
             past or all; fn_get_transaction(id)
Fine         fn_create_fine(transaction_id, fine_type, amount, staff_id) -> fine_id: the loan exists, the kind is
             overdue, lost or damaged, amount > 0, at most one overdue fine per loan.
             fn_list_fines(status, search, student_id, teacher_id, limit, offset); fn_get_fine(id)

TRIGGER
fn_audit() runs after insert, update and delete on staff, student, teacher, book, book_request, transaction, fine and
library_setting. It reads current_setting('app.actor_type', true) and app.actor_id, which PHP sets at the start of every
database transaction. It never stores password_hash values.

VIEWS
v_user_directory   staff, students and teachers as one list: user_type, user_id, full_name, email, contact_number,
                   detail (course and year / department / position), is_active, joined_on
v_book_detail      book + availability word; copy_limit is the last column
v_request_detail   request + borrower name, type and email + book title, author and copies_available + the borrower's
                   active loan count and unpaid fine total + the deciding staff name
v_active_loans     borrowed and overdue transactions + borrower + book + days_late + fine_so_far
v_fine_detail      fine + transaction + borrower + book
v_inbox            message + sender name + is_read
v_dashboard_stats  ONE row: total_titles, total_copies, copies_available, copies_on_loan, pending_requests,
                   awaiting_pickup, overdue_loans, unpaid_fines_total, active_members
v_monthly_activity month, borrows, returns, fines_issued (COUNT and SUM with GROUP BY)
v_popular_books    book_id, title, borrow_count (top 10, JOIN with COUNT)
v_category_breakdown category, titles, copies, on_loan

DATABASE ROLES
lms_owner   owns every object. Used only to run the SQL files.
lms_app     the PHP login. CONNECT, USAGE on the schema, SELECT/INSERT/UPDATE where needed, EXECUTE on routines.
            No DELETE on history tables. No DDL. Not a superuser. No access to password_reset, no INSERT on
            login_attempt, and no EXECUTE on the internal helpers fn_auth_store_hash and fn_auth_account.
            SELECT on staff, student and teacher is column by column without password_hash. No INSERT or UPDATE on
            staff, student, teacher or book: those go through the routines. The only direct write is
            library_setting.setting_value.
lms_report  SELECT on views only.
REVOKE ALL on the public schema and on every table from PUBLIC.

# SPEC part 3: message presets
PLACEHOLDERS (filled in by fn_render_template)
{borrower_name} {book_title} {pickup_date} {pickup_time} {pickup_deadline} {loan_days} {due_date}
{librarian_name} {library_name} {library_hours} {active_loans} {max_loans} {fine_total} {fine_per_day}

REQUEST APPROVED (kind request_approved)
1. Standard pickup
   Subject: Your request for {book_title} is approved
   Body: Hi {borrower_name}, good news: your request for “{book_title}” is approved. Please pick it up at the library on {pickup_date} at {pickup_time}. Bring your school ID. We will hold it until {pickup_deadline}, then it goes back on the shelf. Loan period: {loan_days} days. {librarian_name}, {library_name}
2. Pickup today
   Subject: {book_title} is ready today
   Body: Hi {borrower_name}, “{book_title}” is ready for you. Come by today, {pickup_date}, from {pickup_time}. Library hours: {library_hours}. Bring your school ID. {librarian_name}
3. Flexible pickup
   Subject: {book_title} is reserved for you
   Body: Hi {borrower_name}, we reserved “{book_title}” for you. Pick it up any time during library hours ({library_hours}) until {pickup_deadline}. Please bring your school ID. {librarian_name}
4. Last copy
   Subject: You got the last copy of {book_title}
   Body: Hi {borrower_name}, you got the last copy of “{book_title}”. We will hold it until {pickup_deadline}. Pickup on {pickup_date} at {pickup_time} works best. Please return it by the due date so the next reader can borrow it. {librarian_name}

REQUEST REJECTED (kind request_rejected)
1. All copies on loan
   Subject: {book_title} is not available right now
   Body: Hi {borrower_name}, all copies of “{book_title}” are on loan at the moment, so we cannot approve your request. Please request it again in a few days or choose another title. {librarian_name}
2. Borrowing limit reached
   Subject: Borrowing limit reached
   Body: Hi {borrower_name}, you have {active_loans} of {max_loans} allowed loans, so we cannot approve “{book_title}” yet. Return a book, then request again. {librarian_name}
3. Unpaid fine
   Subject: Please settle your fine first
   Body: Hi {borrower_name}, you have an unpaid fine of ₱{fine_total}. Please settle it at the library, then request “{book_title}” again. {librarian_name}
4. Overdue book
   Subject: Please return your overdue book first
   Body: Hi {borrower_name}, you still have an overdue book. Please return it first, then request “{book_title}” again. {librarian_name}
5. Reference only
   Subject: {book_title} cannot be borrowed
   Body: Hi {borrower_name}, “{book_title}” is a reference book and stays in the library. You can read it here during {library_hours}. {librarian_name}
6. Account inactive
   Subject: Your library account is inactive
   Body: Hi {borrower_name}, your library account is inactive, so we cannot approve “{book_title}”. Please visit the library to reactivate it. {librarian_name}

REMINDER (kind reminder)
   Subject: {book_title} is due on {due_date}
   Body: Hi {borrower_name}, “{book_title}” is due on {due_date}. Please return it to the library desk on time. {librarian_name}

OVERDUE (kind overdue)
   Subject: {book_title} is overdue
   Body: Hi {borrower_name}, “{book_title}” was due on {due_date}. The fine is ₱{fine_per_day} a day until it is returned. Please bring it to the library desk as soon as you can. {librarian_name}
