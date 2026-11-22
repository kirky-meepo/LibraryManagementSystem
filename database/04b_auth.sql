-- 04b_auth.sql: signing in, signing up, signing out and passwords, inside the database (docs/SPEC.md, AUTH ROUTINES).
-- PHP keeps password_hash(), password_verify() and password_needs_rehash(): only hashes ever reach these routines.
-- Every routine is SECURITY DEFINER, owned by lms_owner (05_roles.sql), with a fixed search_path, and PUBLIC
-- cannot run it. Each one writes its own audit_log row (login, logout, sign_up, password_changed,
-- password_reset_by_staff, password_reset_requested, password_reset). No detail ever holds a password,
-- a hash or a reset token.
\set ON_ERROR_STOP on
SET search_path = lms, public;
SET client_min_messages = warning;

-- ---------------------------------------------------------------------------
-- Small helpers
-- ---------------------------------------------------------------------------

-- True for the output of PHP's password_hash(): bcrypt ($2y$) or Argon2 ($argon2i$ / $argon2id$).
CREATE OR REPLACE FUNCTION fn_auth_hash_ok(p_hash text) RETURNS boolean
LANGUAGE sql IMMUTABLE SET search_path = lms, pg_temp AS $$
  SELECT coalesce(length(p_hash) <= 255
     AND (p_hash ~ '^\$2y\$[0-9]{2}\$[./A-Za-z0-9]{53}$'
          OR p_hash ~ '^\$argon2(i|id)\$v=[0-9]+\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$'), false)
$$;

CREATE OR REPLACE FUNCTION fn_auth_check_hash(p_hash text) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path = lms, pg_temp AS $$
BEGIN
  IF NOT fn_auth_hash_ok(p_hash) THEN
    PERFORM fn_rule('Only a value made by password_hash() can be stored as a password.');
  END IF;
END $$;

