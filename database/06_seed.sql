-- 06_seed.sql: the starting data: settings, message presets, the office admin and the first 12 books.
-- (07_seed_catalog.sql adds the other 217 books.) There are no sample people: the office admin is the only account.
--
--   * The office admin's email comes from OFFICE_ADMIN_EMAIL in .env; the reset scripts pass it as the psql
--     variable office_email. Its password is NOT here: the placeholder '!' can never match, and
--     database/seed_passwords.php then sets OFFICE_ADMIN_PASSWORD from .env (the reset script runs it for you).
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET search_path = lms, public;
SET TIME ZONE 'Asia/Manila';

\if :{?office_email}
\else
\set office_email ''
\endif

-- ---------------------------------------------------------------------------
-- Session-only helpers
-- ---------------------------------------------------------------------------
CREATE FUNCTION pg_temp.act(p_type text, p_id int) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  PERFORM set_config('app.actor_type', p_type, true), set_config('app.actor_id', coalesce(p_id::text, ''), true);
END $$;

-- A time p_days_ago days back, at a clock time, in Manila.
CREATE FUNCTION pg_temp.at(p_days_ago int, p_time time) RETURNS timestamptz LANGUAGE sql STABLE AS $$
  SELECT ((current_date - p_days_ago) + p_time)::timestamptz
$$;

-- Give every audit row written since the last call the time the event happened in the story.
CREATE TEMP TABLE seed_cursor (last_id bigint NOT NULL);
INSERT INTO seed_cursor SELECT coalesce(max(audit_id), 0) FROM lms.audit_log;
CREATE FUNCTION pg_temp.stamp(p_at timestamptz) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  UPDATE lms.audit_log SET created_at = p_at WHERE audit_id > (SELECT last_id FROM seed_cursor);
  UPDATE seed_cursor SET last_id = (SELECT coalesce(max(audit_id), 0) FROM lms.audit_log);
END $$;

-- ---------------------------------------------------------------------------
-- Settings and message presets (docs/SPEC.md parts 1 and 3)
-- ---------------------------------------------------------------------------
INSERT INTO library_setting (setting_key, setting_value, description) VALUES
  ('library_name',         'School Library',          'Name used on the sign-in page and in messages'),
  ('library_hours',        'Mon-Fri 8:00 AM-5:00 PM', 'Open days and hours, written like Mon-Fri 8:00 AM-5:00 PM'),
  ('loan_days_student',    '14',                      'Loan period for students, in days'),
  ('loan_days_teacher',    '14',                      'Loan period for teachers, in days'),
  ('max_loans_student',    '5',                       'Most loans plus open requests a student can have at once'),
  ('max_loans_teacher',    '8',                       'Most loans plus open requests a teacher can have at once'),
  ('fine_per_day',         '10.00',                   'Overdue fine per day, in pesos'),
  ('pickup_window_days',   '2',                       'Days an approved book is held after the pickup date'),
  ('session_idle_minutes', '30',                      'Minutes without activity before someone is signed out');

INSERT INTO message_template (kind, label, subject, body, sort_order) VALUES
  ('request_approved', 'Standard pickup', 'Your request for {book_title} is approved',
   'Hi {borrower_name}, good news: your request for “{book_title}” is approved. Please pick it up at the library on {pickup_date} at {pickup_time}. Bring your school ID. We will hold it until {pickup_deadline}, then it goes back on the shelf. Loan period: {loan_days} days. {librarian_name}, {library_name}', 1),
  ('request_approved', 'Pickup today', '{book_title} is ready today',
   'Hi {borrower_name}, “{book_title}” is ready for you. Come by today, {pickup_date}, from {pickup_time}. Library hours: {library_hours}. Bring your school ID. {librarian_name}', 2),
  ('request_approved', 'Flexible pickup', '{book_title} is reserved for you',
   'Hi {borrower_name}, we reserved “{book_title}” for you. Pick it up any time during library hours ({library_hours}) until {pickup_deadline}. Please bring your school ID. {librarian_name}', 3),
  ('request_approved', 'Last copy', 'You got the last copy of {book_title}',
   'Hi {borrower_name}, you got the last copy of “{book_title}”. We will hold it until {pickup_deadline}. Pickup on {pickup_date} at {pickup_time} works best. Please return it by the due date so the next reader can borrow it. {librarian_name}', 4),
  ('request_rejected', 'All copies on loan', '{book_title} is not available right now',
   'Hi {borrower_name}, all copies of “{book_title}” are on loan at the moment, so we cannot approve your request. Please request it again in a few days or choose another title. {librarian_name}', 1),
  ('request_rejected', 'Borrowing limit reached', 'Borrowing limit reached',
   'Hi {borrower_name}, you have {active_loans} of {max_loans} allowed loans, so we cannot approve “{book_title}” yet. Return a book, then request again. {librarian_name}', 2),
  ('request_rejected', 'Unpaid fine', 'Please settle your fine first',
   'Hi {borrower_name}, you have an unpaid fine of ₱{fine_total}. Please settle it at the library, then request “{book_title}” again. {librarian_name}', 3),
  ('request_rejected', 'Overdue book', 'Please return your overdue book first',
   'Hi {borrower_name}, you still have an overdue book. Please return it first, then request “{book_title}” again. {librarian_name}', 4),
  ('request_rejected', 'Reference only', '{book_title} cannot be borrowed',
   'Hi {borrower_name}, “{book_title}” is a reference book and stays in the library. You can read it here during {library_hours}. {librarian_name}', 5),
  ('request_rejected', 'Account inactive', 'Your library account is inactive',
   'Hi {borrower_name}, your library account is inactive, so we cannot approve “{book_title}”. Please visit the library to reactivate it. {librarian_name}', 6),
  ('reminder', 'Due date reminder', '{book_title} is due on {due_date}',
   'Hi {borrower_name}, “{book_title}” is due on {due_date}. Please return it to the library desk on time. {librarian_name}', 1),
  ('overdue', 'Overdue notice', '{book_title} is overdue',
   'Hi {borrower_name}, “{book_title}” was due on {due_date}. The fine is ₱{fine_per_day} a day until it is returned. Please bring it to the library desk as soon as you can. {librarian_name}', 1);

