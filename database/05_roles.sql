-- 05_roles.sql: least-privilege database roles (docs/SPEC.md part 2, DATABASE ROLES; docs/HARDENING.md layer 3).
--   lms_owner   owns every object. NOLOGIN: only used through the superuser that runs these files.
--   lms_app     the PHP login. Reads what it shows (never a password hash) and writes only library_setting values.
--               Every change to people, books, requests, loans, fines and messages goes through the
--               SECURITY DEFINER routines. No DELETE anywhere, no DDL, not a superuser.
--   lms_report  SELECT on the views only.
-- Roles belong to the whole server, so they are created once and reused (lms and lms_test share them).
-- The reset script passes the lms_app password from .env as the psql variable app_pass.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET search_path = lms, public;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_owner') THEN CREATE ROLE lms_owner NOLOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_app') THEN CREATE ROLE lms_app LOGIN; END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lms_report') THEN CREATE ROLE lms_report NOLOGIN; END IF;
END $$;

-- A hosted PostgreSQL (Neon, Supabase, ...) gives an admin role with CREATEROLE but not a true superuser.
-- Superuser-only steps are guarded: SUPERUSER, REPLICATION and BYPASSRLS can only be changed by a superuser
-- (a role made by a non-superuser never has them anyway), and log_statement can only be set by one.
SELECT rolsuper AS is_super FROM pg_roles WHERE rolname = current_user \gset
DO $$
DECLARE v_super boolean := (SELECT rolsuper FROM pg_roles WHERE rolname = current_user);
BEGIN
  IF v_super THEN
    ALTER ROLE lms_owner  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    ALTER ROLE lms_app    LOGIN   NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT CONNECTION LIMIT 50;
    ALTER ROLE lms_report NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
  ELSE
    ALTER ROLE lms_owner  NOLOGIN NOCREATEDB NOCREATEROLE;
    ALTER ROLE lms_app    LOGIN   NOCREATEDB NOCREATEROLE NOINHERIT CONNECTION LIMIT 50;
    ALTER ROLE lms_report NOLOGIN NOCREATEDB NOCREATEROLE;
    -- The admin must be able to act as lms_owner to hand it the objects below (PostgreSQL 16+ only gives the
    -- creator of a role the ADMIN option, so membership with INHERIT and SET is granted explicitly).
    IF current_setting('server_version_num')::int >= 160000 THEN
      EXECUTE format('GRANT lms_owner TO %I WITH INHERIT TRUE, SET TRUE', current_user);
    ELSE
      EXECUTE format('GRANT lms_owner TO %I', current_user);
    END IF;
  END IF;
END $$;

-- The site's connections look in lms first and work in Manila time even when the database settings in
-- 01_schema.sql could not be made (hosted) or a connection pooler resets session settings.
ALTER ROLE lms_app SET search_path = lms, public;
ALTER ROLE lms_app SET timezone = 'Asia/Manila';

-- Set the app password without writing it to the server log (log_statement = 'ddl' would otherwise record it).
-- Only a superuser may switch statement logging off; a hosted admin cannot, and hosted logs are the provider's.
\if :{?app_pass}
SELECT length(:'app_pass') >= 12 AS app_pass_ok \gset
\if :app_pass_ok
\if :is_super
SET log_statement = 'none';
\endif
ALTER ROLE lms_app PASSWORD :'app_pass';
\if :is_super
RESET log_statement;
\endif
\else
\echo 'APP_DB_PASS in .env is missing or shorter than 12 characters, so lms_app keeps its old password.'
\endif
\endif