-- Email, first name and active flag of one account, or no row.
CREATE OR REPLACE FUNCTION fn_auth_account(p_user_type text, p_user_id int)
RETURNS TABLE (email text, first_name text, is_active boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT s.email::text, s.first_name::text, s.is_active FROM staff s   WHERE p_user_type = 'staff'   AND s.staff_id = p_user_id
  UNION ALL
  SELECT s.email::text, s.first_name::text, s.is_active FROM student s WHERE p_user_type = 'student' AND s.student_id = p_user_id
  UNION ALL
  SELECT t.email::text, t.first_name::text, t.is_active FROM teacher t WHERE p_user_type = 'teacher' AND t.teacher_id = p_user_id
$$;

-- Store a new hash for one account. The audit trigger is told to skip this password-only update,
-- because the calling routine writes a clearer row of its own.
CREATE OR REPLACE FUNCTION fn_auth_store_hash(p_user_type text, p_user_id int, p_hash text, p_must_change boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_rows int;
BEGIN
  PERFORM fn_auth_check_hash(p_hash);
  PERFORM set_config('lms.audit_quiet', 'password', true);
  IF p_user_type = 'staff' THEN
    UPDATE staff s SET password_hash = p_hash, must_change_password = p_must_change WHERE s.staff_id = p_user_id;
  ELSIF p_user_type = 'student' THEN
    UPDATE student s SET password_hash = p_hash, must_change_password = p_must_change WHERE s.student_id = p_user_id;
  ELSIF p_user_type = 'teacher' THEN
    UPDATE teacher t SET password_hash = p_hash, must_change_password = p_must_change WHERE t.teacher_id = p_user_id;
  ELSE
    PERFORM set_config('lms.audit_quiet', '', true);
    PERFORM fn_rule('Unknown account type.', 'LM004');
  END IF;
  GET DIAGNOSTICS v_rows = ROW_COUNT;
  PERFORM set_config('lms.audit_quiet', '', true);
  IF v_rows = 0 THEN
    PERFORM fn_rule('That account no longer exists. Reload the page.', 'LM002');
  END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Sign in
-- ---------------------------------------------------------------------------

-- The lock-out rule: 5 failed sign-ins within 15 minutes (counted since the last success) lock that email,
-- and the same count from one IP address locks that address. Always returns one row.
-- When locked, no account data is returned at all.
CREATE OR REPLACE FUNCTION fn_login_lookup(p_email text, p_ip inet, p_kind text)
RETURNS TABLE (locked boolean, tries_left int, lock_minutes int, user_type text, user_id int, role text,
               display_name text, password_hash text, is_active boolean, must_change_password boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  c_max_failures constant int := 5;
  c_minutes      constant int := 15;
  v_email    text := left(lower(btrim(coalesce(p_email, ''))), 254);
  v_by_email int;
  v_by_ip    int := 0;
BEGIN
  IF p_kind IS NULL OR p_kind NOT IN ('member', 'staff') THEN
    PERFORM fn_rule('Choose Member or Librarian/Admin.');
  END IF;

  SELECT count(*) INTO v_by_email
    FROM login_attempt a
   WHERE a.email = v_email AND NOT a.success
     AND a.attempted_at > greatest(now() - make_interval(mins => c_minutes),
           coalesce((SELECT max(s.attempted_at) FROM login_attempt s WHERE s.email = v_email AND s.success), '-infinity'));
  IF p_ip IS NOT NULL THEN
    SELECT count(*) INTO v_by_ip
      FROM login_attempt a
     WHERE a.ip = p_ip AND NOT a.success
       AND a.attempted_at > greatest(now() - make_interval(mins => c_minutes),
             coalesce((SELECT max(s.attempted_at) FROM login_attempt s WHERE s.ip = p_ip AND s.success), '-infinity'));
  END IF;

  locked := greatest(v_by_email, v_by_ip) >= c_max_failures;
  tries_left := greatest(c_max_failures - greatest(v_by_email, v_by_ip), 0);
  lock_minutes := c_minutes;
  IF locked THEN
    RETURN NEXT;
    RETURN;
  END IF;

  IF p_kind = 'staff' THEN
    SELECT 'staff', s.staff_id, s.position, s.first_name || ' ' || s.last_name, s.password_hash, s.is_active, s.must_change_password
      INTO user_type, user_id, role, display_name, password_hash, is_active, must_change_password
      FROM staff s WHERE s.email = v_email;
  ELSE
    -- Members sign in with one form: students first, then teachers (an email is never in both).
    SELECT 'student', s.student_id, 'student', s.first_name || ' ' || s.last_name, s.password_hash, s.is_active, s.must_change_password
      INTO user_type, user_id, role, display_name, password_hash, is_active, must_change_password
      FROM student s WHERE s.email = v_email;
    IF user_id IS NULL THEN
      SELECT 'teacher', t.teacher_id, 'teacher', t.first_name || ' ' || t.last_name, t.password_hash, t.is_active, t.must_change_password
        INTO user_type, user_id, role, display_name, password_hash, is_active, must_change_password
        FROM teacher t WHERE t.email = v_email;
    END IF;
  END IF;
  RETURN NEXT;
END $$;

-- One sign-in try. A success must name the active account that owns this email, and also writes an
-- audit row 'login' with that person as the actor.
CREATE OR REPLACE FUNCTION fn_record_login(p_email text, p_ip inet, p_kind text, p_success boolean,
                                           p_user_type text DEFAULT NULL, p_user_id int DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_email text := left(lower(btrim(coalesce(p_email, ''))), 254);
  v_ok    boolean;
BEGIN
  IF p_kind IS NULL OR p_kind NOT IN ('member', 'staff') THEN
    PERFORM fn_rule('Choose Member or Librarian/Admin.');
  END IF;
  IF coalesce(p_success, false) THEN
    v_ok := (p_kind = 'staff' AND p_user_type = 'staff') OR (p_kind = 'member' AND p_user_type IN ('student', 'teacher'));
    v_ok := v_ok AND EXISTS (SELECT 1 FROM fn_auth_account(p_user_type, p_user_id) a WHERE a.email = v_email AND a.is_active);
    IF NOT coalesce(v_ok, false) THEN
      PERFORM fn_rule('That sign-in does not match an active account.', 'LM004');
    END IF;
  END IF;

  INSERT INTO login_attempt (email, user_type, success, ip) VALUES (v_email, p_kind, coalesce(p_success, false), p_ip);

  IF coalesce(p_success, false) THEN
    INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
    VALUES (p_user_type, p_user_id, 'login', p_user_type, p_user_id::text, jsonb_build_object('ip', host(p_ip)));
  END IF;
END $$;

-- Sign out: one audit row 'logout'. PHP destroys the session afterwards.
CREATE OR REPLACE FUNCTION fn_logout(p_user_type text, p_user_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
BEGIN
  IF p_user_type IS NULL OR p_user_type NOT IN ('staff', 'student', 'teacher') OR p_user_id IS NULL THEN
    PERFORM fn_rule('Unknown account.', 'LM004');
  END IF;
  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES (p_user_type, p_user_id, 'logout', p_user_type, p_user_id::text, '{}'::jsonb);
END $$;

-- ---------------------------------------------------------------------------
-- Passwords
-- ---------------------------------------------------------------------------

-- Your own new password (Change password, Profile, and the silent re-hash at sign-in).
-- The actor PHP sets for the transaction must be this same account.
CREATE OR REPLACE FUNCTION fn_set_password(p_user_type text, p_user_id int, p_new_hash text, p_must_change boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id   text := nullif(current_setting('app.actor_id', true), '');
BEGIN
  IF v_actor_type IS DISTINCT FROM p_user_type OR v_actor_id IS DISTINCT FROM p_user_id::text THEN
    PERFORM fn_rule('You can only change your own password here.', 'LM004');
  END IF;
  PERFORM fn_auth_store_hash(p_user_type, p_user_id, p_new_hash, coalesce(p_must_change, false));
  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES (p_user_type, p_user_id, 'password_changed', p_user_type, p_user_id::text, '{}'::jsonb);
END $$;

-- Manage Users, Reset password: a temporary password the person must change at the next sign-in.
-- Same rules as before: any librarian for students and teachers, only an admin for staff, never yourself.
CREATE OR REPLACE FUNCTION fn_staff_reset_password(p_staff_id int, p_user_type text, p_user_id int, p_temp_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
BEGIN
  IF p_user_type IS NULL OR p_user_type NOT IN ('staff', 'student', 'teacher') THEN
    PERFORM fn_rule('Choose a student, teacher or staff account.', 'LM004');
  END IF;
  PERFORM fn_check_staff(p_staff_id, p_user_type = 'staff');
  IF p_user_type = 'staff' AND p_user_id = p_staff_id THEN
    PERFORM fn_rule('To change your own password, use Profile.');
  END IF;
  PERFORM fn_auth_store_hash(p_user_type, p_user_id, p_temp_hash, true);
  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES ('staff', p_staff_id, 'password_reset_by_staff', p_user_type, p_user_id::text, '{}'::jsonb);
END $$;

-- ---------------------------------------------------------------------------
-- Sign up (students and teachers only; staff accounts are made by an admin)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_register_member(
  p_user_type text, p_first_name text, p_middle_name text, p_last_name text, p_email text, p_password_hash text,
  p_course text, p_year_level int, p_department text, p_contact_number text, p_ip inet)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  c_per_ip_hour constant int := 5;
  v_email  text := lower(btrim(coalesce(p_email, '')));
  v_first  text := btrim(coalesce(p_first_name, ''));
  v_last   text := btrim(coalesce(p_last_name, ''));
  v_middle text := nullif(btrim(coalesce(p_middle_name, '')), '');
  v_phone  text := nullif(btrim(coalesce(p_contact_number, '')), '');
  v_recent int;
  v_id     int;
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id   text := nullif(current_setting('app.actor_id', true), '');
  v_by_staff   int;
BEGIN
  IF v_actor_type = 'staff' AND v_actor_id ~ '^\d{1,9}$' THEN
    v_by_staff := v_actor_id::int;
  END IF;
  IF p_user_type IS NULL OR p_user_type NOT IN ('student', 'teacher') THEN
    PERFORM fn_rule('Sign up is only for students and teachers. Staff accounts are made by an admin.', 'LM004');
  END IF;
  IF v_first = '' OR v_last = '' THEN
    PERFORM fn_rule('Type your first and last name.');
  END IF;
  IF length(v_email) > 254 OR v_email !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
    PERFORM fn_rule('Type a full email address, for example juan.cruz@school.edu.');
  END IF;
  PERFORM fn_auth_check_hash(p_password_hash);

  IF p_ip IS NOT NULL THEN
    SELECT count(*) INTO v_recent
      FROM audit_log a
     WHERE a.action = 'sign_up' AND a.detail ->> 'ip' = host(p_ip) AND a.created_at > now() - interval '1 hour';
    IF v_recent >= c_per_ip_hour THEN
      PERFORM fn_rule('Too many accounts were made from this network in the last hour. Try again later.');
    END IF;
  END IF;

  -- One email, one account, across students, teachers and staff.
  PERFORM pg_advisory_xact_lock(hashtext('member-email:' || v_email));
  IF EXISTS (SELECT 1 FROM student s WHERE s.email = v_email)
     OR EXISTS (SELECT 1 FROM teacher t WHERE t.email = v_email)
     OR EXISTS (SELECT 1 FROM staff f WHERE f.email = v_email) THEN
    PERFORM fn_rule('That email already has an account. Sign in, or use Forgot your password.');
  END IF;

  IF p_user_type = 'student' THEN
    IF coalesce(btrim(p_course), '') = '' THEN PERFORM fn_rule('Type your course.'); END IF;
    IF p_year_level IS NULL OR p_year_level NOT BETWEEN 1 AND 6 THEN
      PERFORM fn_rule('Choose a year level from 1 to 6.');
    END IF;
    INSERT INTO student (first_name, middle_name, last_name, course, year_level, contact_number, email, password_hash)
    VALUES (v_first, v_middle, v_last, btrim(p_course), p_year_level, v_phone, v_email, p_password_hash)
    RETURNING student_id INTO v_id;
  ELSE
    IF coalesce(btrim(p_department), '') = '' THEN PERFORM fn_rule('Type your department.'); END IF;
    INSERT INTO teacher (first_name, middle_name, last_name, department, contact_number, email, password_hash)
    VALUES (v_first, v_middle, v_last, btrim(p_department), v_phone, v_email, p_password_hash)
    RETURNING teacher_id INTO v_id;
  END IF;

  -- The new member is the actor, unless a staff member made the account for them (for example the demo seed).
  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES (CASE WHEN v_by_staff IS NOT NULL THEN 'staff' ELSE p_user_type END, coalesce(v_by_staff, v_id),
          'sign_up', p_user_type, v_id::text, jsonb_build_object('ip', host(p_ip)));
  RETURN v_id;
END $$;

-- ---------------------------------------------------------------------------
-- Forgot password
-- ---------------------------------------------------------------------------

-- Make a reset link for an active account. PHP creates the token and sends only its sha256 (64 hex characters).
-- An unknown or inactive email, a 4th open link for one account, or a 6th link from one IP address in an
-- hour all return no row and raise no error, so the page cannot reveal which emails exist.
CREATE OR REPLACE FUNCTION fn_request_password_reset(p_email text, p_ip inet, p_token_hash text)
RETURNS TABLE (user_type text, user_id int, first_name text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  c_minutes     constant int := 30;
  c_open_max    constant int := 3;
  c_per_ip_hour constant int := 5;
  v_email text := lower(btrim(coalesce(p_email, '')));
  v_type  text;
  v_id    int;
  v_first text;
  v_count int;
BEGIN
  IF p_token_hash IS NULL OR p_token_hash !~ '^[0-9a-f]{64}$' THEN
    PERFORM fn_rule('The reset token must be a sha256 value in hex.');
  END IF;

  -- Same order as sign-in: students, teachers, then staff.
  SELECT 'student', s.student_id, s.first_name INTO v_type, v_id, v_first FROM student s WHERE s.email = v_email AND s.is_active;
  IF v_id IS NULL THEN
    SELECT 'teacher', t.teacher_id, t.first_name INTO v_type, v_id, v_first FROM teacher t WHERE t.email = v_email AND t.is_active;
  END IF;
  IF v_id IS NULL THEN
    SELECT 'staff', f.staff_id, f.first_name INTO v_type, v_id, v_first FROM staff f WHERE f.email = v_email AND f.is_active;
  END IF;
  IF v_id IS NULL THEN
    RETURN;
  END IF;

  PERFORM pg_advisory_xact_lock(hashtext('password-reset:' || v_type || ':' || v_id));
  SELECT count(*) INTO v_count
    FROM password_reset r
   WHERE r.used_at IS NULL AND r.expires_at > now()
     AND ((v_type = 'student' AND r.student_id = v_id) OR (v_type = 'teacher' AND r.teacher_id = v_id)
          OR (v_type = 'staff' AND r.staff_id = v_id));
  IF v_count >= c_open_max THEN
    RETURN;
  END IF;
  IF p_ip IS NOT NULL THEN
    SELECT count(*) INTO v_count FROM password_reset r WHERE r.ip = p_ip AND r.requested_at > now() - interval '1 hour';
    IF v_count >= c_per_ip_hour THEN
      RETURN;
    END IF;
  END IF;

  INSERT INTO password_reset (student_id, teacher_id, staff_id, token_hash, expires_at, ip)
  VALUES (CASE WHEN v_type = 'student' THEN v_id END, CASE WHEN v_type = 'teacher' THEN v_id END,
          CASE WHEN v_type = 'staff' THEN v_id END, p_token_hash, now() + make_interval(mins => c_minutes), p_ip);

  -- Whoever asked is not signed in, so the system is the actor; the account is the record.
  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES ('system', NULL, 'password_reset_requested', v_type, v_id::text, jsonb_build_object('ip', host(p_ip)));

  user_type := v_type;
  user_id := v_id;
  first_name := v_first;
  RETURN NEXT;
END $$;

-- The account behind an unused, unexpired link (and only while the account is active), or no row.
CREATE OR REPLACE FUNCTION fn_check_reset_token(p_token_hash text)
RETURNS TABLE (user_type text, user_id int, first_name text, email text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT o.kind, o.id, a.first_name, a.email
    FROM password_reset r
   CROSS JOIN LATERAL (SELECT CASE WHEN r.student_id IS NOT NULL THEN 'student'
                                   WHEN r.teacher_id IS NOT NULL THEN 'teacher' ELSE 'staff' END AS kind,
                              coalesce(r.student_id, r.teacher_id, r.staff_id) AS id) o
   CROSS JOIN LATERAL fn_auth_account(o.kind, o.id) a
   WHERE r.token_hash = p_token_hash AND r.used_at IS NULL AND r.expires_at > now() AND a.is_active
$$;

-- Use a link: store the new hash, mark this link and every other open link of the account used,
-- and return who it was so PHP can send the "Your password was changed" notice.
CREATE OR REPLACE FUNCTION fn_reset_password(p_token_hash text, p_new_hash text)
RETURNS TABLE (user_type text, user_id int, first_name text, email text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_reset  password_reset%ROWTYPE;
  v_type   text;
  v_id     int;
  v_first  text;
  v_email  text;
  v_active boolean;
BEGIN
  SELECT * INTO v_reset FROM password_reset r WHERE r.token_hash = p_token_hash FOR UPDATE;
  IF v_reset.reset_id IS NULL OR v_reset.used_at IS NOT NULL OR v_reset.expires_at <= now() THEN
    PERFORM fn_rule('This reset link has expired or was already used. Ask for a new one.');
  END IF;
  v_type := CASE WHEN v_reset.student_id IS NOT NULL THEN 'student'
                 WHEN v_reset.teacher_id IS NOT NULL THEN 'teacher' ELSE 'staff' END;
  v_id := coalesce(v_reset.student_id, v_reset.teacher_id, v_reset.staff_id);
  SELECT a.first_name, a.email, a.is_active INTO v_first, v_email, v_active FROM fn_auth_account(v_type, v_id) a;
  IF NOT coalesce(v_active, false) THEN
    PERFORM fn_rule('This reset link has expired or was already used. Ask for a new one.');
  END IF;

  PERFORM fn_auth_store_hash(v_type, v_id, p_new_hash, false);
  UPDATE password_reset r SET used_at = now()
   WHERE r.used_at IS NULL
     AND ((v_type = 'student' AND r.student_id = v_id) OR (v_type = 'teacher' AND r.teacher_id = v_id)
          OR (v_type = 'staff' AND r.staff_id = v_id));

  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES (v_type, v_id, 'password_reset', v_type, v_id::text, '{}'::jsonb);

  user_type := v_type;
  user_id := v_id;
  first_name := v_first;
  email := v_email;
  RETURN NEXT;
END $$;

-- ---------------------------------------------------------------------------
-- Sessions in the database (SESSION_DRIVER=db, used on Vercel). PHP keeps the idle timeout, the new id at
-- sign-in and CSRF; these only store the session text by id.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_session_read(p_id text) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT s.data FROM app_session s WHERE s.id = p_id
$$;

CREATE OR REPLACE FUNCTION fn_session_write(p_id text, p_data text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
BEGIN
  IF p_id IS NULL OR p_id !~ '^[A-Za-z0-9,-]+$' OR length(p_id) NOT BETWEEN 16 AND 256 THEN
    PERFORM fn_rule('That is not a session id.');
  END IF;
  INSERT INTO app_session AS s (id, data, updated_at) VALUES (p_id, coalesce(p_data, ''), now())
  ON CONFLICT (id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at;
END $$;

CREATE OR REPLACE FUNCTION fn_session_destroy(p_id text) RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  DELETE FROM app_session s WHERE s.id = p_id
$$;

-- Remove sessions untouched for p_max_seconds (at least a minute). Returns how many went.
CREATE OR REPLACE FUNCTION fn_session_gc(p_max_seconds int) RETURNS int
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_count int;
BEGIN
  DELETE FROM app_session s WHERE s.updated_at < now() - make_interval(secs => greatest(coalesce(p_max_seconds, 0), 60));
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END $$;

-- Nobody but the roles granted in 05_roles.sql may run these.
REVOKE ALL ON FUNCTION fn_auth_hash_ok(text), fn_auth_check_hash(text), fn_auth_account(text, int),
                       fn_auth_store_hash(text, int, text, boolean),
                       fn_login_lookup(text, inet, text), fn_record_login(text, inet, text, boolean, text, int),
                       fn_logout(text, int), fn_set_password(text, int, text, boolean),
                       fn_staff_reset_password(int, text, int, text),
                       fn_register_member(text, text, text, text, text, text, text, int, text, text, inet),
                       fn_request_password_reset(text, inet, text), fn_check_reset_token(text),
                       fn_reset_password(text, text),
                       fn_session_read(text), fn_session_write(text, text), fn_session_destroy(text), fn_session_gc(int)
  FROM PUBLIC;
