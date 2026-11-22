-- 02_routines.sql: the library rules from docs/SPEC.md part 2, written in PL/pgSQL.
--
-- Every routine runs inside the caller's single transaction, locks the book row with SELECT ... FOR UPDATE
-- before it changes copies, and raises a short readable message when a rule is broken.
-- Error codes (SQLSTATE) so PHP can show the message to people as it is:
--   LM001 a library rule is broken      LM002 the record does not exist
--   LM003 no copy is free               LM004 you are not allowed to do this
-- Only these routines change book.copies_available (05_roles.sql takes that column away from the app).
\set ON_ERROR_STOP on
SET search_path = lms, public;
SET client_min_messages = warning;

-- ===========================================================================
-- Small helpers
-- ===========================================================================

CREATE OR REPLACE FUNCTION fn_rule(p_message text, p_code text DEFAULT 'LM001') RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_message;
END $$;

-- Settings live in library_setting and are never hard-coded in the routines.
CREATE OR REPLACE FUNCTION fn_setting(p_key text) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT ls.setting_value FROM lms.library_setting ls WHERE ls.setting_key = p_key
$$;

CREATE OR REPLACE FUNCTION fn_setting_int(p_key text, p_default int) RETURNS int
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v text := btrim(coalesce(fn_setting(p_key), ''));
BEGIN
  IF v ~ '^-?\d{1,9}$' THEN RETURN v::int; END IF;
  RETURN p_default;
END $$;

CREATE OR REPLACE FUNCTION fn_setting_num(p_key text, p_default numeric) RETURNS numeric
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v text := btrim(coalesce(fn_setting(p_key), ''));
BEGIN
  IF v ~ '^\d{1,8}(\.\d{1,2})?$' THEN RETURN v::numeric; END IF;
  RETURN p_default;
END $$;

-- Sep 20, 2026
CREATE OR REPLACE FUNCTION fn_fmt_date(p date) RETURNS text
LANGUAGE sql IMMUTABLE AS $$ SELECT to_char(p, 'Mon FMDD, YYYY') $$;

-- 9:00 AM
CREATE OR REPLACE FUNCTION fn_fmt_time(p time) RETURNS text
LANGUAGE sql IMMUTABLE AS $$ SELECT to_char(DATE '2000-01-01' + p, 'FMHH12:MI AM') $$;

-- 3,200.00 (the ₱ sign is written in the message text)
CREATE OR REPLACE FUNCTION fn_fmt_money(p numeric) RETURNS text
LANGUAGE sql IMMUTABLE AS $$ SELECT to_char(coalesce(p, 0), 'FM999,999,990.00') $$;

-- "1 overdue book", "2 overdue books"
CREATE OR REPLACE FUNCTION fn_count_books(p_n int, p_word text) RETURNS text
LANGUAGE sql IMMUTABLE AS $$
  SELECT p_n || ' ' || p_word || CASE WHEN p_n = 1 THEN ' book' ELSE ' books' END
$$;

CREATE OR REPLACE FUNCTION fn_person_name(p_student_id int, p_teacher_id int, p_first_only boolean DEFAULT false)
RETURNS text LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT CASE WHEN p_first_only THEN x.first_name ELSE x.first_name || ' ' || x.last_name END
  FROM (
    SELECT s.first_name, s.last_name FROM lms.student s WHERE s.student_id = p_student_id
    UNION ALL
    SELECT t.first_name, t.last_name FROM lms.teacher t WHERE t.teacher_id = p_teacher_id
  ) x LIMIT 1
$$;

-- The staff member must exist, be active, and be the person the app says is acting.
CREATE OR REPLACE FUNCTION fn_check_staff(p_staff_id int, p_admin_only boolean DEFAULT false)
RETURNS text LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_name text; v_position text; v_active boolean;
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id text := nullif(current_setting('app.actor_id', true), '');
BEGIN
  SELECT s.first_name || ' ' || s.last_name, s.position, s.is_active
    INTO v_name, v_position, v_active
    FROM staff s WHERE s.staff_id = p_staff_id;
  IF v_name IS NULL OR NOT v_active THEN
    PERFORM fn_rule('Your staff account is not active. Sign in again or ask an admin.', 'LM004');
  END IF;
  IF v_actor_type = 'staff' AND v_actor_id IS NOT NULL AND v_actor_id <> p_staff_id::text THEN
    PERFORM fn_rule('You can only act as yourself. Sign in again.', 'LM004');
  END IF;
  IF p_admin_only AND v_position <> 'admin' THEN
    PERFORM fn_rule('Only an admin can do this.', 'LM004');
  END IF;
  RETURN v_name;
END $$;

-- Subject and body rules shared by every message.
CREATE OR REPLACE FUNCTION fn_check_message(p_subject text, p_body text) RETURNS void
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
  IF coalesce(btrim(p_subject), '') = '' THEN PERFORM fn_rule('Write a subject before sending.'); END IF;
  IF coalesce(btrim(p_body), '') = '' THEN PERFORM fn_rule('Write a message before sending.'); END IF;
  IF char_length(btrim(p_subject)) > 150 THEN PERFORM fn_rule('The subject can be at most 150 characters.'); END IF;
  IF char_length(p_body) > 1000 THEN PERFORM fn_rule('The message can be at most 1,000 characters.'); END IF;
END $$;

