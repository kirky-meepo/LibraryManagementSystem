-- 04_audit.sql: the audit trail. Every insert, update and delete on the eight main tables writes one audit_log row.
-- PHP sets app.actor_type and app.actor_id at the start of each database transaction (db_tx in src/db.php).
-- Anything without an actor is logged as the system. password_hash values are never stored.
\set ON_ERROR_STOP on
SET search_path = lms, public;
SET client_min_messages = warning;

CREATE OR REPLACE FUNCTION fn_audit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_type    text := nullif(current_setting('app.actor_type', true), '');
  v_id_text text := nullif(current_setting('app.actor_id', true), '');
  v_id      int;
  v_hidden  text[] := ARRAY['password_hash'];
  v_old     jsonb;
  v_new     jsonb;
  v_detail  jsonb := '{}'::jsonb;
  v_key     text;
  v_record  text;
BEGIN
  IF v_type IS NULL OR v_type NOT IN ('staff', 'student', 'teacher', 'system') THEN
    v_type := 'system';
  END IF;
  IF v_type <> 'system' AND v_id_text ~ '^\d{1,9}$' THEN
    v_id := v_id_text::int;
  END IF;

  IF TG_OP = 'INSERT' THEN
    v_new := to_jsonb(NEW);
    v_record := v_new ->> TG_ARGV[0];
    v_detail := v_new - v_hidden;
  ELSIF TG_OP = 'DELETE' THEN
    v_old := to_jsonb(OLD);
    v_record := v_old ->> TG_ARGV[0];
    v_detail := v_old - v_hidden;
  ELSE
    v_old := to_jsonb(OLD);
    v_new := to_jsonb(NEW);
    v_record := v_new ->> TG_ARGV[0];
    -- Keep only the columns that changed, as {"column": {"old": ..., "new": ...}}.
    FOR v_key IN SELECT jsonb_object_keys(v_new) LOOP
      IF (v_old -> v_key) IS DISTINCT FROM (v_new -> v_key) THEN
        IF v_key = ANY (v_hidden) THEN
          v_detail := v_detail || jsonb_build_object('password_changed', true);
        ELSE
          v_detail := v_detail || jsonb_build_object(v_key, jsonb_build_object('old', v_old -> v_key, 'new', v_new -> v_key));
        END IF;
      END IF;
    END LOOP;
    IF v_detail = '{}'::jsonb THEN
      RETURN NULL;  -- nothing really changed
    END IF;
    -- The password routines in 04b_auth.sql write their own row (password_changed, password_reset, ...),
    -- so their password-only update is not logged twice.
    IF current_setting('lms.audit_quiet', true) = 'password'
       AND (v_detail - 'password_changed' - 'must_change_password') = '{}'::jsonb THEN
      RETURN NULL;
    END IF;
  END IF;

  INSERT INTO audit_log (actor_type, actor_id, action, table_name, record_id, detail)
  VALUES (v_type, v_id, lower(TG_OP), TG_TABLE_NAME, v_record, v_detail);
  RETURN NULL;
END $$;

DO $$
DECLARE
  v_pair text[];
BEGIN
  FOREACH v_pair SLICE 1 IN ARRAY ARRAY[
      ['staff', 'staff_id'], ['student', 'student_id'], ['teacher', 'teacher_id'], ['book', 'book_id'],
      ['book_request', 'request_id'], ['transaction', 'transaction_id'], ['fine', 'fine_id'],
      ['library_setting', 'setting_key']]
  LOOP
    EXECUTE format('DROP TRIGGER IF EXISTS trg_audit_%1$s ON lms.%1$I', v_pair[1]);
    EXECUTE format('CREATE TRIGGER trg_audit_%1$s AFTER INSERT OR UPDATE OR DELETE ON lms.%1$I
                    FOR EACH ROW EXECUTE FUNCTION lms.fn_audit(%2$L)', v_pair[1], v_pair[2]);
  END LOOP;
END $$;
