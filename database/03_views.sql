-- 03_views.sql: the read models from docs/SPEC.md part 2. Pages read from these instead of repeating JOINs.
\set ON_ERROR_STOP on
SET search_path = lms, public;
SET client_min_messages = warning;

-- Reports can be limited to a period. PHP sets app.report_from (a date) in its transaction;
-- when it is not set the views cover all time.
CREATE OR REPLACE FUNCTION fn_report_from() RETURNS date
LANGUAGE plpgsql STABLE AS $$
DECLARE v text := nullif(current_setting('app.report_from', true), '');
BEGIN
  IF v IS NULL THEN RETURN '-infinity'::date; END IF;
  RETURN v::date;
EXCEPTION WHEN others THEN
  RETURN '-infinity'::date;
END $$;

-- A book with its loan and request counts (Manage Books, the details drawer and the member catalog).
-- availability: available (2 or more on the shelf), low (1), none (0).
CREATE OR REPLACE VIEW v_book_detail AS
SELECT b.book_id, b.isbn, b.title, b.author, b.category, b.copies_total, b.copies_available, b.replacement_cost,
       b.added_by_id, b.date_added, b.is_archived,
       s.first_name || ' ' || s.last_name   AS added_by_name,
       coalesce(x.on_loan, 0)::int          AS on_loan,
       coalesce(x.total_loans, 0)::int      AS total_loans,
       coalesce(r.reserved, 0)::int         AS reserved,
       coalesce(r.pending, 0)::int          AS pending_requests,
       coalesce(r.total_requests, 0)::int   AS total_requests,
       CASE WHEN b.copies_available = 0 THEN 'none'
            WHEN b.copies_available = 1 THEN 'low'
            ELSE 'available' END             AS availability,
       b.copy_limit                          -- last on purpose: CREATE OR REPLACE VIEW can only add columns at the end
  FROM book b
  JOIN staff s ON s.staff_id = b.added_by_id
  LEFT JOIN (SELECT book_id,
                    count(*) FILTER (WHERE status IN ('borrowed', 'overdue')) AS on_loan,
                    count(*) AS total_loans
               FROM transaction GROUP BY book_id) x ON x.book_id = b.book_id
  LEFT JOIN (SELECT book_id,
                    count(*) FILTER (WHERE status = 'approved') AS reserved,
                    count(*) FILTER (WHERE status = 'pending') AS pending,
                    count(*) AS total_requests
               FROM book_request GROUP BY book_id) r ON r.book_id = b.book_id;

-- Every student and teacher with what limits or blocks their borrowing (Issue Book, member home).
CREATE OR REPLACE VIEW v_borrower_status AS
WITH people AS (
  SELECT 'student'::text AS borrower_type, s.student_id AS borrower_id, s.student_id, NULL::int AS teacher_id,
         s.first_name, s.last_name, s.email, s.is_active, s.course || ', year ' || s.year_level AS detail
    FROM student s
  UNION ALL
  SELECT 'teacher', t.teacher_id, NULL, t.teacher_id, t.first_name, t.last_name, t.email, t.is_active, t.department
    FROM teacher t
)
SELECT p.borrower_type, p.borrower_id, p.student_id, p.teacher_id, p.first_name,
       p.first_name || ' ' || p.last_name AS full_name, p.email, p.is_active, p.detail,
       coalesce(l.active_loans, 0)::int    AS active_loans,
       coalesce(l.overdue_loans, 0)::int   AS overdue_loans,
       coalesce(r.open_requests, 0)::int   AS open_requests,
       coalesce(r.awaiting_pickup, 0)::int AS awaiting_pickup,
       coalesce(f.unpaid, 0)::numeric(10,2) AS unpaid_fines,
       CASE p.borrower_type WHEN 'student' THEN fn_setting_int('max_loans_student', 5)
                            ELSE fn_setting_int('max_loans_teacher', 8) END AS max_loans,
       CASE p.borrower_type WHEN 'student' THEN fn_setting_int('loan_days_student', 14)
                            ELSE fn_setting_int('loan_days_teacher', 14) END AS loan_days,
       current_date + CASE p.borrower_type WHEN 'student' THEN fn_setting_int('loan_days_student', 14)
                                           ELSE fn_setting_int('loan_days_teacher', 14) END AS due_if_today
  FROM people p
  LEFT JOIN LATERAL (
        SELECT count(*) FILTER (WHERE x.status IN ('borrowed', 'overdue')) AS active_loans,
               count(*) FILTER (WHERE x.status = 'overdue' OR (x.status = 'borrowed' AND x.due_date < current_date)) AS overdue_loans
          FROM transaction x WHERE x.student_id = p.student_id OR x.teacher_id = p.teacher_id) l ON true
  LEFT JOIN LATERAL (
        SELECT count(*) FILTER (WHERE rq.status IN ('pending', 'approved')) AS open_requests,
               count(*) FILTER (WHERE rq.status = 'approved') AS awaiting_pickup
          FROM book_request rq WHERE rq.student_id = p.student_id OR rq.teacher_id = p.teacher_id) r ON true
  LEFT JOIN LATERAL (
        SELECT sum(fi.amount) AS unpaid
          FROM fine fi JOIN transaction x ON x.transaction_id = fi.transaction_id
         WHERE fi.status = 'unpaid' AND (x.student_id = p.student_id OR x.teacher_id = p.teacher_id)) f ON true;