-- Can this person borrow or request one more book? Returns their full name, or raises the reason.
-- Locks the person's row so two requests at the same moment cannot both slip under the limit.
CREATE OR REPLACE FUNCTION fn_check_borrower(p_student_id int, p_teacher_id int)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_name text; v_active boolean; v_limit int; v_unpaid numeric; v_used int;
BEGIN
  IF (p_student_id IS NOT NULL)::int + (p_teacher_id IS NOT NULL)::int <> 1 THEN
    PERFORM fn_rule('Choose one borrower: a student or a teacher.');
  END IF;

  IF p_student_id IS NOT NULL THEN
    SELECT s.first_name || ' ' || s.last_name, s.is_active INTO v_name, v_active
      FROM student s WHERE s.student_id = p_student_id FOR UPDATE;
    v_limit := fn_setting_int('max_loans_student', 5);
  ELSE
    SELECT t.first_name || ' ' || t.last_name, t.is_active INTO v_name, v_active
      FROM teacher t WHERE t.teacher_id = p_teacher_id FOR UPDATE;
    v_limit := fn_setting_int('max_loans_teacher', 8);
  END IF;
  IF v_name IS NULL THEN
    PERFORM fn_rule('That borrower does not exist.', 'LM002');
  END IF;
  IF NOT v_active THEN
    PERFORM fn_rule(format('%s''s library account is inactive. Reactivate it on Manage Users first.', v_name));
  END IF;

  SELECT coalesce(sum(f.amount), 0) INTO v_unpaid
    FROM fine f JOIN transaction x ON x.transaction_id = f.transaction_id
   WHERE f.status = 'unpaid' AND (x.student_id = p_student_id OR x.teacher_id = p_teacher_id);
  IF v_unpaid > 0 THEN
    PERFORM fn_rule(format('%s has an unpaid fine of ₱%s. It must be settled at the library desk first.',
                           v_name, fn_fmt_money(v_unpaid)));
  END IF;

  IF EXISTS (SELECT 1 FROM transaction x
              WHERE (x.student_id = p_student_id OR x.teacher_id = p_teacher_id)
                AND (x.status = 'overdue' OR (x.status = 'borrowed' AND x.due_date < current_date))) THEN
    PERFORM fn_rule(format('%s has an overdue book. It must be returned first.', v_name));
  END IF;

  SELECT (SELECT count(*) FROM transaction x
           WHERE (x.student_id = p_student_id OR x.teacher_id = p_teacher_id) AND x.status IN ('borrowed', 'overdue'))
       + (SELECT count(*) FROM book_request r
           WHERE (r.student_id = p_student_id OR r.teacher_id = p_teacher_id) AND r.status IN ('pending', 'approved'))
    INTO v_used;
  IF v_used >= v_limit THEN
    PERFORM fn_rule(format('%s has reached the borrowing limit: %s of %s loans and open requests are in use.',
                           v_name, v_used, v_limit));
  END IF;

  RETURN v_name;
END $$;

-- New books start with every copy on the shelf.
CREATE OR REPLACE FUNCTION fn_book_new_copies() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.copies_available := NEW.copies_total;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS trg_book_new_copies ON book;
CREATE TRIGGER trg_book_new_copies BEFORE INSERT ON book
  FOR EACH ROW EXECUTE FUNCTION fn_book_new_copies();

-- ===========================================================================
-- Requests
-- ===========================================================================

