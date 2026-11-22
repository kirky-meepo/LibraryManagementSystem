-- demo_accounts.sql: the two shared demo accounts, and nothing else.
--   Demo Admin      demo.admin@example.com      admin      (everything: staff, settings, activity, deleting users)
--   Demo Librarian  demo.librarian@example.com  librarian  (books, users, requests, loans, fines, reports, messages)
-- example.com is reserved for examples, so no real person receives these accounts' emails.
--
-- Run it on a database that already has the office admin (any reset makes it); it is never run by a reset:
--   this PC:   & "C:\Program Files\PostgreSQL8\bin\psql.exe" -U postgres -h localhost -d lms -f database\demo_accounts.sql
--   online:    see docs/DEPLOY.md, "Demo accounts"
-- Running it again creates whichever account is missing and puts both passwords back to the shared ones.
-- Only bcrypt hashes are here, never the passwords. Give the passwords out privately.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET search_path = lms, public;

DO $$
DECLARE
  -- The office admin adds the accounts, as Manage Users would.
  v_office int := (SELECT s.staff_id FROM staff s WHERE s.position = 'admin' AND s.is_active ORDER BY s.staff_id LIMIT 1);
  r record;
  v_id int;
BEGIN
  IF v_office IS NULL THEN
    RAISE EXCEPTION 'No active admin yet: run databaseeset.ps1 (or reset.ps1 -Hosted) first.';
  END IF;
  FOR r IN SELECT * FROM (VALUES
      ('Demo', 'Admin',     'demo.admin@example.com',     'admin',     'REDACTED'),
      ('Demo', 'Librarian', 'demo.librarian@example.com', 'librarian', 'REDACTED')
    ) AS v (first_name, last_name, email, position, hash)
  LOOP
    PERFORM set_config('app.actor_type', 'staff', true), set_config('app.actor_id', v_office::text, true);
    SELECT s.staff_id INTO v_id FROM staff s WHERE s.email = r.email;
    IF v_id IS NULL THEN
      v_id := fn_create_staff(r.first_name, NULL, r.last_name, r.email, r.hash, r.position, NULL, v_office, current_date);
    END IF;
    -- The account sets its own (shared) password, so it signs in without a forced change.
    PERFORM set_config('app.actor_type', 'staff', true), set_config('app.actor_id', v_id::text, true);
    PERFORM fn_set_password('staff', v_id, r.hash, false);
  END LOOP;
END $$;

SELECT full_name AS "Demo account", email, detail AS role, CASE WHEN is_active THEN 'active' ELSE 'INACTIVE' END AS status
  FROM v_user_directory WHERE user_type = 'staff' AND email LIKE 'demo.%@example.com' ORDER BY detail;