-- Staff, students and teachers as one list.
CREATE OR REPLACE VIEW v_user_directory AS
SELECT 'staff'::text            AS user_type,
       s.staff_id               AS user_id,
       s.first_name || ' ' || s.last_name AS full_name,
       s.email,
       s.contact_number,
       initcap(s.position)      AS detail,
       s.is_active,
       s.date_hired             AS joined_on,
       s.first_name, s.middle_name, s.last_name,
       NULL::varchar            AS course,
       NULL::smallint           AS year_level,
       NULL::varchar            AS department,
       s.position,
       s.must_change_password,
       (EXISTS (SELECT 1 FROM transaction x WHERE x.staff_id = s.staff_id)
        OR EXISTS (SELECT 1 FROM book_request r WHERE r.decided_by_id = s.staff_id)
        OR EXISTS (SELECT 1 FROM message m WHERE m.sender_staff_id = s.staff_id)
        OR EXISTS (SELECT 1 FROM book b WHERE b.added_by_id = s.staff_id)) AS has_history
  FROM staff s
UNION ALL
SELECT 'student', st.student_id, st.first_name || ' ' || st.last_name, st.email, st.contact_number,
       st.course || ', year ' || st.year_level, st.is_active, st.date_registered,
       st.first_name, st.middle_name, st.last_name, st.course, st.year_level, NULL, NULL, st.must_change_password,
       (EXISTS (SELECT 1 FROM transaction x WHERE x.student_id = st.student_id)
        OR EXISTS (SELECT 1 FROM book_request r WHERE r.student_id = st.student_id)
        OR EXISTS (SELECT 1 FROM message m WHERE m.recipient_student_id = st.student_id))
  FROM student st
UNION ALL
SELECT 'teacher', te.teacher_id, te.first_name || ' ' || te.last_name, te.email, te.contact_number,
       te.department, te.is_active, te.date_registered,
       te.first_name, te.middle_name, te.last_name, NULL, NULL, te.department, NULL, te.must_change_password,
       (EXISTS (SELECT 1 FROM transaction x WHERE x.teacher_id = te.teacher_id)
        OR EXISTS (SELECT 1 FROM book_request r WHERE r.teacher_id = te.teacher_id)
        OR EXISTS (SELECT 1 FROM message m WHERE m.recipient_teacher_id = te.teacher_id))
  FROM teacher te;

