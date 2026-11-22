-- tests.sql: checks the library rules in the database. Each check prints one PASS or FAIL line.
--
-- It changes data, so it refuses to run on anything but a scratch copy whose name contains "test".
-- Run it with:   powershell -ExecutionPolicy Bypass -File database\run_tests.ps1
--          or:   bash database/run_tests.sh
-- Both rebuild the scratch database lms_test from the numbered SQL files, then run this file there.
-- Afterwards, SELECT * FROM lms.audit_log in lms_test shows who did what during the tests.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET search_path = lms, public;
SET TIME ZONE 'Asia/Manila';

DO $$
BEGIN
  IF current_database() !~ 'test' THEN
    RAISE EXCEPTION 'tests.sql changes data. Run it on a scratch copy (database\run_tests.ps1), not on %.', current_database();
  END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Test harness
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE t_result (n int PRIMARY KEY, name text NOT NULL, pass boolean NOT NULL, detail text);
CREATE TEMP TABLE t_ctx (key text PRIMARY KEY, id int NOT NULL);
CREATE TEMP TABLE t_meta AS SELECT (SELECT coalesce(max(audit_id), 0) FROM lms.audit_log) AS audit_from;

-- Act as someone: the same two settings PHP's db_tx() sets.
CREATE FUNCTION pg_temp.act(p_type text, p_id int) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  PERFORM set_config('app.actor_type', p_type, true), set_config('app.actor_id', coalesce(p_id::text, ''), true);
END $$;

CREATE FUNCTION pg_temp.id(p_key text) RETURNS int LANGUAGE sql AS $$
  SELECT id FROM t_ctx WHERE key = p_key
$$;