DO $$ BEGIN PERFORM pg_temp.stamp(pg_temp.at(400, '08:00')); END $$;

-- ---------------------------------------------------------------------------
-- The office admin: the only account. Every admin can do everything (staff, settings, activity, deleting users).
-- ---------------------------------------------------------------------------
INSERT INTO staff (first_name, last_name, email, password_hash, position, date_hired)
SELECT 'Library', 'Management Office', lower(btrim(:'office_email')), '!', 'admin', current_date - 120
 WHERE btrim(:'office_email') <> '';
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM staff) THEN
    RAISE EXCEPTION 'Set OFFICE_ADMIN_EMAIL in .env (the office admin''s email), then run the reset again.';
  END IF;
END $$;
DO $$ BEGIN PERFORM pg_temp.stamp(pg_temp.at(120, '08:30')); END $$;

-- ---------------------------------------------------------------------------
-- Books: 12 titles, 6 categories, 3 to 5 copies, valid ISBN-13s. Added by the office admin.
-- copies_available is set to copies_total by a trigger on insert.
-- ---------------------------------------------------------------------------
BEGIN;
DO $$ BEGIN PERFORM pg_temp.act('staff', (SELECT staff_id FROM staff ORDER BY staff_id LIMIT 1)); END $$;
INSERT INTO book (isbn, title, author, category, copies_total, copies_available, replacement_cost, added_by_id, date_added)
SELECT v.isbn, v.title, v.author, v.category, v.copies, v.copies, v.cost,
       (SELECT staff_id FROM staff ORDER BY staff_id LIMIT 1), current_date - 120
  FROM (VALUES
    ('978-0132350884', 'Clean Code',                                'Robert C. Martin',            'Programming', 4, 2450.00),
    ('978-0135957059', 'The Pragmatic Programmer',                  'David Thomas and Andrew Hunt', 'Programming', 3, 2600.00),
    ('978-0078022159', 'Database System Concepts',                  'Abraham Silberschatz',        'Databases',   4, 3800.00),
    ('978-0133970777', 'Fundamentals of Database Systems',          'Ramez Elmasri',               'Databases',   3, 3500.00),
    ('978-0133594140', 'Computer Networking: A Top-Down Approach',  'James F. Kurose',             'Networking',  4, 3900.00),
    ('978-0073376226', 'Data Communications and Networking',        'Behrouz A. Forouzan',         'Networking',  3, 3200.00),
    ('978-0073383095', 'Discrete Mathematics and Its Applications', 'Kenneth H. Rosen',            'Mathematics', 5, 3600.00),
    ('978-1285741550', 'Calculus: Early Transcendentals',           'James Stewart',               'Mathematics', 4, 4200.00),
    ('978-0143039693', 'Noli Me Tangere',                           'José Rizal',                  'Literature',  5,  650.00),
    ('978-0143106395', 'El Filibusterismo',                         'José Rizal',                  'Literature',  4,  700.00),
    ('978-0553380163', 'A Brief History of Time',                   'Stephen Hawking',             'Science',     3,  950.00),
    ('978-0345539434', 'Cosmos',                                    'Carl Sagan',                  'Science',     3, 1100.00)
  ) AS v (isbn, title, author, category, copies, cost);
DO $$ BEGIN PERFORM pg_temp.stamp(pg_temp.at(120, '10:00')); END $$;
COMMIT;