CREATE OR REPLACE FUNCTION fn_create_request(p_student_id int, p_teacher_id int, p_book_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_name text; v_title text; v_archived boolean; v_total int; v_id int;
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id text := nullif(current_setting('app.actor_id', true), '');
BEGIN
  -- A member can only ask for themselves.
  IF v_actor_type = 'student' AND (p_student_id IS NULL OR v_actor_id IS DISTINCT FROM p_student_id::text) THEN
    PERFORM fn_rule('You can only request books for yourself.', 'LM004');
  END IF;
  IF v_actor_type = 'teacher' AND (p_teacher_id IS NULL OR v_actor_id IS DISTINCT FROM p_teacher_id::text) THEN
    PERFORM fn_rule('You can only request books for yourself.', 'LM004');
  END IF;

  v_name := fn_check_borrower(p_student_id, p_teacher_id);

  SELECT b.title, b.is_archived, b.copies_total INTO v_title, v_archived, v_total FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF v_title IS NULL THEN PERFORM fn_rule('That book does not exist.', 'LM002'); END IF;
  IF v_archived THEN PERFORM fn_rule(format('“%s” is archived and cannot be requested.', v_title)); END IF;
  -- Every copy was lost: there is nothing to wait for. (A title whose copies are all on loan can still be
  -- requested; the request waits until a copy comes back.)
  IF v_total = 0 THEN
    PERFORM fn_rule(format('“%s” has no copies left in the library, so it cannot be requested.', v_title));
  END IF;

  IF EXISTS (SELECT 1 FROM book_request r
              WHERE r.book_id = p_book_id AND r.status IN ('pending', 'approved')
                AND (r.student_id = p_student_id OR r.teacher_id = p_teacher_id)) THEN
    PERFORM fn_rule(format('%s already has an open request for “%s”.', v_name, v_title));
  END IF;

  INSERT INTO book_request (student_id, teacher_id, book_id)
  VALUES (p_student_id, p_teacher_id, p_book_id)
  RETURNING request_id INTO v_id;
  RETURN v_id;
END $$;

-- The owner or any staff member, only while pending or approved.
CREATE OR REPLACE FUNCTION fn_cancel_request(p_request_id int, p_actor_type text, p_actor_id int, p_note text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  r book_request%ROWTYPE; v_title text; v_staff_name text; v_first text;
  v_note text := nullif(btrim(coalesce(p_note, '')), '');
BEGIN
  SELECT * INTO r FROM book_request WHERE request_id = p_request_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That request does not exist.', 'LM002'); END IF;

  IF p_actor_type = 'staff' THEN
    v_staff_name := fn_check_staff(p_actor_id);
  ELSIF p_actor_type = 'student' THEN
    IF r.student_id IS DISTINCT FROM p_actor_id THEN PERFORM fn_rule('You can only cancel your own requests.', 'LM004'); END IF;
  ELSIF p_actor_type = 'teacher' THEN
    IF r.teacher_id IS DISTINCT FROM p_actor_id THEN PERFORM fn_rule('You can only cancel your own requests.', 'LM004'); END IF;
  ELSE
    PERFORM fn_rule('Unknown kind of user.', 'LM004');
  END IF;

  IF r.status NOT IN ('pending', 'approved') THEN
    PERFORM fn_rule(format('This request is already %s, so it cannot be cancelled.', r.status));
  END IF;

  SELECT b.title INTO v_title FROM book b WHERE b.book_id = r.book_id FOR UPDATE;
  IF r.status = 'approved' THEN
    UPDATE book SET copies_available = copies_available + 1 WHERE book_id = r.book_id;  -- the reserved copy goes back
  END IF;

  UPDATE book_request
     SET status = 'cancelled',
         date_decided = now(),
         decided_by_id = CASE WHEN p_actor_type = 'staff' THEN p_actor_id ELSE decided_by_id END,
         decision_note = coalesce(v_note, CASE WHEN p_actor_type = 'staff' THEN 'Cancelled by the library'
                                               ELSE 'Cancelled by the borrower' END)
   WHERE request_id = p_request_id;

  IF p_actor_type = 'staff' THEN
    v_first := fn_person_name(r.student_id, r.teacher_id, true);
    INSERT INTO message (sender_staff_id, recipient_student_id, recipient_teacher_id, request_id, kind, subject, body)
    VALUES (p_actor_id, r.student_id, r.teacher_id, r.request_id, 'general',
            left(format('Your request for %s was cancelled', v_title), 150),
            left(format('Hi %s, your request for “%s” was cancelled by the library.%s %s',
                        v_first, v_title,
                        CASE WHEN v_note IS NOT NULL THEN ' Note: ' || v_note ELSE '' END,
                        v_staff_name), 1000));
  END IF;
END $$;

CREATE OR REPLACE FUNCTION fn_approve_request(p_request_id int, p_staff_id int, p_pickup_date date, p_pickup_time time,
                                              p_subject text, p_body text)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  r book_request%ROWTYPE; v_title text; v_available int; v_archived boolean; v_message int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM fn_check_message(p_subject, p_body);

  SELECT * INTO r FROM book_request WHERE request_id = p_request_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That request does not exist.', 'LM002'); END IF;
  IF r.status <> 'pending' THEN
    PERFORM fn_rule(format('Only pending requests can be approved. This one is %s.', r.status));
  END IF;
  IF p_pickup_date IS NULL OR p_pickup_time IS NULL THEN
    PERFORM fn_rule('Choose a pickup date and time.');
  END IF;
  IF p_pickup_date < current_date THEN
    PERFORM fn_rule('The pickup date cannot be in the past.');
  END IF;

  SELECT b.title, b.copies_available, b.is_archived INTO v_title, v_available, v_archived
    FROM book b WHERE b.book_id = r.book_id FOR UPDATE;
  IF v_archived THEN PERFORM fn_rule(format('“%s” is archived and cannot be lent.', v_title)); END IF;
  IF v_available < 1 THEN
    PERFORM fn_rule(format('No copy of “%s” is free right now: every copy is on loan or reserved. Reject the request with the All copies on loan preset instead.', v_title), 'LM003');
  END IF;

  UPDATE book SET copies_available = copies_available - 1 WHERE book_id = r.book_id;  -- reserve one copy

  UPDATE book_request
     SET status = 'approved', decided_by_id = p_staff_id, date_decided = now(),
         pickup_date = p_pickup_date, pickup_time = p_pickup_time,
         pickup_deadline = p_pickup_date + fn_setting_int('pickup_window_days', 2),
         decision_note = NULL
   WHERE request_id = p_request_id;

  INSERT INTO message (sender_staff_id, recipient_student_id, recipient_teacher_id, request_id, kind, subject, body)
  VALUES (p_staff_id, r.student_id, r.teacher_id, r.request_id, 'request_approved', btrim(p_subject), p_body)
  RETURNING message_id INTO v_message;
  RETURN v_message;
END $$;

CREATE OR REPLACE FUNCTION fn_reject_request(p_request_id int, p_staff_id int, p_reason text, p_subject text, p_body text)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  r book_request%ROWTYPE; v_message int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  IF coalesce(btrim(p_reason), '') = '' THEN PERFORM fn_rule('Write a reason for the rejection.'); END IF;
  PERFORM fn_check_message(p_subject, p_body);

  SELECT * INTO r FROM book_request WHERE request_id = p_request_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That request does not exist.', 'LM002'); END IF;
  IF r.status <> 'pending' THEN
    PERFORM fn_rule(format('Only pending requests can be rejected. This one is %s.', r.status));
  END IF;

  UPDATE book_request
     SET status = 'rejected', decided_by_id = p_staff_id, date_decided = now(), decision_note = btrim(p_reason)
   WHERE request_id = p_request_id;

  INSERT INTO message (sender_staff_id, recipient_student_id, recipient_teacher_id, request_id, kind, subject, body)
  VALUES (p_staff_id, r.student_id, r.teacher_id, r.request_id, 'request_rejected', btrim(p_subject), p_body)
  RETURNING message_id INTO v_message;
  RETURN v_message;
END $$;

-- ===========================================================================
-- Loans
-- ===========================================================================

-- Issue an approved request. The copy was already reserved at approval, so copies do not change again.
CREATE OR REPLACE FUNCTION fn_issue_book(p_request_id int, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  r book_request%ROWTYPE; v_days int; v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT * INTO r FROM book_request WHERE request_id = p_request_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That request does not exist.', 'LM002'); END IF;
  IF r.status <> 'approved' THEN
    PERFORM fn_rule(format('Only approved requests can be issued. This one is %s.', r.status));
  END IF;

  PERFORM 1 FROM book b WHERE b.book_id = r.book_id FOR UPDATE;

  v_days := CASE WHEN r.student_id IS NOT NULL THEN fn_setting_int('loan_days_student', 14)
                 ELSE fn_setting_int('loan_days_teacher', 14) END;

  INSERT INTO transaction (student_id, teacher_id, book_id, staff_id, request_id, date_borrowed, due_date, status)
  VALUES (r.student_id, r.teacher_id, r.book_id, p_staff_id, r.request_id, current_date, current_date + v_days, 'borrowed')
  RETURNING transaction_id INTO v_id;

  UPDATE book_request SET status = 'fulfilled' WHERE request_id = p_request_id;
  RETURN v_id;
END $$;

-- Walk-in loan with no request. Same checks as fn_create_request.
CREATE OR REPLACE FUNCTION fn_issue_book_direct(p_student_id int, p_teacher_id int, p_book_id int, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_name text; v_title text; v_available int; v_archived boolean; v_days int; v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  v_name := fn_check_borrower(p_student_id, p_teacher_id);

  SELECT b.title, b.copies_available, b.is_archived INTO v_title, v_available, v_archived
    FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF v_title IS NULL THEN PERFORM fn_rule('That book does not exist.', 'LM002'); END IF;
  IF v_archived THEN PERFORM fn_rule(format('“%s” is archived and cannot be lent.', v_title)); END IF;

  IF EXISTS (SELECT 1 FROM book_request r
              WHERE r.book_id = p_book_id AND r.status IN ('pending', 'approved')
                AND (r.student_id = p_student_id OR r.teacher_id = p_teacher_id)) THEN
    PERFORM fn_rule(format('%s already has an open request for “%s”. Issue it from Requests instead.', v_name, v_title));
  END IF;
  IF v_available < 1 THEN
    PERFORM fn_rule(format('No copy of “%s” is on the shelf right now.', v_title), 'LM003');
  END IF;

  UPDATE book SET copies_available = copies_available - 1 WHERE book_id = p_book_id;

  v_days := CASE WHEN p_student_id IS NOT NULL THEN fn_setting_int('loan_days_student', 14)
                 ELSE fn_setting_int('loan_days_teacher', 14) END;

  INSERT INTO transaction (student_id, teacher_id, book_id, staff_id, request_id, date_borrowed, due_date, status)
  VALUES (p_student_id, p_teacher_id, p_book_id, p_staff_id, NULL, current_date, current_date + v_days, 'borrowed')
  RETURNING transaction_id INTO v_id;
  RETURN v_id;
END $$;

-- condition: good, damaged or lost. Returns the id of a fine it created, or null.
CREATE OR REPLACE FUNCTION fn_return_book(p_transaction_id int, p_staff_id int, p_condition text, p_damage_amount numeric DEFAULT NULL)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  t transaction%ROWTYPE; v_cost numeric; v_days int; v_fine int; v_extra int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT * INTO t FROM transaction WHERE transaction_id = p_transaction_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That loan does not exist.', 'LM002'); END IF;
  IF t.status NOT IN ('borrowed', 'overdue') THEN
    PERFORM fn_rule(format('This loan is already %s.', t.status));
  END IF;
  IF p_condition IS NULL OR p_condition NOT IN ('good', 'damaged', 'lost') THEN
    PERFORM fn_rule('Choose the condition: good, damaged or lost.');
  END IF;
  IF p_condition = 'damaged' AND (p_damage_amount IS NULL OR p_damage_amount < 0) THEN
    PERFORM fn_rule('Enter the damage amount in pesos (0 or more).');
  END IF;

  SELECT b.replacement_cost INTO v_cost FROM book b WHERE b.book_id = t.book_id FOR UPDATE;
  v_days := greatest(current_date - t.due_date, 0);

  IF p_condition = 'lost' THEN
    UPDATE transaction SET status = 'lost' WHERE transaction_id = t.transaction_id;
    UPDATE book SET copies_total = copies_total - 1 WHERE book_id = t.book_id;  -- the copy was out, so available stays
    INSERT INTO fine (transaction_id, fine_type, amount) VALUES (t.transaction_id, 'lost', v_cost)
    RETURNING fine_id INTO v_fine;
  ELSE
    UPDATE transaction SET status = 'returned', date_returned = current_date WHERE transaction_id = t.transaction_id;
    UPDATE book SET copies_available = copies_available + 1 WHERE book_id = t.book_id;
    IF p_condition = 'damaged' AND p_damage_amount > 0 THEN
      INSERT INTO fine (transaction_id, fine_type, amount) VALUES (t.transaction_id, 'damaged', round(p_damage_amount, 2))
      RETURNING fine_id INTO v_fine;
    END IF;
  END IF;

  IF v_days > 0 THEN
    INSERT INTO fine (transaction_id, fine_type, amount)
    VALUES (t.transaction_id, 'overdue', round(v_days * fn_setting_num('fine_per_day', 10), 2))
    RETURNING fine_id INTO v_extra;
    v_fine := coalesce(v_fine, v_extra);
  END IF;
  RETURN v_fine;
END $$;

-- ===========================================================================
-- Fines
-- ===========================================================================

CREATE OR REPLACE FUNCTION fn_pay_fine(p_fine_id int, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_status text;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT f.status INTO v_status FROM fine f WHERE f.fine_id = p_fine_id FOR UPDATE;
  IF v_status IS NULL THEN PERFORM fn_rule('That fine does not exist.', 'LM002'); END IF;
  IF v_status <> 'unpaid' THEN PERFORM fn_rule(format('This fine is already %s.', v_status)); END IF;
  UPDATE fine SET status = 'paid', date_paid = current_date WHERE fine_id = p_fine_id;
END $$;

CREATE OR REPLACE FUNCTION fn_waive_fine(p_fine_id int, p_staff_id int, p_reason text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_status text;
BEGIN
  PERFORM fn_check_staff(p_staff_id, true);
  IF coalesce(btrim(p_reason), '') = '' THEN PERFORM fn_rule('Write a reason for waiving the fine.'); END IF;
  SELECT f.status INTO v_status FROM fine f WHERE f.fine_id = p_fine_id FOR UPDATE;
  IF v_status IS NULL THEN PERFORM fn_rule('That fine does not exist.', 'LM002'); END IF;
  IF v_status <> 'unpaid' THEN PERFORM fn_rule(format('This fine is already %s.', v_status)); END IF;
  UPDATE fine SET status = 'waived', waived_reason = btrim(p_reason) WHERE fine_id = p_fine_id;
END $$;

-- ===========================================================================
-- Books
-- ===========================================================================

-- copies_available moves by the same difference as copies_total. The total never goes above the title's copy_limit.
CREATE OR REPLACE FUNCTION fn_set_copies_total(p_book_id int, p_new_total int, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_title text; v_total int; v_available int; v_out int; v_limit int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT b.title, b.copies_total, b.copies_available, b.copy_limit INTO v_title, v_total, v_available, v_limit
    FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF v_title IS NULL THEN PERFORM fn_rule('That book does not exist.', 'LM002'); END IF;
  IF p_new_total IS NULL OR p_new_total < 0 THEN PERFORM fn_rule('Total copies must be 0 or more.'); END IF;
  IF p_new_total > v_limit THEN
    PERFORM fn_rule(format('“%s” can have at most %s %s.', v_title, v_limit, CASE WHEN v_limit = 1 THEN 'copy' ELSE 'copies' END));
  END IF;

  v_out := v_total - v_available;
  IF p_new_total < v_out THEN
    PERFORM fn_rule(format('“%s” cannot go down to %s: %s on loan or reserved right now. Set %s or more.',
                           v_title, p_new_total,
                           CASE WHEN v_out = 1 THEN '1 copy is' ELSE v_out || ' copies are' END, v_out));
  END IF;

  UPDATE book
     SET copies_available = copies_available + (p_new_total - copies_total),
         copies_total = p_new_total
   WHERE book_id = p_book_id;
END $$;

-- Raise or lower how many copies a title may have. Admin only; never below the copies the library has now.
-- The change is written to audit_log by the book trigger.
CREATE OR REPLACE FUNCTION fn_set_copy_limit(p_book_id int, p_new_limit int, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_title text; v_total int;
BEGIN
  PERFORM fn_check_staff(p_staff_id, true);
  SELECT b.title, b.copies_total INTO v_title, v_total FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF v_title IS NULL THEN PERFORM fn_rule('That book does not exist.', 'LM002'); END IF;
  IF p_new_limit IS NULL OR p_new_limit < 1 OR p_new_limit > 999 THEN
    PERFORM fn_rule('The copy limit must be a whole number from 1 to 999.');
  END IF;
  IF p_new_limit < v_total THEN
    PERFORM fn_rule(format('“%s” has %s copies, so its limit cannot be %s. Remove %s %s first.',
                           v_title, v_total, p_new_limit, v_total - p_new_limit,
                           CASE WHEN v_total - p_new_limit = 1 THEN 'copy' ELSE 'copies' END));
  END IF;
  UPDATE book SET copy_limit = p_new_limit WHERE book_id = p_book_id AND copy_limit IS DISTINCT FROM p_new_limit;
END $$;

-- Archive or restore. Archiving is blocked while copies are out or reserved; pending requests are cancelled with a message.
CREATE OR REPLACE FUNCTION fn_set_book_archived(p_book_id int, p_archived boolean, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_title text; v_loans int; v_reserved int; v_parts text[] := '{}'; v_req int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT b.title INTO v_title FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF v_title IS NULL THEN PERFORM fn_rule('That book does not exist.', 'LM002'); END IF;

  IF p_archived THEN
    SELECT count(*) INTO v_loans FROM transaction x WHERE x.book_id = p_book_id AND x.status IN ('borrowed', 'overdue');
    SELECT count(*) INTO v_reserved FROM book_request r WHERE r.book_id = p_book_id AND r.status = 'approved';
    IF v_loans > 0 THEN v_parts := v_parts || (CASE WHEN v_loans = 1 THEN '1 copy is on loan' ELSE v_loans || ' copies are on loan' END); END IF;
    IF v_reserved > 0 THEN v_parts := v_parts || (CASE WHEN v_reserved = 1 THEN '1 copy is reserved' ELSE v_reserved || ' copies are reserved' END); END IF;
    IF cardinality(v_parts) > 0 THEN
      PERFORM fn_rule(format('“%s” cannot be archived yet: %s. Wait for the returns or cancel the reservations first.',
                             v_title, array_to_string(v_parts, ' and ')));
    END IF;
    FOR v_req IN SELECT r.request_id FROM book_request r WHERE r.book_id = p_book_id AND r.status = 'pending' ORDER BY r.request_id LOOP
      PERFORM fn_cancel_request(v_req, 'staff', p_staff_id, 'This book was removed from the catalog.');
    END LOOP;
  END IF;

  UPDATE book SET is_archived = p_archived WHERE book_id = p_book_id AND is_archived IS DISTINCT FROM p_archived;
END $$;

-- ===========================================================================
-- People
-- ===========================================================================

CREATE OR REPLACE FUNCTION fn_set_user_active(p_user_type text, p_user_id int, p_active boolean, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_name text; v_position text; v_borrowed int := 0; v_overdue int := 0; v_reserved int := 0; v_parts text[] := '{}';
  v_sid int; v_tid int;
BEGIN
  IF p_user_type NOT IN ('student', 'teacher', 'staff') THEN PERFORM fn_rule('Unknown kind of user.'); END IF;
  IF p_active IS NULL THEN PERFORM fn_rule('Choose active or inactive.'); END IF;

  IF p_user_type = 'staff' THEN
    PERFORM fn_check_staff(p_staff_id, true);  -- only an admin manages staff accounts
    IF p_user_id = p_staff_id AND NOT p_active THEN PERFORM fn_rule('You cannot deactivate your own account.'); END IF;
    SELECT s.first_name || ' ' || s.last_name, s.position INTO v_name, v_position
      FROM staff s WHERE s.staff_id = p_user_id FOR UPDATE;
    IF v_name IS NULL THEN PERFORM fn_rule('That staff account does not exist.', 'LM002'); END IF;
    IF NOT p_active AND v_position = 'admin' THEN
      PERFORM 1 FROM staff s WHERE s.position = 'admin' FOR UPDATE;  -- two admins cannot deactivate each other at once
      IF NOT EXISTS (SELECT 1 FROM staff s WHERE s.position = 'admin' AND s.is_active AND s.staff_id <> p_user_id) THEN
        PERFORM fn_rule(format('%s is the last active admin, so the account cannot be deactivated. Make another admin first.', v_name));
      END IF;
    END IF;
    UPDATE staff SET is_active = p_active WHERE staff_id = p_user_id AND is_active IS DISTINCT FROM p_active;
    RETURN;
  END IF;

  PERFORM fn_check_staff(p_staff_id);
  IF p_user_type = 'student' THEN
    v_sid := p_user_id;
    SELECT s.first_name || ' ' || s.last_name INTO v_name FROM student s WHERE s.student_id = p_user_id FOR UPDATE;
  ELSE
    v_tid := p_user_id;
    SELECT t.first_name || ' ' || t.last_name INTO v_name FROM teacher t WHERE t.teacher_id = p_user_id FOR UPDATE;
  END IF;
  IF v_name IS NULL THEN PERFORM fn_rule('That person does not exist.', 'LM002'); END IF;

  IF NOT p_active THEN
    SELECT count(*) FILTER (WHERE x.status = 'borrowed' AND x.due_date >= current_date),
           count(*) FILTER (WHERE x.status = 'overdue' OR (x.status = 'borrowed' AND x.due_date < current_date))
      INTO v_borrowed, v_overdue
      FROM transaction x WHERE x.student_id = v_sid OR x.teacher_id = v_tid;
    SELECT count(*) INTO v_reserved FROM book_request r
     WHERE (r.student_id = v_sid OR r.teacher_id = v_tid) AND r.status = 'approved';
    IF v_overdue > 0 THEN v_parts := v_parts || fn_count_books(v_overdue, 'overdue'); END IF;
    IF v_borrowed > 0 THEN v_parts := v_parts || fn_count_books(v_borrowed, 'borrowed'); END IF;
    IF v_reserved > 0 THEN v_parts := v_parts || fn_count_books(v_reserved, 'reserved'); END IF;
    IF cardinality(v_parts) > 0 THEN
      PERFORM fn_rule(format('%s has %s. They must be returned or cancelled before the account can be deactivated.',
                             v_name, array_to_string(v_parts, ' and ')));
    END IF;
    -- Pending requests of an inactive person can never be approved, so they are closed now.
    UPDATE book_request
       SET status = 'cancelled', date_decided = now(), decided_by_id = p_staff_id, decision_note = 'Account deactivated'
     WHERE (student_id = v_sid OR teacher_id = v_tid) AND status = 'pending';
  END IF;

  IF p_user_type = 'student' THEN
    UPDATE student SET is_active = p_active WHERE student_id = p_user_id AND is_active IS DISTINCT FROM p_active;
  ELSE
    UPDATE teacher SET is_active = p_active WHERE teacher_id = p_user_id AND is_active IS DISTINCT FROM p_active;
  END IF;
END $$;

-- Hard delete: admin only, never yourself, and only for people with no history.
CREATE OR REPLACE FUNCTION fn_delete_user(p_user_type text, p_user_id int, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_name text; v_position text; v_history boolean;
BEGIN
  PERFORM fn_check_staff(p_staff_id, true);
  IF p_user_type = 'student' THEN
    SELECT s.first_name || ' ' || s.last_name INTO v_name FROM student s WHERE s.student_id = p_user_id FOR UPDATE;
    v_history := EXISTS (SELECT 1 FROM transaction x WHERE x.student_id = p_user_id)
              OR EXISTS (SELECT 1 FROM book_request r WHERE r.student_id = p_user_id)
              OR EXISTS (SELECT 1 FROM message m WHERE m.recipient_student_id = p_user_id);
  ELSIF p_user_type = 'teacher' THEN
    SELECT t.first_name || ' ' || t.last_name INTO v_name FROM teacher t WHERE t.teacher_id = p_user_id FOR UPDATE;
    v_history := EXISTS (SELECT 1 FROM transaction x WHERE x.teacher_id = p_user_id)
              OR EXISTS (SELECT 1 FROM book_request r WHERE r.teacher_id = p_user_id)
              OR EXISTS (SELECT 1 FROM message m WHERE m.recipient_teacher_id = p_user_id);
  ELSIF p_user_type = 'staff' THEN
    IF p_user_id = p_staff_id THEN PERFORM fn_rule('You cannot delete your own account.'); END IF;
    SELECT s.first_name || ' ' || s.last_name, s.position INTO v_name, v_position FROM staff s WHERE s.staff_id = p_user_id FOR UPDATE;
    v_history := EXISTS (SELECT 1 FROM transaction x WHERE x.staff_id = p_user_id)
              OR EXISTS (SELECT 1 FROM book_request r WHERE r.decided_by_id = p_user_id)
              OR EXISTS (SELECT 1 FROM message m WHERE m.sender_staff_id = p_user_id)
              OR EXISTS (SELECT 1 FROM book b WHERE b.added_by_id = p_user_id);
    IF v_position = 'admin' AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.position = 'admin' AND s.is_active AND s.staff_id <> p_user_id) THEN
      PERFORM fn_rule(format('%s is the last active admin and cannot be deleted.', v_name));
    END IF;
  ELSE
    PERFORM fn_rule('Unknown kind of user.');
  END IF;

  IF v_name IS NULL THEN PERFORM fn_rule('That person does not exist.', 'LM002'); END IF;
  IF v_history THEN PERFORM fn_rule(format('%s has borrowing history. Deactivate instead.', v_name)); END IF;

  IF p_user_type = 'student' THEN DELETE FROM student WHERE student_id = p_user_id;
  ELSIF p_user_type = 'teacher' THEN DELETE FROM teacher WHERE teacher_id = p_user_id;
  ELSE DELETE FROM staff WHERE staff_id = p_user_id;
  END IF;
END $$;

-- ===========================================================================
-- Messages
-- ===========================================================================

-- Sets read_at only when the message belongs to that person. Returns true if it changed.
CREATE OR REPLACE FUNCTION fn_mark_message_read(p_message_id int, p_student_id int, p_teacher_id int)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
BEGIN
  UPDATE message
     SET read_at = now()
   WHERE message_id = p_message_id
     AND read_at IS NULL
     AND ((p_student_id IS NOT NULL AND recipient_student_id = p_student_id)
       OR (p_teacher_id IS NOT NULL AND recipient_teacher_id = p_teacher_id));
  RETURN FOUND;
END $$;

-- audience: all_students, all_teachers or everyone. One general message per active recipient.
CREATE OR REPLACE FUNCTION fn_broadcast_message(p_audience text, p_subject text, p_body text, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_count int := 0; v_n int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM fn_check_message(p_subject, p_body);
  IF p_audience IS NULL OR p_audience NOT IN ('all_students', 'all_teachers', 'everyone') THEN
    PERFORM fn_rule('Choose who gets the message: All students, All teachers or Everyone.');
  END IF;
  IF p_audience IN ('all_students', 'everyone') THEN
    INSERT INTO message (sender_staff_id, recipient_student_id, kind, subject, body)
    SELECT p_staff_id, s.student_id, 'general', btrim(p_subject), p_body FROM student s WHERE s.is_active;
    GET DIAGNOSTICS v_n = ROW_COUNT;
    v_count := v_count + v_n;
  END IF;
  IF p_audience IN ('all_teachers', 'everyone') THEN
    INSERT INTO message (sender_staff_id, recipient_teacher_id, kind, subject, body)
    SELECT p_staff_id, t.teacher_id, 'general', btrim(p_subject), p_body FROM teacher t WHERE t.is_active;
    GET DIAGNOSTICS v_n = ROW_COUNT;
    v_count := v_count + v_n;
  END IF;
  RETURN v_count;
END $$;

-- One message to one borrower. kind is reminder, overdue or general.
CREATE OR REPLACE FUNCTION fn_send_message(p_staff_id int, p_student_id int, p_teacher_id int, p_kind text, p_subject text, p_body text)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM fn_check_message(p_subject, p_body);
  IF (p_student_id IS NOT NULL)::int + (p_teacher_id IS NOT NULL)::int <> 1 THEN
    PERFORM fn_rule('Choose one recipient: a student or a teacher.');
  END IF;
  IF p_kind IS NULL OR p_kind NOT IN ('reminder', 'overdue', 'general') THEN
    PERFORM fn_rule('A message sent from here must be a reminder, an overdue notice or a general message.');
  END IF;
  IF fn_person_name(p_student_id, p_teacher_id) IS NULL THEN
    PERFORM fn_rule('That recipient does not exist.', 'LM002');
  END IF;
  INSERT INTO message (sender_staff_id, recipient_student_id, recipient_teacher_id, kind, subject, body)
  VALUES (p_staff_id, p_student_id, p_teacher_id, p_kind, btrim(p_subject), p_body)
  RETURNING message_id INTO v_id;
  RETURN v_id;
END $$;

-- Fill a preset with real names and dates. The only place placeholders are replaced, so PHP and tests agree.
-- request_id for approval and rejection presets, transaction_id for reminder and overdue presets.
-- An unknown placeholder (or one with no value yet) is left visible, for example {pickup_time}.
CREATE OR REPLACE FUNCTION fn_render_template(p_template_id int, p_request_id int, p_transaction_id int,
                                              p_pickup_date date DEFAULT NULL, p_pickup_time time DEFAULT NULL)
RETURNS TABLE (subject text, body text)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_kind text; v_subject text; v_body text;
  v_student int; v_teacher int; v_book int;
  v_pickup_date date; v_pickup_time time; v_deadline date; v_due date;
  v_loan_days int; v_max int; v_active int; v_fines numeric; v_staff text;
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id text := nullif(current_setting('app.actor_id', true), '');
  v_map jsonb; v_key text; v_val text;
BEGIN
  SELECT mt.kind, mt.subject, mt.body INTO v_kind, v_subject, v_body
    FROM message_template mt WHERE mt.template_id = p_template_id;
  IF v_kind IS NULL THEN PERFORM fn_rule('That message preset does not exist.', 'LM002'); END IF;

  IF v_kind IN ('request_approved', 'request_rejected') THEN
    SELECT r.student_id, r.teacher_id, r.book_id, coalesce(p_pickup_date, r.pickup_date), coalesce(p_pickup_time, r.pickup_time)
      INTO v_student, v_teacher, v_book, v_pickup_date, v_pickup_time
      FROM book_request r WHERE r.request_id = p_request_id;
    IF v_book IS NULL THEN PERFORM fn_rule('Choose a request for this preset.', 'LM002'); END IF;
  ELSE
    SELECT x.student_id, x.teacher_id, x.book_id, x.due_date
      INTO v_student, v_teacher, v_book, v_due
      FROM transaction x WHERE x.transaction_id = p_transaction_id;
    IF v_book IS NULL THEN PERFORM fn_rule('Choose a loan for this preset.', 'LM002'); END IF;
    v_pickup_date := p_pickup_date;
    v_pickup_time := p_pickup_time;
  END IF;

  v_loan_days := CASE WHEN v_student IS NOT NULL THEN fn_setting_int('loan_days_student', 14) ELSE fn_setting_int('loan_days_teacher', 14) END;
  v_max := CASE WHEN v_student IS NOT NULL THEN fn_setting_int('max_loans_student', 5) ELSE fn_setting_int('max_loans_teacher', 8) END;
  IF v_pickup_date IS NOT NULL THEN
    v_deadline := v_pickup_date + fn_setting_int('pickup_window_days', 2);
    v_due := coalesce(v_due, v_pickup_date + v_loan_days);
  END IF;

  SELECT count(*) INTO v_active FROM transaction x
   WHERE (x.student_id = v_student OR x.teacher_id = v_teacher) AND x.status IN ('borrowed', 'overdue');
  SELECT coalesce(sum(f.amount), 0) INTO v_fines
    FROM fine f JOIN transaction x ON x.transaction_id = f.transaction_id
   WHERE f.status = 'unpaid' AND (x.student_id = v_student OR x.teacher_id = v_teacher);

  IF v_actor_type = 'staff' AND v_actor_id ~ '^\d+$' THEN
    SELECT s.first_name || ' ' || s.last_name INTO v_staff FROM staff s WHERE s.staff_id = v_actor_id::int;
  END IF;

  v_map := jsonb_build_object(
    'borrower_name',   fn_person_name(v_student, v_teacher, true),
    'book_title',      (SELECT b.title FROM book b WHERE b.book_id = v_book),
    'pickup_date',     fn_fmt_date(v_pickup_date),
    'pickup_time',     fn_fmt_time(v_pickup_time),
    'pickup_deadline', fn_fmt_date(v_deadline),
    'loan_days',       v_loan_days::text,
    'due_date',        fn_fmt_date(v_due),
    'librarian_name',  coalesce(v_staff, 'The library team'),
    'library_name',    coalesce(fn_setting('library_name'), 'School Library'),
    'library_hours',   coalesce(fn_setting('library_hours'), 'Mon-Fri 8:00 AM-5:00 PM'),
    'active_loans',    v_active::text,
    'max_loans',       v_max::text,
    'fine_total',      fn_fmt_money(v_fines),
    'fine_per_day',    fn_fmt_money(fn_setting_num('fine_per_day', 10))
  );

  FOR v_key, v_val IN SELECT e.key, e.value FROM jsonb_each_text(v_map) e LOOP
    IF v_val IS NOT NULL THEN
      v_subject := replace(v_subject, '{' || v_key || '}', v_val);
      v_body := replace(v_body, '{' || v_key || '}', v_val);
    END IF;
  END LOOP;

  subject := left(v_subject, 150);
  body := left(v_body, 1000);
  RETURN NEXT;
END $$;

-- ===========================================================================
-- Housekeeping procedures (called when the dashboard loads)
-- ===========================================================================

-- Borrowed loans past their due date become overdue. Logged as the system, not as whoever loaded the page.
CREATE OR REPLACE PROCEDURE sp_mark_overdue()
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_type text := coalesce(current_setting('app.actor_type', true), '');
  v_id text := coalesce(current_setting('app.actor_id', true), '');
BEGIN
  PERFORM set_config('app.actor_type', 'system', true), set_config('app.actor_id', '', true);
  UPDATE transaction SET status = 'overdue' WHERE status = 'borrowed' AND due_date < current_date;
  PERFORM set_config('app.actor_type', v_type, true), set_config('app.actor_id', v_id, true);
END $$;

-- Approved requests past their pickup deadline expire: the copy goes back and the borrower gets a system message.
CREATE OR REPLACE PROCEDURE sp_expire_reservations()
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_type text := coalesce(current_setting('app.actor_type', true), '');
  v_id text := coalesce(current_setting('app.actor_id', true), '');
  r record; v_title text;
BEGIN
  PERFORM set_config('app.actor_type', 'system', true), set_config('app.actor_id', '', true);
  FOR r IN
    SELECT br.request_id, br.student_id, br.teacher_id, br.book_id, br.pickup_deadline
      FROM book_request br
     WHERE br.status = 'approved' AND br.pickup_deadline < current_date
     ORDER BY br.request_id
       FOR UPDATE
  LOOP
    SELECT b.title INTO v_title FROM book b WHERE b.book_id = r.book_id FOR UPDATE;
    UPDATE book SET copies_available = copies_available + 1 WHERE book_id = r.book_id;
    UPDATE book_request SET status = 'expired' WHERE request_id = r.request_id;
    INSERT INTO message (sender_staff_id, recipient_student_id, recipient_teacher_id, request_id, kind, subject, body)
    VALUES (NULL, r.student_id, r.teacher_id, r.request_id, 'general',
            left(format('Your reservation for %s has expired', v_title), 150),
            left(format('Hi %s, we held “%s” for you until %s, but it was not picked up, so it is back on the shelf. You can request it again from the catalog. %s',
                        fn_person_name(r.student_id, r.teacher_id, true), v_title, fn_fmt_date(r.pickup_deadline),
                        coalesce(fn_setting('library_name'), 'School Library')), 1000));
  END LOOP;
  PERFORM set_config('app.actor_type', v_type, true), set_config('app.actor_id', v_id, true);
END $$;