-- ---------------------------------------------------------------------------
-- Ownership: everything in this database belongs to lms_owner.
-- ---------------------------------------------------------------------------
DO $$
DECLARE r record;
BEGIN
  -- On a hosted database the provider's admin keeps owning the database itself; the schema and everything in it
  -- still belong to lms_owner.
  IF (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
    EXECUTE format('ALTER DATABASE %I OWNER TO lms_owner', current_database());
  END IF;
  ALTER SCHEMA lms OWNER TO lms_owner;
  FOR r IN
    SELECT c.relname, c.relkind
      FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
     WHERE n.nspname = 'lms' AND c.relkind IN ('r', 'p', 'v', 'm')
  LOOP
    EXECUTE format('ALTER %s lms.%I OWNER TO lms_owner',
                   CASE r.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' ELSE 'TABLE' END, r.relname);
  END LOOP;
  FOR r IN
    SELECT p.oid::regprocedure AS sig
      FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = 'lms'
  LOOP
    EXECUTE format('ALTER ROUTINE %s OWNER TO lms_owner', r.sig);
  END LOOP;
END $$;

-- ---------------------------------------------------------------------------
-- Nothing for PUBLIC (every role on the server).
-- ---------------------------------------------------------------------------
DO $$
BEGIN
  -- Only on our own server. A hosted database (Supabase, Neon) is shared with the provider's own services, which
  -- connect to it and use the public schema; there only the lms schema is closed (below).
  IF (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database());
    REVOKE ALL ON SCHEMA public FROM PUBLIC;
  END IF;
  EXECUTE format('GRANT CONNECT ON DATABASE %I TO lms_app, lms_report', current_database());
END $$;
REVOKE ALL ON SCHEMA lms FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA lms FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA lms FROM PUBLIC;
REVOKE ALL ON ALL ROUTINES IN SCHEMA lms FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lms_owner IN SCHEMA lms REVOKE EXECUTE ON ROUTINES FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE lms_owner IN SCHEMA lms REVOKE ALL ON TABLES FROM PUBLIC;

-- Start each role from zero so re-running this file never leaves old grants behind.
REVOKE ALL ON ALL TABLES IN SCHEMA lms FROM lms_app, lms_report;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA lms FROM lms_app, lms_report;
REVOKE ALL ON ALL ROUTINES IN SCHEMA lms FROM lms_app, lms_report;

GRANT USAGE ON SCHEMA lms TO lms_app, lms_report;

-- ---------------------------------------------------------------------------
-- lms_app (the PHP site)
-- ---------------------------------------------------------------------------
GRANT SELECT ON ALL TABLES IN SCHEMA lms TO lms_app;   -- tables and views it reads

-- People: the site never sees a password hash. SELECT is column by column without password_hash;
-- signing in reads the hash only through fn_login_lookup (04b_auth.sql).
REVOKE SELECT ON staff, student, teacher FROM lms_app;
GRANT SELECT (staff_id, first_name, middle_name, last_name, email, position, contact_number, date_hired, is_active,
              must_change_password) ON staff TO lms_app;
GRANT SELECT (student_id, first_name, middle_name, last_name, course, year_level, contact_number, email, is_active,
              must_change_password, date_registered) ON student TO lms_app;
GRANT SELECT (teacher_id, first_name, middle_name, last_name, department, contact_number, email, is_active,
              must_change_password, date_registered) ON teacher TO lms_app;

-- No INSERT or UPDATE on staff, student, teacher or book at all (Step 17). People are added and edited through
-- fn_create_* / fn_update_* and fn_update_my_profile (04c_crud.sql), passwords through 04b_auth.sql,
-- books through fn_create_book and fn_update_book_details; copies, the copy limit and archiving through
-- fn_set_copies_total, fn_set_copy_limit and fn_set_book_archived; is_active through fn_set_user_active,
-- delete through fn_delete_user.

GRANT UPDATE (setting_value) ON library_setting TO lms_app;

-- Sign-in attempts and reset links are written only by the routines in 04b_auth.sql.
-- The app cannot even read password_reset (it holds token hashes) or app_session (the fn_session_* routines).
REVOKE ALL ON password_reset, app_session FROM lms_app;

GRANT EXECUTE ON ALL ROUTINES IN SCHEMA lms TO lms_app;
-- Internal helpers of 04b_auth.sql: storing a hash for any account is only allowed through the routines
-- that check who may do it (fn_set_password, fn_staff_reset_password, fn_reset_password).
REVOKE EXECUTE ON FUNCTION fn_auth_store_hash(text, int, text, boolean), fn_auth_account(text, int) FROM lms_app;

-- ---------------------------------------------------------------------------
-- lms_report (read-only reporting, for example from pgAdmin or a spreadsheet)
-- ---------------------------------------------------------------------------
GRANT SELECT ON v_dashboard_stats, v_monthly_activity, v_popular_books, v_category_breakdown,
                v_request_detail, v_active_loans, v_loan_detail, v_fine_detail, v_inbox, v_user_directory, v_book_detail,
                v_borrower_status
  TO lms_report;
-- Views call these helpers with the caller's rights, so the report role may run them (they only read settings).
GRANT EXECUTE ON FUNCTION fn_setting_int(text, int), fn_setting_num(text, numeric), fn_report_from() TO lms_report;