CREATE FUNCTION pg_temp.record(p_n int, p_name text, p_pass boolean, p_detail text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO t_result VALUES (p_n, p_name, coalesce(p_pass, false), p_detail)
  ON CONFLICT (n) DO UPDATE SET pass = excluded.pass, detail = excluded.detail;
END $$;

CREATE FUNCTION pg_temp.new_student(p_key text) RETURNS int LANGUAGE plpgsql AS $$
DECLARE v int;
BEGIN
  INSERT INTO lms.student (first_name, last_name, course, year_level, email, password_hash)
  VALUES ('Test', upper(p_key), 'BS Information Technology', 2, p_key || '.' || txid_current() || '@test.example', '!')
  RETURNING student_id INTO v;
  INSERT INTO t_ctx VALUES (p_key, v) ON CONFLICT (key) DO UPDATE SET id = excluded.id;
  RETURN v;
END $$;

CREATE FUNCTION pg_temp.new_book(p_key text, p_copies int, p_cost numeric) RETURNS int LANGUAGE plpgsql AS $$
DECLARE v int;
BEGIN
  INSERT INTO lms.book (title, author, category, copies_total, copies_available, replacement_cost, added_by_id)
  VALUES ('Test book ' || p_key, 'Test Author', 'Testing', p_copies, p_copies, p_cost, pg_temp.id('admin'))
  RETURNING book_id INTO v;
  RETURN v;
END $$;

CREATE FUNCTION pg_temp.avail(p_book int) RETURNS int LANGUAGE sql AS $$
  SELECT copies_available FROM lms.book WHERE book_id = p_book
$$;

-- Fixture staff (created by the system, like the seed).
DO $$
DECLARE v int;
BEGIN
  INSERT INTO staff (first_name, last_name, email, password_hash, position)
  VALUES ('Test', 'Admin', 'test.admin.' || txid_current() || '@test.example', '!', 'admin') RETURNING staff_id INTO v;
  INSERT INTO t_ctx VALUES ('admin', v);
  INSERT INTO staff (first_name, last_name, email, password_hash, position)
  VALUES ('Test', 'Librarian', 'test.librarian.' || txid_current() || '@test.example', '!', 'librarian') RETURNING staff_id INTO v;
  INSERT INTO t_ctx VALUES ('librarian', v);
END $$;

-- ---------------------------------------------------------------------------
-- 1. approve reserves a copy; reject does not
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s1 int; v_s2 int; v_b int; v_r1 int; v_r2 int; v_after_approve int; v_after_reject int; v_msgs int;
BEGIN
  v_s1 := pg_temp.new_student('t1a');
  v_s2 := pg_temp.new_student('t1b');
  v_b := pg_temp.new_book('t1', 3, 500);
  PERFORM pg_temp.act('student', v_s1);  v_r1 := fn_create_request(v_s1, NULL, v_b);
  PERFORM pg_temp.act('student', v_s2);  v_r2 := fn_create_request(v_s2, NULL, v_b);
  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_approve_request(v_r1, v_lib, current_date + 1, '09:00', 'Approved', 'Please pick it up tomorrow.');
  v_after_approve := pg_temp.avail(v_b);
  PERFORM fn_reject_request(v_r2, v_lib, 'All copies on loan', 'Not available', 'Sorry, all copies are out.');
  v_after_reject := pg_temp.avail(v_b);
  SELECT count(*) INTO v_msgs FROM message WHERE request_id IN (v_r1, v_r2);
  PERFORM pg_temp.record(1, 'approve reserves a copy; reject does not',
    v_after_approve = 2 AND v_after_reject = 2 AND v_msgs = 2,
    format('available after approve %s (want 2), after reject %s (want 2), messages %s (want 2)', v_after_approve, v_after_reject, v_msgs));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(1, 'approve reserves a copy; reject does not', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 2. cancelling an approved request gives the copy back
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int; v_b int; v_r int; v_mid int; v_end int; v_status text; v_notes int;
BEGIN
  v_s := pg_temp.new_student('t2');
  v_b := pg_temp.new_book('t2', 2, 500);
  PERFORM pg_temp.act('student', v_s);  v_r := fn_create_request(v_s, NULL, v_b);
  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_approve_request(v_r, v_lib, current_date, '10:00', 'Approved', 'Pick it up today.');
  v_mid := pg_temp.avail(v_b);
  PERFORM fn_cancel_request(v_r, 'staff', v_lib, 'Shelf audit this week.');
  v_end := pg_temp.avail(v_b);
  SELECT status INTO v_status FROM book_request WHERE request_id = v_r;
  SELECT count(*) INTO v_notes FROM message WHERE request_id = v_r AND kind = 'general';
  PERFORM pg_temp.record(2, 'cancelling an approved request gives the copy back',
    v_mid = 1 AND v_end = 2 AND v_status = 'cancelled' AND v_notes = 1,
    format('available %s -> %s (want 1 -> 2), status %s, cancel messages %s (want 1)', v_mid, v_end, v_status, v_notes));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(2, 'cancelling an approved request gives the copy back', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 3. issue after approve does not change copies again
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int; v_b int; v_r int; v_t int; v_before int; v_after int; v_status text; v_due date;
BEGIN
  v_s := pg_temp.new_student('t3');
  v_b := pg_temp.new_book('t3', 2, 500);
  PERFORM pg_temp.act('student', v_s);  v_r := fn_create_request(v_s, NULL, v_b);
  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_approve_request(v_r, v_lib, current_date, '10:00', 'Approved', 'Pick it up today.');
  v_before := pg_temp.avail(v_b);
  v_t := fn_issue_book(v_r, v_lib);
  v_after := pg_temp.avail(v_b);
  SELECT status INTO v_status FROM book_request WHERE request_id = v_r;
  SELECT due_date INTO v_due FROM transaction WHERE transaction_id = v_t;
  PERFORM pg_temp.record(3, 'issue after approve does not change copies again',
    v_before = 1 AND v_after = 1 AND v_status = 'fulfilled' AND v_due = current_date + fn_setting_int('loan_days_student', 14),
    format('available %s -> %s (want 1 -> 1), request %s (want fulfilled), due %s', v_before, v_after, v_status, v_due));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(3, 'issue after approve does not change copies again', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 4. a second open request for the same book is refused
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_s int; v_b int; v_refused boolean := false; v_msg text;
BEGIN
  v_s := pg_temp.new_student('t4');
  v_b := pg_temp.new_book('t4', 2, 500);
  PERFORM pg_temp.act('student', v_s);
  PERFORM fn_create_request(v_s, NULL, v_b);
  BEGIN
    PERFORM fn_create_request(v_s, NULL, v_b);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN
    v_refused := true; v_msg := SQLERRM;
  END;
  PERFORM pg_temp.record(4, 'a second open request for the same book is refused', v_refused,
    coalesce('refused with: ' || v_msg, 'the second request was accepted'));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(4, 'a second open request for the same book is refused', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 5. a borrower with an unpaid fine or an overdue loan cannot request
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int; v_b1 int; v_b2 int; v_t int; v_overdue_msg text; v_fine_msg text;
BEGIN
  v_s := pg_temp.new_student('t5');
  v_b1 := pg_temp.new_book('t5a', 2, 500);
  v_b2 := pg_temp.new_book('t5b', 2, 500);
  PERFORM pg_temp.act('staff', v_lib);
  v_t := fn_issue_book_direct(v_s, NULL, v_b1, v_lib);
  -- Test setup: pretend the loan started 17 days ago and was due 3 days ago.
  UPDATE transaction SET date_borrowed = current_date - 17, due_date = current_date - 3 WHERE transaction_id = v_t;

  PERFORM pg_temp.act('student', v_s);
  BEGIN
    PERFORM fn_create_request(v_s, NULL, v_b2);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_overdue_msg := SQLERRM;
  END;

  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_return_book(v_t, v_lib, 'good', NULL);  -- returned late, so an overdue fine is created

  PERFORM pg_temp.act('student', v_s);
  BEGIN
    PERFORM fn_create_request(v_s, NULL, v_b2);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_fine_msg := SQLERRM;
  END;

  PERFORM pg_temp.record(5, 'a borrower with an unpaid fine or an overdue loan cannot request',
    v_overdue_msg LIKE '%overdue book%' AND v_fine_msg LIKE '%unpaid fine%',
    format('overdue: %s | fine: %s', coalesce(v_overdue_msg, 'not refused'), coalesce(v_fine_msg, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(5, 'a borrower with an unpaid fine or an overdue loan cannot request', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 6. the borrowing limit is enforced
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_old text := fn_setting('max_loans_student');
  v_s int; v_b1 int; v_b2 int; v_b3 int; v_msg text;
BEGIN
  -- Test setup: a limit of 2 keeps the test short. Restored below.
  INSERT INTO library_setting (setting_key, setting_value, description) VALUES ('max_loans_student', '2', 'test')
  ON CONFLICT (setting_key) DO UPDATE SET setting_value = '2';
  v_s := pg_temp.new_student('t6');
  v_b1 := pg_temp.new_book('t6a', 2, 500);
  v_b2 := pg_temp.new_book('t6b', 2, 500);
  v_b3 := pg_temp.new_book('t6c', 2, 500);
  PERFORM pg_temp.act('student', v_s);
  PERFORM fn_create_request(v_s, NULL, v_b1);
  PERFORM fn_create_request(v_s, NULL, v_b2);
  BEGIN
    PERFORM fn_create_request(v_s, NULL, v_b3);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_msg := SQLERRM;
  END;
  PERFORM pg_temp.act('system', NULL);
  IF v_old IS NULL THEN
    DELETE FROM library_setting WHERE setting_key = 'max_loans_student';
  ELSE
    UPDATE library_setting SET setting_value = v_old WHERE setting_key = 'max_loans_student';
  END IF;
  PERFORM pg_temp.record(6, 'the borrowing limit is enforced', v_msg LIKE '%borrowing limit%',
    coalesce('third request refused with: ' || v_msg, 'the third request was accepted'));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(6, 'the borrowing limit is enforced', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 7. return late creates the right overdue fine; return lost reduces copies_total
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_sa int; v_sb int; v_ba int; v_bb int; v_t1 int; v_t2 int;
  v_fine numeric; v_want numeric; v_total int; v_avail int; v_lost numeric; v_status text;
BEGIN
  v_sa := pg_temp.new_student('t7a');
  v_sb := pg_temp.new_student('t7b');
  v_ba := pg_temp.new_book('t7a', 2, 800);
  v_bb := pg_temp.new_book('t7b', 3, 1250.50);
  PERFORM pg_temp.act('staff', v_lib);

  v_t1 := fn_issue_book_direct(v_sa, NULL, v_ba, v_lib);
  UPDATE transaction SET date_borrowed = current_date - 18, due_date = current_date - 4 WHERE transaction_id = v_t1;
  PERFORM fn_return_book(v_t1, v_lib, 'good', NULL);
  SELECT amount INTO v_fine FROM fine WHERE transaction_id = v_t1 AND fine_type = 'overdue';
  v_want := 4 * fn_setting_num('fine_per_day', 10);

  v_t2 := fn_issue_book_direct(v_sb, NULL, v_bb, v_lib);
  PERFORM fn_return_book(v_t2, v_lib, 'lost', NULL);
  SELECT copies_total, copies_available INTO v_total, v_avail FROM book WHERE book_id = v_bb;
  SELECT amount INTO v_lost FROM fine WHERE transaction_id = v_t2 AND fine_type = 'lost';
  SELECT status INTO v_status FROM transaction WHERE transaction_id = v_t2;

  PERFORM pg_temp.record(7, 'return late creates the right overdue fine; return lost reduces copies_total',
    v_fine = v_want AND v_total = 2 AND v_avail = 2 AND v_lost = 1250.50 AND v_status = 'lost',
    format('overdue fine %s (want %s); lost: total %s (want 2), available %s (want 2), fine %s (want 1250.50), status %s',
           v_fine, v_want, v_total, v_avail, v_lost, v_status));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(7, 'return late creates the right overdue fine; return lost reduces copies_total', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 8. an expired reservation gives the copy back and sends a message
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int; v_b int; v_r int; v_mid int; v_end int; v_status text; v_sys int;
BEGIN
  v_s := pg_temp.new_student('t8');
  v_b := pg_temp.new_book('t8', 2, 500);
  PERFORM pg_temp.act('student', v_s);  v_r := fn_create_request(v_s, NULL, v_b);
  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_approve_request(v_r, v_lib, current_date, '09:00', 'Approved', 'Pick it up today.');
  v_mid := pg_temp.avail(v_b);
  -- Test setup: the pickup window ended yesterday.
  UPDATE book_request SET pickup_date = current_date - 3, pickup_deadline = current_date - 1 WHERE request_id = v_r;
  CALL sp_expire_reservations();
  v_end := pg_temp.avail(v_b);
  SELECT status INTO v_status FROM book_request WHERE request_id = v_r;
  SELECT count(*) INTO v_sys FROM message WHERE request_id = v_r AND sender_staff_id IS NULL AND recipient_student_id = v_s;
  PERFORM pg_temp.record(8, 'an expired reservation gives the copy back and sends a message',
    v_mid = 1 AND v_end = 2 AND v_status = 'expired' AND v_sys = 1,
    format('available %s -> %s (want 1 -> 2), status %s, system messages %s (want 1)', v_mid, v_end, v_status, v_sys));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(8, 'an expired reservation gives the copy back and sends a message', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 9. a user with history cannot be deleted; deactivation is blocked while loans are open
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_admin int := pg_temp.id('admin');
  v_lib int := pg_temp.id('librarian');
  v_del_msg text; v_deact_msg text; v_lib_msg text; v_clean int; v_gone boolean;
BEGIN
  PERFORM pg_temp.act('staff', v_admin);
  BEGIN
    PERFORM fn_delete_user('student', pg_temp.id('t1a'), v_admin);  -- has a request and a message
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_del_msg := SQLERRM;
  END;
  BEGIN
    PERFORM fn_set_user_active('student', pg_temp.id('t3'), false, v_admin);  -- has a book out (test 3)
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_deact_msg := SQLERRM;
  END;
  -- Someone with no history can be deleted by an admin, but not by a librarian.
  v_clean := pg_temp.new_student('t9');
  PERFORM pg_temp.act('staff', v_lib);
  BEGIN
    PERFORM fn_delete_user('student', v_clean, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_lib_msg := SQLERRM;
  END;
  PERFORM pg_temp.act('staff', v_admin);
  PERFORM fn_delete_user('student', v_clean, v_admin);
  v_gone := NOT EXISTS (SELECT 1 FROM student WHERE student_id = v_clean);

  PERFORM pg_temp.record(9, 'a user with history cannot be deleted; deactivation is blocked while loans are open',
    v_del_msg LIKE '%history%' AND v_deact_msg LIKE '%1 borrowed book%' AND v_lib_msg IS NOT NULL AND v_gone,
    format('delete: %s | deactivate: %s | librarian delete: %s | clean user deleted: %s',
           coalesce(v_del_msg, 'not blocked'), coalesce(v_deact_msg, 'not blocked'), coalesce(v_lib_msg, 'not blocked'), v_gone));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(9, 'a user with history cannot be deleted; deactivation is blocked while loans are open', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 10. every change above leaves an audit_log row with the right actor
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_from bigint := (SELECT audit_from FROM t_meta);
  v_lib int := pg_temp.id('librarian');
  v_approved int; v_member_inserts int; v_member_wrong int; v_loans int; v_fines int; v_system int; v_hash_rows int;
  v_pw_detail jsonb;
BEGIN
  -- A password change is logged without the hash.
  PERFORM pg_temp.act('staff', v_lib);
  UPDATE student SET password_hash = '$2y$10$abcdefghijklmnopqrstuv' WHERE student_id = pg_temp.id('t2');
  SELECT detail INTO v_pw_detail FROM audit_log
   WHERE audit_id > v_from AND table_name = 'student' AND record_id = pg_temp.id('t2')::text AND action = 'update'
   ORDER BY audit_id DESC LIMIT 1;

  SELECT count(*) INTO v_approved FROM audit_log
   WHERE audit_id > v_from AND table_name = 'book_request' AND actor_type = 'staff' AND actor_id = v_lib
     AND detail -> 'status' ->> 'new' = 'approved';
  SELECT count(*), count(*) FILTER (WHERE actor_id IS DISTINCT FROM (detail ->> 'student_id')::int)
    INTO v_member_inserts, v_member_wrong FROM audit_log
   WHERE audit_id > v_from AND table_name = 'book_request' AND action = 'insert' AND actor_type = 'student';
  SELECT count(*) INTO v_loans FROM audit_log
   WHERE audit_id > v_from AND table_name = 'transaction' AND action = 'insert' AND actor_type = 'staff' AND actor_id = v_lib;
  SELECT count(*) INTO v_fines FROM audit_log
   WHERE audit_id > v_from AND table_name = 'fine' AND action = 'insert' AND actor_type = 'staff' AND actor_id = v_lib;
  SELECT count(*) INTO v_system FROM audit_log
   WHERE audit_id > v_from AND table_name = 'book_request' AND actor_type = 'system' AND actor_id IS NULL
     AND detail -> 'status' ->> 'new' = 'expired';
  SELECT count(*) INTO v_hash_rows FROM audit_log
   WHERE detail::text LIKE '%password_hash%' OR detail::text LIKE '%$2y$%';

  PERFORM pg_temp.record(10, 'every change above leaves an audit_log row with the right actor',
    v_approved >= 3 AND v_member_inserts >= 8 AND v_member_wrong = 0 AND v_loans >= 4 AND v_fines >= 3
      AND v_system >= 1 AND v_hash_rows = 0 AND (v_pw_detail ->> 'password_changed') = 'true',
    format('approvals by librarian %s, member requests %s (wrong actor %s), loans %s, fines %s, system expiries %s, rows with a hash %s, password change detail %s',
           v_approved, v_member_inserts, v_member_wrong, v_loans, v_fines, v_system, v_hash_rows, v_pw_detail));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(10, 'every change above leaves an audit_log row with the right actor', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 11. the app role cannot bypass the routines (05_roles.sql)
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_book int := (SELECT min(book_id) FROM book);
  v_copies text := 'allowed'; v_delete text := 'allowed'; v_active text := 'allowed'; v_ddl text := 'allowed';
  v_reads boolean; v_routine boolean; v_super boolean;
BEGIN
  SET LOCAL ROLE lms_app;
  BEGIN UPDATE book SET copies_available = 0 WHERE book_id = v_book;
  EXCEPTION WHEN insufficient_privilege THEN v_copies := 'blocked'; END;
  BEGIN DELETE FROM transaction WHERE false;
  EXCEPTION WHEN insufficient_privilege THEN v_delete := 'blocked'; END;
  BEGIN UPDATE student SET is_active = false WHERE false;
  EXCEPTION WHEN insufficient_privilege THEN v_active := 'blocked'; END;
  BEGIN CREATE TABLE lms.t_hack (x int);
  EXCEPTION WHEN insufficient_privilege THEN v_ddl := 'blocked'; END;
  v_reads := (SELECT total_titles >= 0 FROM v_dashboard_stats);
  v_routine := fn_setting_int('loan_days_student', 14) > 0;
  RESET ROLE;
  v_super := (SELECT rolsuper FROM pg_roles WHERE rolname = 'lms_app');
  PERFORM pg_temp.record(11, 'the app role cannot bypass the routines (copies, deletes, deactivation, DDL)',
    v_copies = 'blocked' AND v_delete = 'blocked' AND v_active = 'blocked' AND v_ddl = 'blocked' AND v_reads AND v_routine AND NOT v_super,
    format('update copies %s, delete loan %s, set is_active %s, create table %s, reads views %s, runs routines %s, superuser %s',
           v_copies, v_delete, v_active, v_ddl, v_reads, v_routine, v_super));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(11, 'the app role cannot bypass the routines (copies, deletes, deactivation, DDL)', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 12. the report role reads views only
-- ---------------------------------------------------------------------------
DO $$
DECLARE v_view boolean; v_detail boolean; v_table text := 'allowed';
BEGIN
  SET LOCAL ROLE lms_report;
  v_view := (SELECT count(*) = 1 FROM v_dashboard_stats);
  v_detail := (SELECT count(*) >= 0 FROM v_request_detail);
  BEGIN PERFORM 1 FROM student LIMIT 1;
  EXCEPTION WHEN insufficient_privilege THEN v_table := 'blocked'; END;
  RESET ROLE;
  PERFORM pg_temp.record(12, 'the report role reads views only', v_view AND v_detail AND v_table = 'blocked',
    format('dashboard view %s, request view %s, student table %s', v_view, v_detail, v_table));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(12, 'the report role reads views only', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 13. PUBLIC has no access to the data or the routines
-- ---------------------------------------------------------------------------
DO $$
DECLARE v_tables int; v_routines int; v_schema boolean;
BEGIN
  SELECT count(*) INTO v_tables FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE n.nspname = 'lms' AND c.relkind IN ('r', 'v') AND has_table_privilege('public', c.oid, 'SELECT');
  SELECT count(*) INTO v_routines FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
   WHERE n.nspname = 'lms' AND has_function_privilege('public', p.oid, 'EXECUTE');
  v_schema := has_schema_privilege('public', 'lms', 'USAGE');
  PERFORM pg_temp.record(13, 'PUBLIC has no access to the data or the routines', v_tables = 0 AND v_routines = 0 AND NOT v_schema,
    format('tables/views readable by PUBLIC %s, routines runnable by PUBLIC %s, schema usage %s', v_tables, v_routines, v_schema));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(13, 'PUBLIC has no access to the data or the routines', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 14. the per-title copy limit (Step 15)
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_admin int := pg_temp.id('admin');
  v_lib int := pg_temp.id('librarian');
  v_b int; v_insert text; v_raise text; v_lib_msg text; v_lower text; v_limit int; v_total int;
  v_app_update text := 'allowed'; v_app_insert text := 'allowed';
BEGIN
  v_b := pg_temp.new_book('t14', 3, 500);
  -- A new title may not start with more copies than the default limit (5).
  BEGIN
    INSERT INTO book (title, author, category, copies_total, copies_available, replacement_cost, added_by_id)
    VALUES ('Too many copies', 'Test Author', 'Testing', 6, 6, 1, v_admin);
  EXCEPTION WHEN check_violation THEN v_insert := SQLERRM;
  END;
  PERFORM pg_temp.act('staff', v_lib);
  BEGIN PERFORM fn_set_copies_total(v_b, 6, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_raise := SQLERRM; END;
  BEGIN PERFORM fn_set_copy_limit(v_b, 8, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_lib_msg := SQLERRM; END;
  -- The app role cannot choose a copy limit, neither when adding a book nor afterwards.
  SET LOCAL ROLE lms_app;
  BEGIN UPDATE book SET copy_limit = 50 WHERE book_id = v_b;
  EXCEPTION WHEN insufficient_privilege THEN v_app_update := 'blocked'; END;
  BEGIN
    INSERT INTO book (title, author, category, copies_total, copies_available, replacement_cost, added_by_id, copy_limit)
    VALUES ('Sneaky', 'Test Author', 'Testing', 1, 1, 1, v_admin, 50);
  EXCEPTION WHEN insufficient_privilege THEN v_app_insert := 'blocked'; END;
  RESET ROLE;
  -- An admin can raise the limit and then the copies; lowering below the copies is refused.
  PERFORM pg_temp.act('staff', v_admin);
  PERFORM fn_set_copy_limit(v_b, 8, v_admin);
  PERFORM fn_set_copies_total(v_b, 7, v_admin);
  SELECT copy_limit, copies_total INTO v_limit, v_total FROM book WHERE book_id = v_b;
  BEGIN PERFORM fn_set_copy_limit(v_b, 2, v_admin);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_lower := SQLERRM; END;
  PERFORM pg_temp.record(14, 'copies above the limit are refused; only an admin changes the limit, never below the copies',
    v_insert LIKE '%ck_book_within_limit%' AND v_raise LIKE '%at most 5 copies%' AND v_lib_msg IS NOT NULL
      AND v_app_update = 'blocked' AND v_app_insert = 'blocked' AND v_limit = 8 AND v_total = 7 AND v_lower LIKE '%Remove 5 copies first%',
    format('insert: %s | raise: %s | librarian: %s | app update %s, app insert %s | admin result %s of %s | lower: %s',
           coalesce(v_insert, 'not refused'), coalesce(v_raise, 'not refused'), coalesce(v_lib_msg, 'not refused'),
           v_app_update, v_app_insert, v_total, v_limit, coalesce(v_lower, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(14, 'copies above the limit are refused; only an admin changes the limit, never below the copies', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- Step 16 helpers: a value shaped like password_hash() output, and a 64-character hex token hash.
-- ---------------------------------------------------------------------------
CREATE FUNCTION pg_temp.fake_hash(p_seed text) RETURNS text LANGUAGE sql AS $$
  SELECT '$2y$10$' || left(translate(encode(sha256(p_seed::bytea), 'base64') || repeat('x', 53), '+=', './'), 53)
$$;
CREATE FUNCTION pg_temp.tok(p_seed text) RETURNS text LANGUAGE sql AS $$
  SELECT encode(sha256(p_seed::bytea), 'hex')
$$;

-- ---------------------------------------------------------------------------
-- 15. sign-up: one email per person across the three tables, never staff, only real hashes
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_email text := 'signup.' || txid_current() || '@test.example';
  v_id int; v_dup_member text; v_dup_teacher text; v_dup_staff text; v_staff text; v_hash text; v_year text;
  v_row record; v_ip_limit text;
BEGIN
  v_id := fn_register_member('student', 'Sid', NULL, 'Signup', v_email, pg_temp.fake_hash('a'),
                             'BS Information Technology', 2, NULL, NULL, NULL);
  INSERT INTO t_ctx VALUES ('signup_student', v_id);
  SELECT * INTO v_row FROM student WHERE student_id = v_id;
  -- The same email again, as a teacher, in capitals, and an email a staff member already has.
  BEGIN PERFORM fn_register_member('student', 'Dup', NULL, 'Again', v_email, pg_temp.fake_hash('b'), 'BSIT', 1, NULL, NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_dup_member := SQLERRM; END;
  BEGIN PERFORM fn_register_member('teacher', 'Dup', NULL, 'Teacher', upper(v_email), pg_temp.fake_hash('c'), NULL, NULL, 'IT', NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_dup_teacher := SQLERRM; END;
  BEGIN PERFORM fn_register_member('teacher', 'Dup', NULL, 'Staff', (SELECT email FROM staff WHERE staff_id = pg_temp.id('admin')),
                                   pg_temp.fake_hash('d'), NULL, NULL, 'IT', NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_dup_staff := SQLERRM; END;
  BEGIN PERFORM fn_register_member('staff', 'Sneaky', NULL, 'Staff', 'x' || v_email, pg_temp.fake_hash('e'), NULL, NULL, 'IT', NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_staff := SQLERRM; END;
  BEGIN PERFORM fn_register_member('student', 'Plain', NULL, 'Text', 'y' || v_email, 'hunter22', 'BSIT', 1, NULL, NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_hash := SQLERRM; END;
  BEGIN PERFORM fn_register_member('student', 'Year', NULL, 'Seven', 'z' || v_email, pg_temp.fake_hash('f'), 'BSIT', 7, NULL, NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_year := SQLERRM; END;
  -- Five sign-ups from one address in an hour are fine; the sixth is refused.
  FOR i IN 1..6 LOOP
    BEGIN
      PERFORM fn_register_member('teacher', 'Ip', NULL, 'Limit' || i, 'ip' || i || '.' || v_email, pg_temp.fake_hash('ip' || i),
                                 NULL, NULL, 'IT', NULL, '10.15.0.1');
    EXCEPTION WHEN SQLSTATE 'LM001' THEN v_ip_limit := i || ': ' || SQLERRM; END;
  END LOOP;
  PERFORM pg_temp.record(15, 'sign-up refuses a used email (any table), staff accounts, non-hashes and a 6th sign-up per IP per hour',
    v_row.is_active AND NOT v_row.must_change_password AND v_dup_member LIKE '%already has an account%'
      AND v_dup_teacher LIKE '%already has an account%' AND v_dup_staff LIKE '%already has an account%'
      AND v_staff LIKE '%only for students and teachers%' AND v_hash LIKE '%password_hash()%' AND v_year IS NOT NULL
      AND v_ip_limit LIKE '6:%Too many accounts%',
    format('active %s must_change %s | dup member: %s | dup teacher: %s | dup staff: %s | staff: %s | hash: %s | year 7: %s | ip: %s',
           v_row.is_active, v_row.must_change_password, coalesce(v_dup_member, 'not refused'), coalesce(v_dup_teacher, 'not refused'),
           coalesce(v_dup_staff, 'not refused'), coalesce(v_staff, 'not refused'), coalesce(v_hash, 'not refused'),
           coalesce(v_year, 'not refused'), coalesce(v_ip_limit, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(15, 'sign-up refuses a used email (any table), staff accounts, non-hashes and a 6th sign-up per IP per hour', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 16. sign-in lock-out: 5 failures lock the email (and the IP); the 6th try gets no account data
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_id int := pg_temp.id('signup_student');
  v_email text := (SELECT email FROM student WHERE student_id = v_id);
  v_before record; v_after4 record; v_after5 record; v_other_email record; v_other_ip record; v_staff_side record;
  v_login_ok boolean; v_forged text;
BEGIN
  SELECT * INTO v_before FROM fn_login_lookup(upper(v_email), '10.16.0.1', 'member');
  -- A correct sign-in is recorded with an audit row 'login', and sign-out with 'logout'.
  PERFORM fn_record_login(v_email, '10.16.0.1', 'member', true, 'student', v_id);
  PERFORM fn_logout('student', v_id);
  -- Forged success for someone else's account is refused.
  BEGIN PERFORM fn_record_login(v_email, '10.16.0.1', 'member', true, 'teacher', v_id);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_forged := SQLERRM; END;
  -- now() is the same all through this test, so move the success a minute back: failures count only after it.
  UPDATE login_attempt SET attempted_at = now() - interval '1 minute' WHERE email = v_email AND success;
  FOR i IN 1..4 LOOP PERFORM fn_record_login(v_email, '10.16.0.2', 'member', false); END LOOP;
  SELECT * INTO v_after4 FROM fn_login_lookup(v_email, '10.16.0.3', 'member');
  PERFORM fn_record_login(v_email, '10.16.0.2', 'member', false);
  SELECT * INTO v_after5 FROM fn_login_lookup(v_email, '10.16.0.3', 'member');      -- same email, new IP: locked
  SELECT * INTO v_other_email FROM fn_login_lookup('nobody.' || v_email, '10.16.0.2', 'member');  -- same IP: locked
  SELECT * INTO v_other_ip FROM fn_login_lookup((SELECT email FROM staff WHERE staff_id = pg_temp.id('admin')), '10.16.0.9', 'staff');
  SELECT * INTO v_staff_side FROM fn_login_lookup(v_email, '10.16.0.9', 'staff');
  v_login_ok := v_before.user_type = 'student' AND v_before.user_id = v_id AND v_before.password_hash LIKE '$2y$%' AND NOT v_before.locked;
  PERFORM pg_temp.record(16, 'five failed sign-ins lock the email and the IP for 15 minutes; the 6th try is refused',
    v_login_ok AND v_forged IS NOT NULL AND v_after4.tries_left = 1 AND NOT v_after4.locked
      AND v_after5.locked AND v_after5.password_hash IS NULL AND v_after5.user_id IS NULL AND v_after5.lock_minutes = 15
      AND v_other_email.locked AND NOT v_other_ip.locked AND v_other_ip.role = 'admin' AND v_staff_side.locked,
    format('lookup ok %s | forged: %s | after 4: locked %s, tries left %s | after 5: locked %s, hash returned %s | same IP: %s | other staff: %s | staff tab same email: %s',
           v_login_ok, coalesce(v_forged, 'not refused'), v_after4.locked, v_after4.tries_left, v_after5.locked,
           v_after5.password_hash IS NOT NULL, v_other_email.locked, v_other_ip.locked, v_staff_side.locked));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(16, 'five failed sign-ins lock the email and the IP for 15 minutes; the 6th try is refused', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 17. forgot password: a link works once, for 30 minutes, and only for an active account
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_id int := pg_temp.id('signup_student');
  v_email text := (SELECT email FROM student WHERE student_id = v_id);
  v_t1 text := pg_temp.tok('t17-1'); v_t2 text := pg_temp.tok('t17-2'); v_t3 text := pg_temp.tok('t17-3');
  v_req record; v_check record; v_done record; v_unknown int; v_inactive int; v_reuse text; v_expired text;
  v_t2_after int; v_minutes numeric; v_hash_ok boolean; v_open_limit int; v_ip_limit int; v_inactive_id int;
BEGIN
  SELECT * INTO v_req FROM fn_request_password_reset(upper(v_email), '10.17.0.1', v_t1);
  SELECT round(extract(epoch FROM expires_at - requested_at) / 60) INTO v_minutes FROM password_reset WHERE token_hash = v_t1;
  PERFORM fn_request_password_reset(v_email, '10.17.0.1', v_t2);
  SELECT * INTO v_check FROM fn_check_reset_token(v_t1);
  SELECT * INTO v_done FROM fn_reset_password(v_t1, pg_temp.fake_hash('new t17'));
  v_hash_ok := (SELECT password_hash = pg_temp.fake_hash('new t17') AND NOT must_change_password FROM student WHERE student_id = v_id);
  BEGIN PERFORM fn_reset_password(v_t1, pg_temp.fake_hash('again'));
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_reuse := SQLERRM; END;
  SELECT count(*) INTO v_t2_after FROM fn_check_reset_token(v_t2);          -- the other open link died with the reset
  -- An expired link is refused.
  PERFORM fn_request_password_reset(v_email, '10.17.0.2', v_t3);
  UPDATE password_reset SET requested_at = now() - interval '1 hour', expires_at = now() - interval '30 minutes' WHERE token_hash = v_t3;
  BEGIN PERFORM fn_reset_password(v_t3, pg_temp.fake_hash('late'));
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_expired := SQLERRM; END;
  -- Unknown and inactive emails get no row and no error.
  SELECT count(*) INTO v_unknown FROM fn_request_password_reset('nobody.' || v_email, '10.17.0.3', pg_temp.tok('t17-unknown'));
  v_inactive_id := fn_register_member('student', 'In', NULL, 'Active', 'inactive.' || v_email, pg_temp.fake_hash('in'), 'BSIT', 1, NULL, NULL, NULL);
  UPDATE student SET is_active = false WHERE student_id = v_inactive_id;
  SELECT count(*) INTO v_inactive FROM fn_request_password_reset('inactive.' || v_email, '10.17.0.3', pg_temp.tok('t17-inactive'));
  -- At most 3 open links per account: the 4th quietly gives nothing.
  FOR i IN 1..4 LOOP
    SELECT count(*) INTO v_open_limit FROM fn_request_password_reset(v_email, ('10.17.1.' || i)::inet, pg_temp.tok('t17-open' || i));
  END LOOP;
  -- At most 5 links per IP address per hour (two accounts, one address).
  FOR i IN 1..6 LOOP
    SELECT count(*) INTO v_ip_limit
      FROM fn_request_password_reset(CASE WHEN i <= 3 THEN (SELECT email FROM staff WHERE staff_id = pg_temp.id('librarian'))
                                          ELSE (SELECT email FROM staff WHERE staff_id = pg_temp.id('admin')) END,
                                     '10.17.2.1', pg_temp.tok('t17-ip' || i));
  END LOOP;
  PERFORM pg_temp.record(17, 'a reset link works once for 30 minutes, kills the other links, and reveals nothing for unknown emails',
    v_req.user_type = 'student' AND v_req.user_id = v_id AND v_minutes = 30 AND v_check.user_id = v_id AND v_done.email = v_email
      AND v_hash_ok AND v_reuse LIKE '%expired or was already used%' AND v_t2_after = 0 AND v_expired LIKE '%expired or was already used%'
      AND v_unknown = 0 AND v_inactive = 0 AND v_open_limit = 0 AND v_ip_limit = 0,
    format('request %s/%s, %s minutes | check %s | reset to %s, hash stored %s | reuse: %s | other link left %s | expired: %s | unknown %s, inactive %s | 4th open %s | 6th from IP %s',
           v_req.user_type, v_req.user_id, v_minutes, v_check.user_id, v_done.email, v_hash_ok, coalesce(v_reuse, 'not refused'),
           v_t2_after, coalesce(v_expired, 'not refused'), v_unknown, v_inactive, v_open_limit, v_ip_limit));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(17, 'a reset link works once for 30 minutes, kills the other links, and reveals nothing for unknown emails', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 18. password changes: your own only, staff resets keep the old rules, the app cannot go around them
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_sid int := pg_temp.id('signup_student');
  v_admin int := pg_temp.id('admin');
  v_lib int := pg_temp.id('librarian');
  v_own boolean; v_other text; v_lib_staff text; v_self text; v_must boolean; v_admin_ok boolean;
  v_app_store text := 'allowed'; v_app_read text := 'allowed'; v_app_login boolean; v_hash text;
BEGIN
  PERFORM pg_temp.act('student', v_sid);
  PERFORM fn_set_password('student', v_sid, pg_temp.fake_hash('own'), false);
  v_own := (SELECT password_hash = pg_temp.fake_hash('own') FROM student WHERE student_id = v_sid);
  PERFORM pg_temp.act('student', v_sid + 1000);
  BEGIN PERFORM fn_set_password('student', v_sid, pg_temp.fake_hash('other'), false);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_other := SQLERRM; END;

  PERFORM pg_temp.act('staff', v_lib);
  PERFORM fn_staff_reset_password(v_lib, 'student', v_sid, pg_temp.fake_hash('temp'));
  v_must := (SELECT must_change_password FROM student WHERE student_id = v_sid);
  BEGIN PERFORM fn_staff_reset_password(v_lib, 'staff', v_admin, pg_temp.fake_hash('temp2'));
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_lib_staff := SQLERRM; END;
  PERFORM pg_temp.act('staff', v_admin);
  PERFORM fn_staff_reset_password(v_admin, 'staff', v_lib, pg_temp.fake_hash('temp3'));
  v_admin_ok := (SELECT must_change_password FROM staff WHERE staff_id = v_lib);
  BEGIN PERFORM fn_staff_reset_password(v_admin, 'staff', v_admin, pg_temp.fake_hash('temp4'));
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_self := SQLERRM; END;

  v_hash := pg_temp.fake_hash('x');
  SET LOCAL ROLE lms_app;
  BEGIN PERFORM fn_auth_store_hash('staff', v_admin, v_hash, false);
  EXCEPTION WHEN insufficient_privilege THEN v_app_store := 'blocked'; END;
  BEGIN PERFORM 1 FROM password_reset LIMIT 1;
  EXCEPTION WHEN insufficient_privilege THEN v_app_read := 'blocked'; END;
  v_app_login := (SELECT NOT locked FROM fn_login_lookup('nobody@test.example', '10.18.0.1', 'staff'));
  RESET ROLE;
  PERFORM pg_temp.record(18, 'only you change your own password; staff resets keep the old rules; the app cannot go around them',
    v_own AND v_other IS NOT NULL AND v_must AND v_lib_staff LIKE '%Only an admin%' AND v_admin_ok AND v_self LIKE '%use Profile%'
      AND v_app_store = 'blocked' AND v_app_read = 'blocked' AND v_app_login,
    format('own %s | other: %s | librarian reset student, must change %s | librarian reset admin: %s | admin reset librarian %s | admin self: %s | app store hash %s, read password_reset %s, runs sign-in %s',
           v_own, coalesce(v_other, 'not refused'), v_must, coalesce(v_lib_staff, 'not refused'), v_admin_ok,
           coalesce(v_self, 'not refused'), v_app_store, v_app_read, v_app_login));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(18, 'only you change your own password; staff resets keep the old rules; the app cannot go around them', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 19. the sign-in story is in audit_log, without any hash or token
-- ---------------------------------------------------------------------------
DO $$
DECLARE v_actions text[]; v_leaks int; v_trigger_dupes int; v_login_actor boolean;
BEGIN
  SELECT array_agg(DISTINCT a.action ORDER BY a.action) INTO v_actions
    FROM audit_log a, t_meta m
   WHERE a.audit_id > m.audit_from
     AND a.action IN ('login', 'logout', 'sign_up', 'password_changed', 'password_reset_by_staff', 'password_reset_requested', 'password_reset');
  SELECT count(*) INTO v_leaks
    FROM audit_log a, t_meta m
   WHERE a.audit_id > m.audit_from
     AND (a.detail::text ~ '\$2y\$|\$argon2' OR a.detail::text ~ '[0-9a-f]{64}' OR a.detail ? 'password_hash' OR a.detail ? 'token_hash');
  -- The password routines do not also leave a plain "update" row from the trigger
  -- (test 10 updates a hash directly on purpose, so only accounts the routines touched are counted).
  SELECT count(*) INTO v_trigger_dupes
    FROM audit_log a, t_meta m
   WHERE a.audit_id > m.audit_from AND a.action = 'update' AND a.table_name IN ('student', 'teacher', 'staff')
     AND a.detail ? 'password_changed'
     AND EXISTS (SELECT 1 FROM audit_log b
                  WHERE b.table_name = a.table_name AND b.record_id = a.record_id
                    AND b.action IN ('password_changed', 'password_reset', 'password_reset_by_staff'));
  v_login_actor := EXISTS (SELECT 1 FROM audit_log a, t_meta m WHERE a.audit_id > m.audit_from AND a.action = 'login'
                             AND a.actor_type = 'student' AND a.actor_id = pg_temp.id('signup_student'));
  PERFORM pg_temp.record(19, 'login, logout, sign_up and password resets are audited, with no hash or token in any row',
    v_actions @> ARRAY['login', 'logout', 'password_changed', 'password_reset', 'password_reset_by_staff', 'password_reset_requested', 'sign_up']
      AND v_leaks = 0 AND v_trigger_dupes = 0 AND v_login_actor,
    format('actions %s | rows with a hash or token %s | duplicate trigger rows %s | login actor right %s', v_actions, v_leaks, v_trigger_dupes, v_login_actor));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(19, 'login, logout, sign_up and password resets are audited, with no hash or token in any row', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 20. Book: fn_create_book keeps the ISBN, limit and category rules; fn_list_books and fn_get_book read them back
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_id int; v_id2 int; v_isbn text; v_dupe text; v_bad_isbn text; v_limit text; v_cat1 text; v_cat2 text;
  v_found int; v_total bigint; v_by_isbn int; v_get record; v_upd_dupe text; v_title text; v_only int; v_cat3 text;
BEGIN
  PERFORM pg_temp.act('staff', v_lib);
  v_id := fn_create_book('0-306-40615-2', 'Tidy Test Book', 'Test Author', '  testing   shelf ', 2, 450, v_lib);
  SELECT isbn, category INTO v_isbn, v_cat1 FROM book WHERE book_id = v_id;
  BEGIN PERFORM fn_create_book('978-0-306-40615-7', 'Same ISBN', 'Test Author', 'Testing', 1, 1, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_dupe := SQLERRM; END;
  BEGIN PERFORM fn_create_book('978-0-306-40615-8', 'Bad ISBN', 'Test Author', 'Testing', 1, 1, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_bad_isbn := SQLERRM; END;
  BEGIN PERFORM fn_create_book(NULL, 'Six copies', 'Test Author', 'Testing', 6, 1, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_limit := SQLERRM; END;
  v_id2 := fn_create_book(NULL, 'Tidy Test Book Two', 'Test Author', 'TESTING SHELF', 1, 0, v_lib);
  SELECT category INTO v_cat2 FROM book WHERE book_id = v_id2;
  SELECT count(*), max(total_count) INTO v_found, v_total FROM fn_list_books('tidy test', NULL, false, false, 1, 0);
  SELECT count(*) INTO v_by_isbn FROM fn_list_books('0306406157');
  SELECT * INTO v_get FROM fn_get_book(v_id);
  -- Edit: details only, and an ISBN that belongs to another book is refused.
  PERFORM fn_update_book_details(v_id2, NULL, 'Tidy Test Book Renamed', 'Test Author', 'Testing shelf', 10, v_lib);
  SELECT title INTO v_title FROM book WHERE book_id = v_id2;
  BEGIN PERFORM fn_update_book_details(v_id2, '9780306406157', 'X', 'Y', 'Z', 1, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_upd_dupe := SQLERRM; END;
  -- Fixing only the letter case of a category no other book uses works.
  PERFORM fn_update_book_details(v_id2, NULL, 'Tidy Test Book Renamed', 'Test Author', 'Lone shelf', 10, v_lib);
  PERFORM fn_update_book_details(v_id2, NULL, 'Tidy Test Book Renamed', 'Test Author', 'Lone Shelf', 10, v_lib);
  SELECT category INTO v_cat3 FROM book WHERE book_id = v_id2;
  -- Only available: a title with every copy out is left out.
  UPDATE book SET copies_available = 0 WHERE book_id = v_id2;
  SELECT count(*) INTO v_only FROM fn_list_books('Tidy Test Book', NULL, true);
  PERFORM pg_temp.record(20, 'fn_create_book refuses a duplicate ISBN and more than 5 copies; fn_list_books and fn_get_book read titles back',
    v_isbn = '978-0306406157' AND v_dupe LIKE '%already exists: Tidy Test Book%' AND v_bad_isbn LIKE '%not a valid ISBN%'
      AND v_limit LIKE '%from 1 to 5%' AND v_cat1 = 'testing shelf' AND v_cat2 = 'testing shelf'
      AND v_found = 1 AND v_total = 2 AND v_by_isbn = 1 AND v_get.copy_limit = 5 AND v_get.copies_available = 2
      AND v_title = 'Tidy Test Book Renamed' AND v_upd_dupe LIKE '%already exists%' AND v_only = 1 AND v_cat3 = 'Lone Shelf',
    format('isbn %s | dupe: %s | bad: %s | limit: %s | categories %s / %s / %s | list %s of %s, by ISBN %s | get limit %s | renamed %s | update dupe: %s | only available %s',
           v_isbn, coalesce(v_dupe, 'not refused'), coalesce(v_bad_isbn, 'not refused'), coalesce(v_limit, 'not refused'),
           v_cat1, v_cat2, v_cat3, v_found, v_total, v_by_isbn, v_get.copy_limit, v_title, coalesce(v_upd_dupe, 'not refused'), v_only));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(20, 'fn_create_book refuses a duplicate ISBN and more than 5 copies; fn_list_books and fn_get_book read titles back', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 21. Fine: fn_create_fine needs a real loan, a known kind and an amount above zero; one overdue fine per loan
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int := pg_temp.new_student('t21');
  v_b int := pg_temp.new_book('t21', 2, 800);
  v_loan int; v_missing text; v_zero text; v_kind text; v_fine int; v_over1 int; v_over2 text;
  v_listed int; v_total bigint; v_get record; v_status text;
BEGIN
  PERFORM pg_temp.act('staff', v_lib);
  v_loan := fn_issue_book_direct(v_s, NULL, v_b, v_lib);
  BEGIN PERFORM fn_create_fine(999999, 'damaged', 100, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM002' THEN v_missing := SQLERRM; END;
  BEGIN PERFORM fn_create_fine(v_loan, 'damaged', 0, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_zero := SQLERRM; END;
  BEGIN PERFORM fn_create_fine(v_loan, 'late', 50, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_kind := SQLERRM; END;
  v_fine := fn_create_fine(v_loan, 'damaged', 250.5, v_lib);
  v_over1 := fn_create_fine(v_loan, 'overdue', 30, v_lib);
  BEGIN PERFORM fn_create_fine(v_loan, 'overdue', 30, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_over2 := SQLERRM; END;
  SELECT count(*), max(total_count) INTO v_listed, v_total FROM fn_list_fines(NULL, NULL, v_s, NULL, 1, 0);
  SELECT * INTO v_get FROM fn_get_fine(v_fine);
  SELECT status INTO v_status FROM fine WHERE fine_id = v_fine;
  PERFORM pg_temp.record(21, 'fn_create_fine refuses a missing loan, a zero amount, an unknown kind and a 2nd overdue fine',
    v_missing IS NOT NULL AND v_zero LIKE '%more than%' AND v_kind LIKE '%overdue, lost or damaged%' AND v_status = 'unpaid'
      AND v_over1 IS NOT NULL AND v_over2 LIKE '%at most one%' AND v_listed = 1 AND v_total = 2
      AND v_get.amount = 250.50 AND v_get.borrower_type = 'student',
    format('missing: %s | zero: %s | kind: %s | status %s | 2nd overdue: %s | list %s of %s | get %s %s',
           coalesce(v_missing, 'not refused'), coalesce(v_zero, 'not refused'), coalesce(v_kind, 'not refused'), v_status,
           coalesce(v_over2, 'not refused'), v_listed, v_total, v_get.amount, v_get.borrower_type));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(21, 'fn_create_fine refuses a missing loan, a zero amount, an unknown kind and a 2nd overdue fine', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 22. People: only an admin creates staff; new accounts must change the temporary password; lists and gets work
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_admin int := pg_temp.id('admin');
  v_lib int := pg_temp.id('librarian');
  v_tag text := txid_current()::text;
  v_lib_try text; v_staff int; v_stu int; v_tea int; v_dupe text; v_plain text; v_must boolean[]; v_list int; v_total bigint;
  v_get_staff record; v_get_stu record; v_get_tea record; v_upd text; v_courses int;
BEGIN
  PERFORM pg_temp.act('staff', v_lib);
  BEGIN PERFORM fn_create_staff('Lib', NULL, 'Try', 'libtry.' || v_tag || '@test.example', pg_temp.fake_hash('s1'), 'admin', NULL, v_lib);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_lib_try := SQLERRM; END;
  v_stu := fn_create_student('Crud', NULL, 'Student', 'BS Information Technology', 2, '0917 555 0001',
                             'crud.student.' || v_tag || '@test.example', pg_temp.fake_hash('s2'), v_lib);
  v_tea := fn_create_teacher('Crud', NULL, 'Teacher', 'Mathematics', NULL, 'crud.teacher.' || v_tag || '@test.example',
                             pg_temp.fake_hash('s3'), v_lib);
  BEGIN PERFORM fn_create_student('Dup', NULL, 'Student', 'BSIT', 1, NULL, 'CRUD.STUDENT.' || v_tag || '@test.example', pg_temp.fake_hash('s4'), v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_dupe := SQLERRM; END;
  BEGIN PERFORM fn_create_teacher('Plain', NULL, 'Text', 'English', NULL, 'plain.' || v_tag || '@test.example', 'not-a-hash', v_lib);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_plain := SQLERRM; END;
  PERFORM pg_temp.act('staff', v_admin);
  v_staff := fn_create_staff('Crud', NULL, 'Staff', 'crud.staff.' || v_tag || '@test.example', pg_temp.fake_hash('s5'), 'librarian', NULL, v_admin, current_date);
  PERFORM fn_update_staff(v_staff, 'Crud', 'M', 'Staffer', 'crud.staff.' || v_tag || '@test.example', 'librarian', '0917 555 0002', current_date, v_admin);
  BEGIN PERFORM fn_update_staff(v_staff, 'Crud', NULL, 'Staffer', 'x', 'librarian', NULL, NULL, v_admin);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_upd := SQLERRM; END;
  v_must := ARRAY[(SELECT must_change_password FROM staff WHERE staff_id = v_staff),
                  (SELECT must_change_password FROM student WHERE student_id = v_stu),
                  (SELECT must_change_password FROM teacher WHERE teacher_id = v_tea)];
  SELECT count(*), max(total_count) INTO v_list, v_total FROM fn_list_students('crud student', true, 5, 0);
  SELECT * INTO v_get_staff FROM fn_get_staff(v_staff);
  SELECT * INTO v_get_stu FROM fn_get_student(v_stu);
  SELECT * INTO v_get_tea FROM fn_get_teacher(v_tea);
  SELECT count(*) INTO v_courses FROM fn_list_courses() c WHERE c.course = 'BS Information Technology';
  PERFORM pg_temp.record(22, 'only an admin creates staff; new people must change their password; duplicates and non-hashes are refused',
    v_lib_try LIKE '%Only an admin%' AND v_dupe LIKE '%already uses this email%' AND v_plain LIKE '%password_hash()%'
      AND v_must = ARRAY[true, true, true] AND v_list = 1 AND v_total = 1 AND v_get_staff.last_name = 'Staffer'
      AND v_get_staff.position = 'librarian' AND v_get_stu.year_level = 2 AND v_get_tea.department = 'Mathematics'
      AND v_upd LIKE '%full email address%' AND v_courses = 1,
    format('librarian makes staff: %s | dup: %s | plain: %s | must change %s | list %s of %s | staff %s %s | student year %s | teacher %s | bad email: %s',
           coalesce(v_lib_try, 'not refused'), coalesce(v_dupe, 'not refused'), coalesce(v_plain, 'not refused'), v_must,
           v_list, v_total, v_get_staff.last_name, v_get_staff.position, v_get_stu.year_level, v_get_tea.department, coalesce(v_upd, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(22, 'only an admin creates staff; new people must change their password; duplicates and non-hashes are refused', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 23. lms_app cannot read or write password_hash, and cannot insert or update people or books directly
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_sel text := 'allowed'; v_names boolean; v_upd text := 'allowed'; v_ins_person text := 'allowed'; v_ins_book text := 'allowed';
  v_upd_book text := 'allowed'; v_fn_ok boolean; v_leaky text;
BEGIN
  -- No function but fn_login_lookup ever returns a password hash.
  SELECT string_agg(p.proname, ', ') INTO v_leaky
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
   WHERE n.nspname = 'lms' AND pg_get_function_result(p.oid) ILIKE '%password_hash%' AND p.proname <> 'fn_login_lookup';
  SET LOCAL ROLE lms_app;
  BEGIN PERFORM password_hash FROM staff LIMIT 1;
  EXCEPTION WHEN insufficient_privilege THEN v_sel := 'blocked'; END;
  v_names := (SELECT count(*) > 0 FROM (SELECT first_name, email FROM student LIMIT 1) x);
  BEGIN UPDATE student SET password_hash = 'x' WHERE false;
  EXCEPTION WHEN insufficient_privilege THEN v_upd := 'blocked'; END;
  BEGIN INSERT INTO teacher (first_name, last_name, department, email, password_hash) VALUES ('A', 'B', 'C', 'd@e.fg', 'x');
  EXCEPTION WHEN insufficient_privilege THEN v_ins_person := 'blocked'; END;
  BEGIN INSERT INTO book (title, author, category, copies_total, copies_available, replacement_cost, added_by_id) VALUES ('A', 'B', 'C', 1, 1, 1, 1);
  EXCEPTION WHEN insufficient_privilege THEN v_ins_book := 'blocked'; END;
  BEGIN UPDATE book SET title = title WHERE false;
  EXCEPTION WHEN insufficient_privilege THEN v_upd_book := 'blocked'; END;
  v_fn_ok := (SELECT count(*) >= 1 FROM fn_list_books(NULL, NULL, false, true, 1, 0));
  RESET ROLE;
  PERFORM pg_temp.record(23, 'lms_app cannot SELECT or write password_hash, nor insert or update people and books directly',
    v_sel = 'blocked' AND v_names AND v_upd = 'blocked' AND v_ins_person = 'blocked' AND v_ins_book = 'blocked'
      AND v_upd_book = 'blocked' AND v_fn_ok AND v_leaky IS NULL,
    format('select hash %s | other columns readable %s | update hash %s | insert person %s | insert book %s | update book %s | functions work %s | functions returning a hash: %s',
           v_sel, v_names, v_upd, v_ins_person, v_ins_book, v_upd_book, v_fn_ok, coalesce(v_leaky, 'none')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(23, 'lms_app cannot SELECT or write password_hash, nor insert or update people and books directly', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 24. Transaction: fn_list_transactions filters by status, person and date; fn_get_transaction; your own profile only
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_s int := pg_temp.new_student('t24');
  v_b1 int := pg_temp.new_book('t24a', 1, 100);
  v_b2 int := pg_temp.new_book('t24b', 1, 100);
  v_l1 int; v_l2 int; v_active int; v_past int; v_other int; v_dated int; v_get record; v_total bigint; v_profile text; v_name text;
BEGIN
  PERFORM pg_temp.act('staff', v_lib);
  v_l1 := fn_issue_book_direct(v_s, NULL, v_b1, v_lib);
  v_l2 := fn_issue_book_direct(v_s, NULL, v_b2, v_lib);
  PERFORM fn_return_book(v_l2, v_lib, 'good');
  SELECT count(*), max(total_count) INTO v_active, v_total FROM fn_list_transactions('active', NULL, NULL, NULL, v_s, NULL);
  SELECT count(*) INTO v_past FROM fn_list_transactions('past', NULL, NULL, NULL, v_s, NULL);
  SELECT count(*) INTO v_other FROM fn_list_transactions(NULL, NULL, NULL, NULL, v_s + 100000, NULL);
  SELECT count(*) INTO v_dated FROM fn_list_transactions('all', 'Test book t24', current_date + 1, NULL, NULL, NULL);
  SELECT * INTO v_get FROM fn_get_transaction(v_l1);
  -- fn_update_my_profile: you, and only you.
  PERFORM pg_temp.act('student', v_s);
  PERFORM fn_update_my_profile('student', v_s, 'Renamed', NULL, 'Student', '0917 555 0003', NULL);
  SELECT first_name INTO v_name FROM student WHERE student_id = v_s;
  PERFORM pg_temp.act('student', v_s + 1);
  BEGIN PERFORM fn_update_my_profile('student', v_s, 'Hacker', NULL, 'Student', NULL, NULL);
  EXCEPTION WHEN SQLSTATE 'LM004' THEN v_profile := SQLERRM; END;
  PERFORM pg_temp.record(24, 'fn_list_transactions filters by status, person and date; fn_get_transaction; profiles change only your own',
    v_active = 1 AND v_total = 1 AND v_past = 1 AND v_other = 0 AND v_dated = 0 AND v_get.book_id = v_b1
      AND v_get.display_status = 'borrowed' AND v_name = 'Renamed' AND v_profile IS NOT NULL,
    format('active %s (total %s), past %s, someone else %s, borrowed after tomorrow %s | get book %s %s | renamed %s | other person: %s',
           v_active, v_total, v_past, v_other, v_dated, v_get.book_id, v_get.display_status, v_name, coalesce(v_profile, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(24, 'fn_list_transactions filters by status, person and date; fn_get_transaction; profiles change only your own', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 25. a title with every copy out can be requested (it waits) but not approved; a title with no copies left cannot
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_lib int := pg_temp.id('librarian');
  v_a int := pg_temp.new_student('t25a');
  v_b int := pg_temp.new_student('t25b');
  v_c int := pg_temp.new_student('t25c');
  v_book int := pg_temp.new_book('t25', 1, 500);
  v_loan int; v_wait int; v_approve text; v_gone text;
BEGIN
  PERFORM pg_temp.act('staff', v_lib);
  v_loan := fn_issue_book_direct(v_a, NULL, v_book, v_lib);           -- the only copy goes out
  PERFORM pg_temp.act('student', v_b);
  v_wait := fn_create_request(v_b, NULL, v_book);                       -- allowed: it waits
  PERFORM pg_temp.act('staff', v_lib);
  BEGIN PERFORM fn_approve_request(v_wait, v_lib, current_date + 1, '09:00', 'Ready', 'Ready for pickup.');
  EXCEPTION WHEN SQLSTATE 'LM003' THEN v_approve := SQLERRM; END;
  PERFORM fn_return_book(v_loan, v_lib, 'lost', NULL);                  -- the copy is lost: none left at all
  PERFORM pg_temp.act('student', v_c);
  BEGIN PERFORM fn_create_request(v_c, NULL, v_book);
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_gone := SQLERRM; END;
  PERFORM pg_temp.record(25, 'a title with every copy out can be requested but not approved; a title with no copies left cannot be requested',
    v_wait IS NOT NULL AND v_approve LIKE '%No copy of%' AND v_gone LIKE '%no copies left in the library%',
    format('waiting request %s | approve: %s | no copies left: %s', v_wait, coalesce(v_approve, 'not refused'), coalesce(v_gone, 'not refused')));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(25, 'a title with every copy out can be requested but not approved; a title with no copies left cannot be requested', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- 26. sessions in the database (SESSION_DRIVER=db): only through fn_session_*
-- ---------------------------------------------------------------------------
DO $$
DECLARE
  v_id text := 'smoke' || md5(random()::text);
  v_empty text; v_read text; v_again text; v_gone text; v_bad text; v_gc int; v_table text := 'allowed'; v_app_rw boolean;
BEGIN
  v_empty := fn_session_read(v_id);
  PERFORM fn_session_write(v_id, 'csrf|s:4:"abcd";');
  PERFORM fn_session_write(v_id, 'csrf|s:4:"wxyz";');                      -- a second write replaces the first
  v_read := fn_session_read(v_id);
  UPDATE app_session SET updated_at = now() - interval '3 hours' WHERE id = v_id;
  v_gc := fn_session_gc(3600);
  v_again := fn_session_read(v_id);
  PERFORM fn_session_write(v_id || 'b', 'x');
  PERFORM fn_session_destroy(v_id || 'b');
  v_gone := fn_session_read(v_id || 'b');
  BEGIN PERFORM fn_session_write('bad id!', 'x');
  EXCEPTION WHEN SQLSTATE 'LM001' THEN v_bad := SQLERRM; END;
  SET LOCAL ROLE lms_app;
  BEGIN PERFORM 1 FROM app_session LIMIT 1;
  EXCEPTION WHEN insufficient_privilege THEN v_table := 'blocked'; END;
  PERFORM fn_session_write(v_id || 'c', 'y');
  v_app_rw := fn_session_read(v_id || 'c') = 'y';
  PERFORM fn_session_destroy(v_id || 'c');
  RESET ROLE;
  PERFORM pg_temp.record(26, 'sessions are stored, replaced, expired and destroyed only through the fn_session_* routines',
    v_empty IS NULL AND v_read = 'csrf|s:4:"wxyz";' AND v_gc >= 1 AND v_again IS NULL AND v_gone IS NULL AND v_bad IS NOT NULL
      AND v_table = 'blocked' AND v_app_rw,
    format('new id reads %s | read %s | gc removed %s, then %s | destroyed %s | bad id: %s | app reads table %s, uses routines %s',
           coalesce(v_empty, 'nothing'), v_read, v_gc, coalesce(v_again, 'nothing'), coalesce(v_gone, 'nothing'),
           coalesce(v_bad, 'not refused'), v_table, v_app_rw));
EXCEPTION WHEN others THEN
  PERFORM pg_temp.record(26, 'sessions are stored, replaced, expired and destroyed only through the fn_session_* routines', false, SQLERRM);
END $$;

-- ---------------------------------------------------------------------------
-- Results
-- ---------------------------------------------------------------------------
\pset tuples_only on
\pset format unaligned
\echo ''
\echo 'Results'
SELECT CASE WHEN pass THEN 'PASS' ELSE 'FAIL' END || '  ' || lpad(n::text, 2) || '. ' || name
       || CASE WHEN pass THEN '' ELSE E'\n        ' || coalesce(detail, '') END
  FROM t_result ORDER BY n;
SELECT format('%s of %s checks passed', count(*) FILTER (WHERE pass), count(*)) FROM t_result;
\echo ''
\echo 'Who did what during the tests (audit_log, grouped):'
SELECT format('  %-7s %-4s %-6s %-15s %s row(s)', a.actor_type, coalesce(a.actor_id::text, '-'), a.action, a.table_name, count(*))
  FROM audit_log a, t_meta m
 WHERE a.audit_id > m.audit_from
 GROUP BY a.actor_type, a.actor_id, a.action, a.table_name
 ORDER BY a.actor_type, a.actor_id NULLS FIRST, a.table_name, a.action;
\pset tuples_only off
\pset format aligned

-- Make the run fail (non-zero exit code) when any check failed.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM t_result WHERE NOT pass) THEN
    RAISE EXCEPTION 'Some checks failed. Fix the routine, not the test.';
  END IF;
END $$;