-- A request with everything the librarian needs to decide without opening another page.
CREATE OR REPLACE VIEW v_request_detail AS
SELECT r.request_id, r.student_id, r.teacher_id, r.book_id, r.date_requested, r.status,
       r.decided_by_id, r.date_decided, r.pickup_date, r.pickup_time, r.pickup_deadline, r.decision_note,
       CASE WHEN r.student_id IS NOT NULL THEN 'student' ELSE 'teacher' END AS borrower_type,
       coalesce(r.student_id, r.teacher_id)                                 AS borrower_id,
       coalesce(s.first_name, t.first_name)                                 AS borrower_first_name,
       coalesce(s.first_name || ' ' || s.last_name, t.first_name || ' ' || t.last_name) AS borrower_name,
       coalesce(s.email, t.email)                                           AS borrower_email,
       b.title AS book_title, b.author AS book_author, b.copies_available, b.copies_total,
       coalesce(la.active_loans, 0)::int  AS borrower_active_loans,
       coalesce(la.overdue_loans, 0)::int AS borrower_overdue_loans,
       CASE WHEN r.student_id IS NOT NULL THEN fn_setting_int('max_loans_student', 5)
            ELSE fn_setting_int('max_loans_teacher', 8) END AS borrower_max_loans,
       coalesce(uf.unpaid_total, 0)::numeric(10,2) AS borrower_unpaid_fines,
       st.first_name || ' ' || st.last_name AS decided_by_name,
       m.message_id, m.subject AS message_subject, m.body AS message_body,
       m.sent_at AS message_sent_at, m.read_at AS message_read_at
  FROM book_request r
  JOIN book b ON b.book_id = r.book_id
  LEFT JOIN student s ON s.student_id = r.student_id
  LEFT JOIN teacher t ON t.teacher_id = r.teacher_id
  LEFT JOIN staff st ON st.staff_id = r.decided_by_id
  LEFT JOIN LATERAL (
        SELECT count(*) FILTER (WHERE x.status IN ('borrowed', 'overdue')) AS active_loans,
               count(*) FILTER (WHERE x.status = 'overdue' OR (x.status = 'borrowed' AND x.due_date < current_date)) AS overdue_loans
          FROM transaction x
         WHERE x.student_id = r.student_id OR x.teacher_id = r.teacher_id) la ON true
  LEFT JOIN LATERAL (
        SELECT sum(f.amount) AS unpaid_total
          FROM fine f JOIN transaction x ON x.transaction_id = f.transaction_id
         WHERE f.status = 'unpaid' AND (x.student_id = r.student_id OR x.teacher_id = r.teacher_id)) uf ON true
  LEFT JOIN LATERAL (
        SELECT mm.message_id, mm.subject, mm.body, mm.sent_at, mm.read_at
          FROM message mm WHERE mm.request_id = r.request_id
         ORDER BY mm.sent_at DESC, mm.message_id DESC LIMIT 1) m ON true;

-- Every loan with borrower, book, lateness and fines. v_active_loans narrows it to open loans.
CREATE OR REPLACE VIEW v_loan_detail AS
SELECT x.transaction_id, x.student_id, x.teacher_id, x.book_id, x.staff_id, x.request_id,
       x.date_borrowed, x.due_date, x.date_returned, x.status,
       CASE WHEN x.status = 'borrowed' AND x.due_date < current_date THEN 'overdue' ELSE x.status END AS display_status,
       CASE WHEN x.student_id IS NOT NULL THEN 'student' ELSE 'teacher' END AS borrower_type,
       coalesce(x.student_id, x.teacher_id)                                 AS borrower_id,
       coalesce(s.first_name, t.first_name)                                 AS borrower_first_name,
       coalesce(s.first_name || ' ' || s.last_name, t.first_name || ' ' || t.last_name) AS borrower_name,
       coalesce(s.email, t.email)                                           AS borrower_email,
       b.title AS book_title, b.author AS book_author, b.isbn, b.replacement_cost,
       st.first_name || ' ' || st.last_name AS issued_by_name,
       CASE WHEN x.status IN ('borrowed', 'overdue') THEN greatest(current_date - x.due_date, 0) ELSE 0 END AS days_late,
       CASE WHEN x.status IN ('borrowed', 'overdue') THEN x.due_date - current_date END                     AS days_left,
       CASE WHEN x.status IN ('borrowed', 'overdue')
            THEN round(greatest(current_date - x.due_date, 0) * fn_setting_num('fine_per_day', 10), 2)
            ELSE 0 END::numeric(10,2)                                       AS fine_so_far,
       coalesce(fs.fines_total, 0)::numeric(10,2)  AS fines_total,
       coalesce(fs.fines_unpaid, 0)::numeric(10,2) AS fines_unpaid
  FROM transaction x
  JOIN book b ON b.book_id = x.book_id
  JOIN staff st ON st.staff_id = x.staff_id
  LEFT JOIN student s ON s.student_id = x.student_id
  LEFT JOIN teacher t ON t.teacher_id = x.teacher_id
  LEFT JOIN LATERAL (
        SELECT sum(f.amount) AS fines_total, sum(f.amount) FILTER (WHERE f.status = 'unpaid') AS fines_unpaid
          FROM fine f WHERE f.transaction_id = x.transaction_id) fs ON true;

CREATE OR REPLACE VIEW v_active_loans AS
SELECT * FROM v_loan_detail WHERE status IN ('borrowed', 'overdue');

CREATE OR REPLACE VIEW v_fine_detail AS
SELECT f.fine_id, f.transaction_id, f.fine_type, f.amount, f.date_issued, f.date_paid, f.status, f.waived_reason,
       x.book_id, x.date_borrowed, x.due_date, x.date_returned, x.status AS loan_status,
       x.student_id, x.teacher_id,
       CASE WHEN x.student_id IS NOT NULL THEN 'student' ELSE 'teacher' END AS borrower_type,
       coalesce(x.student_id, x.teacher_id)                                 AS borrower_id,
       coalesce(s.first_name || ' ' || s.last_name, t.first_name || ' ' || t.last_name) AS borrower_name,
       coalesce(s.email, t.email)                                           AS borrower_email,
       b.title AS book_title
  FROM fine f
  JOIN transaction x ON x.transaction_id = f.transaction_id
  JOIN book b ON b.book_id = x.book_id
  LEFT JOIN student s ON s.student_id = x.student_id
  LEFT JOIN teacher t ON t.teacher_id = x.teacher_id;

CREATE OR REPLACE VIEW v_inbox AS
SELECT m.message_id, m.sender_staff_id, m.recipient_student_id, m.recipient_teacher_id, m.request_id,
       m.kind, m.subject, m.body, m.sent_at, m.read_at,
       (m.read_at IS NOT NULL) AS is_read,
       coalesce(st.first_name || ' ' || st.last_name, 'Library system') AS sender_name,
       CASE WHEN m.recipient_student_id IS NOT NULL THEN 'student' ELSE 'teacher' END AS recipient_type,
       coalesce(s.first_name || ' ' || s.last_name, t.first_name || ' ' || t.last_name) AS recipient_name,
       coalesce(s.email, t.email) AS recipient_email,
       r.status AS request_status, r.pickup_date, r.pickup_time, r.pickup_deadline,
       b.title AS book_title
  FROM message m
  LEFT JOIN staff st ON st.staff_id = m.sender_staff_id
  LEFT JOIN student s ON s.student_id = m.recipient_student_id
  LEFT JOIN teacher t ON t.teacher_id = m.recipient_teacher_id
  LEFT JOIN book_request r ON r.request_id = m.request_id
  LEFT JOIN book b ON b.book_id = r.book_id;

-- ONE row with every number on the dashboard.
CREATE OR REPLACE VIEW v_dashboard_stats AS
SELECT
  (SELECT count(*) FROM book WHERE NOT is_archived)::int                                 AS total_titles,
  (SELECT coalesce(sum(copies_total), 0) FROM book WHERE NOT is_archived)::int           AS total_copies,
  (SELECT coalesce(sum(copies_available), 0) FROM book WHERE NOT is_archived)::int       AS copies_available,
  (SELECT count(*) FROM transaction WHERE status IN ('borrowed', 'overdue'))::int        AS copies_on_loan,
  (SELECT count(*) FROM book_request WHERE status = 'pending')::int                      AS pending_requests,
  (SELECT count(*) FROM book_request WHERE status = 'approved')::int                     AS awaiting_pickup,
  (SELECT count(*) FROM transaction
    WHERE status = 'overdue' OR (status = 'borrowed' AND due_date < current_date))::int  AS overdue_loans,
  (SELECT coalesce(sum(amount), 0) FROM fine WHERE status = 'unpaid')::numeric(12,2)     AS unpaid_fines_total,
  (SELECT count(*) FROM fine WHERE status = 'unpaid')::int                               AS unpaid_fines_count,
  ((SELECT count(*) FROM student WHERE is_active)
   + (SELECT count(*) FROM teacher WHERE is_active))::int                                AS active_members;

-- Borrows, returns and fines per month for the last 12 months (COUNT and SUM with GROUP BY).
CREATE OR REPLACE VIEW v_monthly_activity AS
WITH months AS (
  SELECT generate_series(date_trunc('month', current_date) - interval '11 months',
                         date_trunc('month', current_date), interval '1 month')::date AS month
), borrows AS (
  SELECT date_trunc('month', date_borrowed)::date AS month, count(*) AS borrows
    FROM transaction GROUP BY 1
), returns AS (
  SELECT date_trunc('month', date_returned)::date AS month, count(*) AS returns
    FROM transaction WHERE date_returned IS NOT NULL GROUP BY 1
), issued AS (
  SELECT date_trunc('month', date_issued)::date AS month, count(*) AS fines_issued, sum(amount) AS fines_issued_amount
    FROM fine GROUP BY 1
), collected AS (
  SELECT date_trunc('month', date_paid)::date AS month, count(*) AS fines_paid, sum(amount) AS fines_collected_amount
    FROM fine WHERE status = 'paid' GROUP BY 1
)
SELECT m.month,
       coalesce(b.borrows, 0)::int                       AS borrows,
       coalesce(r.returns, 0)::int                       AS returns,
       coalesce(i.fines_issued, 0)::int                  AS fines_issued,
       coalesce(i.fines_issued_amount, 0)::numeric(12,2) AS fines_issued_amount,
       coalesce(c.fines_paid, 0)::int                    AS fines_paid,
       coalesce(c.fines_collected_amount, 0)::numeric(12,2) AS fines_collected_amount
  FROM months m
  LEFT JOIN borrows b USING (month)
  LEFT JOIN returns r USING (month)
  LEFT JOIN issued i USING (month)
  LEFT JOIN collected c USING (month)
 WHERE m.month >= date_trunc('month', fn_report_from())
 ORDER BY m.month;

-- Top 10 most borrowed books in the report period (JOIN with COUNT).
CREATE OR REPLACE VIEW v_popular_books AS
SELECT b.book_id, b.title, b.author, count(x.transaction_id)::int AS borrow_count
  FROM book b
  JOIN transaction x ON x.book_id = b.book_id
 WHERE x.date_borrowed >= fn_report_from()
 GROUP BY b.book_id, b.title, b.author
 ORDER BY borrow_count DESC, b.title
 LIMIT 10;

-- Titles and copies per category today, plus borrows in the report period.
CREATE OR REPLACE VIEW v_category_breakdown AS
WITH shelf AS (
  SELECT category, count(*) AS titles, sum(copies_total) AS copies
    FROM book WHERE NOT is_archived GROUP BY category
), loans AS (
  SELECT bk.category,
         count(*) FILTER (WHERE x.status IN ('borrowed', 'overdue')) AS on_loan,
         count(*) FILTER (WHERE x.date_borrowed >= fn_report_from()) AS borrows
    FROM transaction x JOIN book bk ON bk.book_id = x.book_id
   GROUP BY bk.category
)
SELECT s.category, s.titles::int, s.copies::int,
       coalesce(l.on_loan, 0)::int AS on_loan,
       coalesce(l.borrows, 0)::int AS borrows
  FROM shelf s LEFT JOIN loans l USING (category)
 ORDER BY s.category;
