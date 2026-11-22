-- 01_schema.sql: the 14 tables of docs/SPEC.md part 1, with every rule the database can enforce on its own.
-- Safe to re-run: it drops and recreates the lms schema.
\set ON_ERROR_STOP on
SET client_min_messages = warning;

DROP SCHEMA IF EXISTS lms CASCADE;
CREATE SCHEMA lms;

-- Every connection to this database looks in lms first and works in Manila time.
-- Only on our own server: a hosted database (Supabase, Neon) is shared with the provider's own services, so there
-- the same settings are given to the lms_app role instead (05_roles.sql).
DO $$
BEGIN
  IF (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
    EXECUTE format('ALTER DATABASE %I SET search_path = lms, public', current_database());
    EXECUTE format('ALTER DATABASE %I SET timezone = %L', current_database(), 'Asia/Manila');
  END IF;
END $$;
SET search_path = lms, public;
SET TIME ZONE 'Asia/Manila';

-- ---------------------------------------------------------------------------
-- People
-- ---------------------------------------------------------------------------
CREATE TABLE staff (
  staff_id             int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_staff PRIMARY KEY,
  first_name           varchar(60)  NOT NULL,
  middle_name          varchar(60),
  last_name            varchar(60)  NOT NULL,
  email                varchar(254) NOT NULL,
  password_hash        text         NOT NULL,
  position             varchar(20)  NOT NULL,
  contact_number       varchar(20),
  date_hired           date,
  is_active            boolean      NOT NULL DEFAULT true,
  must_change_password boolean      NOT NULL DEFAULT false,
  CONSTRAINT uq_staff_email       UNIQUE (email),
  CONSTRAINT ck_staff_email_lower CHECK (email = lower(email) AND email LIKE '%_@_%'),
  CONSTRAINT ck_staff_position    CHECK (position IN ('librarian', 'admin')),
  CONSTRAINT ck_staff_names       CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '')
);

CREATE TABLE student (
  student_id           int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_student PRIMARY KEY,
  first_name           varchar(60)  NOT NULL,
  middle_name          varchar(60),
  last_name            varchar(60)  NOT NULL,
  course               varchar(80)  NOT NULL,
  year_level           smallint     NOT NULL,
  contact_number       varchar(20),
  email                varchar(254) NOT NULL,
  password_hash        text         NOT NULL,
  is_active            boolean      NOT NULL DEFAULT true,
  must_change_password boolean      NOT NULL DEFAULT false,
  date_registered      date         NOT NULL DEFAULT current_date,
  CONSTRAINT uq_student_email       UNIQUE (email),
  CONSTRAINT ck_student_email_lower CHECK (email = lower(email) AND email LIKE '%_@_%'),
  CONSTRAINT ck_student_year_level  CHECK (year_level BETWEEN 1 AND 6),
  CONSTRAINT ck_student_names       CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '' AND btrim(course) <> '')
);

CREATE TABLE teacher (
  teacher_id           int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_teacher PRIMARY KEY,
  first_name           varchar(60)  NOT NULL,
  middle_name          varchar(60),
  last_name            varchar(60)  NOT NULL,
  department           varchar(80)  NOT NULL,
  contact_number       varchar(20),
  email                varchar(254) NOT NULL,
  password_hash        text         NOT NULL,
  is_active            boolean      NOT NULL DEFAULT true,
  must_change_password boolean      NOT NULL DEFAULT false,
  date_registered      date         NOT NULL DEFAULT current_date,
  CONSTRAINT uq_teacher_email       UNIQUE (email),
  CONSTRAINT ck_teacher_email_lower CHECK (email = lower(email) AND email LIKE '%_@_%'),
  CONSTRAINT ck_teacher_names       CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '' AND btrim(department) <> '')
);

-- ---------------------------------------------------------------------------
-- Books
-- ---------------------------------------------------------------------------
CREATE TABLE book (
  book_id          int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_book PRIMARY KEY,
  isbn             varchar(17),
  title            varchar(200)  NOT NULL,
  author           varchar(150)  NOT NULL,
  category         varchar(60)   NOT NULL,
  copies_total     int           NOT NULL,
  copies_available int           NOT NULL,
  replacement_cost numeric(10,2) NOT NULL DEFAULT 0,
  added_by_id      int           NOT NULL CONSTRAINT fk_book_added_by REFERENCES staff (staff_id) ON DELETE RESTRICT,
  date_added       date          NOT NULL DEFAULT current_date,
  is_archived      boolean       NOT NULL DEFAULT false,
  -- Most titles may have up to 5 copies; rare titles get 1 or 2. Only an admin changes it (fn_set_copy_limit).
  copy_limit       int           NOT NULL DEFAULT 5,
  CONSTRAINT ck_book_copies_total     CHECK (copies_total >= 0),
  CONSTRAINT ck_book_copies           CHECK (copies_available BETWEEN 0 AND copies_total),
  CONSTRAINT ck_book_copy_limit       CHECK (copy_limit BETWEEN 1 AND 999),
  CONSTRAINT ck_book_within_limit     CHECK (copies_total <= copy_limit),
  CONSTRAINT ck_book_replacement_cost CHECK (replacement_cost >= 0),
  CONSTRAINT ck_book_isbn_format      CHECK (isbn IS NULL OR isbn ~ '^[0-9][0-9-]{8,15}[0-9X]$'),
  CONSTRAINT ck_book_text             CHECK (btrim(title) <> '' AND btrim(author) <> '' AND btrim(category) <> '')
);
-- One book per ISBN, whether or not it was typed with hyphens.
CREATE UNIQUE INDEX uq_book_isbn ON book ((replace(isbn, '-', ''))) WHERE isbn IS NOT NULL;
CREATE INDEX ix_book_title    ON book (lower(title));
CREATE INDEX ix_book_category ON book (category);
CREATE INDEX ix_book_added_by ON book (added_by_id);

-- ---------------------------------------------------------------------------
-- Requests, loans, fines
-- ---------------------------------------------------------------------------
CREATE TABLE book_request (
  request_id      int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_book_request PRIMARY KEY,
  student_id      int CONSTRAINT fk_request_student REFERENCES student (student_id) ON DELETE RESTRICT,
  teacher_id      int CONSTRAINT fk_request_teacher REFERENCES teacher (teacher_id) ON DELETE RESTRICT,
  book_id         int NOT NULL CONSTRAINT fk_request_book REFERENCES book (book_id) ON DELETE RESTRICT,
  date_requested  timestamptz NOT NULL DEFAULT now(),
  status          varchar(12) NOT NULL DEFAULT 'pending',
  decided_by_id   int CONSTRAINT fk_request_decided_by REFERENCES staff (staff_id) ON DELETE RESTRICT,
  date_decided    timestamptz,
  pickup_date     date,
  pickup_time     time,
  pickup_deadline date,
  decision_note   text,
  CONSTRAINT ck_request_one_borrower CHECK ((student_id IS NOT NULL)::int + (teacher_id IS NOT NULL)::int = 1),
  CONSTRAINT ck_request_status       CHECK (status IN ('pending', 'approved', 'rejected', 'fulfilled', 'expired', 'cancelled')),
  CONSTRAINT ck_request_pickup       CHECK (status <> 'approved' OR (pickup_date IS NOT NULL AND pickup_time IS NOT NULL AND pickup_deadline IS NOT NULL)),
  CONSTRAINT ck_request_deadline     CHECK (pickup_deadline IS NULL OR pickup_date IS NULL OR pickup_deadline >= pickup_date),
  CONSTRAINT ck_request_reason       CHECK (status <> 'rejected' OR coalesce(btrim(decision_note), '') <> '')
);
-- One open (pending or approved) request per borrower per book.
CREATE UNIQUE INDEX uq_request_open_student ON book_request (student_id, book_id)
  WHERE status IN ('pending', 'approved') AND student_id IS NOT NULL;
CREATE UNIQUE INDEX uq_request_open_teacher ON book_request (teacher_id, book_id)
  WHERE status IN ('pending', 'approved') AND teacher_id IS NOT NULL;
CREATE INDEX ix_request_status  ON book_request (status, date_requested);
CREATE INDEX ix_request_student ON book_request (student_id) WHERE student_id IS NOT NULL;
CREATE INDEX ix_request_teacher ON book_request (teacher_id) WHERE teacher_id IS NOT NULL;
CREATE INDEX ix_request_book    ON book_request (book_id);
CREATE INDEX ix_request_decided_by ON book_request (decided_by_id) WHERE decided_by_id IS NOT NULL;

CREATE TABLE transaction (
  transaction_id int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_transaction PRIMARY KEY,
  student_id     int CONSTRAINT fk_transaction_student REFERENCES student (student_id) ON DELETE RESTRICT,
  teacher_id     int CONSTRAINT fk_transaction_teacher REFERENCES teacher (teacher_id) ON DELETE RESTRICT,
  book_id        int NOT NULL CONSTRAINT fk_transaction_book  REFERENCES book (book_id)   ON DELETE RESTRICT,
  staff_id       int NOT NULL CONSTRAINT fk_transaction_staff REFERENCES staff (staff_id) ON DELETE RESTRICT,
  request_id     int CONSTRAINT fk_transaction_request REFERENCES book_request (request_id) ON DELETE RESTRICT,
  date_borrowed  date NOT NULL DEFAULT current_date,
  due_date       date NOT NULL,
  date_returned  date,
  status         varchar(10) NOT NULL DEFAULT 'borrowed',
  CONSTRAINT uq_transaction_request      UNIQUE (request_id),
  CONSTRAINT ck_transaction_one_borrower CHECK ((student_id IS NOT NULL)::int + (teacher_id IS NOT NULL)::int = 1),
  CONSTRAINT ck_transaction_status       CHECK (status IN ('borrowed', 'overdue', 'returned', 'lost')),
  CONSTRAINT ck_transaction_due          CHECK (due_date >= date_borrowed),
  CONSTRAINT ck_transaction_returned     CHECK ((status = 'returned') = (date_returned IS NOT NULL)),
  CONSTRAINT ck_transaction_return_date  CHECK (date_returned IS NULL OR date_returned >= date_borrowed)
);
CREATE INDEX ix_transaction_status  ON transaction (status);
CREATE INDEX ix_transaction_due     ON transaction (due_date) WHERE status IN ('borrowed', 'overdue');
CREATE INDEX ix_transaction_student ON transaction (student_id) WHERE student_id IS NOT NULL;
CREATE INDEX ix_transaction_teacher ON transaction (teacher_id) WHERE teacher_id IS NOT NULL;
CREATE INDEX ix_transaction_book    ON transaction (book_id);
CREATE INDEX ix_transaction_staff   ON transaction (staff_id);

CREATE TABLE fine (
  fine_id        int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_fine PRIMARY KEY,
  transaction_id int NOT NULL CONSTRAINT fk_fine_transaction REFERENCES transaction (transaction_id) ON DELETE RESTRICT,
  fine_type      varchar(10)   NOT NULL,
  amount         numeric(10,2) NOT NULL,
  date_issued    date          NOT NULL DEFAULT current_date,
  date_paid      date,
  status         varchar(10)   NOT NULL DEFAULT 'unpaid',
  waived_reason  text,
  CONSTRAINT ck_fine_type   CHECK (fine_type IN ('overdue', 'lost', 'damaged')),
  CONSTRAINT ck_fine_amount CHECK (amount >= 0),
  CONSTRAINT ck_fine_status CHECK (status IN ('unpaid', 'paid', 'waived')),
  CONSTRAINT ck_fine_paid   CHECK ((status = 'paid') = (date_paid IS NOT NULL)),
  CONSTRAINT ck_fine_waived CHECK (status <> 'waived' OR coalesce(btrim(waived_reason), '') <> '')
);
-- At most one overdue fine per loan.
CREATE UNIQUE INDEX uq_fine_overdue_once ON fine (transaction_id) WHERE fine_type = 'overdue';
CREATE INDEX ix_fine_transaction ON fine (transaction_id);
CREATE INDEX ix_fine_status      ON fine (status);

-- ---------------------------------------------------------------------------
-- Messages and presets
-- ---------------------------------------------------------------------------
CREATE TABLE message (
  message_id           int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_message PRIMARY KEY,
  sender_staff_id      int CONSTRAINT fk_message_sender  REFERENCES staff (staff_id)     ON DELETE RESTRICT,
  recipient_student_id int CONSTRAINT fk_message_student REFERENCES student (student_id) ON DELETE RESTRICT,
  recipient_teacher_id int CONSTRAINT fk_message_teacher REFERENCES teacher (teacher_id) ON DELETE RESTRICT,
  request_id           int CONSTRAINT fk_message_request REFERENCES book_request (request_id) ON DELETE RESTRICT,
  kind                 varchar(20)  NOT NULL,
  subject              varchar(150) NOT NULL,
  body                 text         NOT NULL,
  sent_at              timestamptz  NOT NULL DEFAULT now(),
  read_at              timestamptz,
  CONSTRAINT ck_message_one_recipient CHECK ((recipient_student_id IS NOT NULL)::int + (recipient_teacher_id IS NOT NULL)::int = 1),
  CONSTRAINT ck_message_kind          CHECK (kind IN ('request_approved', 'request_rejected', 'reminder', 'overdue', 'general')),
  CONSTRAINT ck_message_text          CHECK (btrim(subject) <> '' AND btrim(body) <> ''),
  CONSTRAINT ck_message_body_length   CHECK (char_length(body) <= 1000)
);
CREATE INDEX ix_message_student ON message (recipient_student_id, read_at) WHERE recipient_student_id IS NOT NULL;
CREATE INDEX ix_message_teacher ON message (recipient_teacher_id, read_at) WHERE recipient_teacher_id IS NOT NULL;
CREATE INDEX ix_message_request ON message (request_id) WHERE request_id IS NOT NULL;
CREATE INDEX ix_message_sent    ON message (sent_at DESC);
CREATE INDEX ix_message_sender  ON message (sender_staff_id) WHERE sender_staff_id IS NOT NULL;

CREATE TABLE message_template (
  template_id int GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_message_template PRIMARY KEY,
  kind        varchar(20)  NOT NULL,
  label       varchar(60)  NOT NULL,
  subject     varchar(150) NOT NULL,
  body        text         NOT NULL,
  is_active   boolean      NOT NULL DEFAULT true,
  sort_order  int          NOT NULL DEFAULT 0,
  CONSTRAINT ck_template_kind  CHECK (kind IN ('request_approved', 'request_rejected', 'reminder', 'overdue')),
  CONSTRAINT uq_template_label UNIQUE (kind, label)
);

-- ---------------------------------------------------------------------------
-- Settings, audit trail, sign-in attempts
-- ---------------------------------------------------------------------------
CREATE TABLE library_setting (
  setting_key   text CONSTRAINT pk_library_setting PRIMARY KEY,
  setting_value text NOT NULL,
  description   text
);

CREATE TABLE audit_log (
  audit_id   bigint GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_audit_log PRIMARY KEY,
  actor_type varchar(10) NOT NULL,
  actor_id   int,
  action     text        NOT NULL,
  table_name text        NOT NULL,
  record_id  text,
  detail     jsonb,
  created_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT ck_audit_actor_type CHECK (actor_type IN ('staff', 'student', 'teacher', 'system'))
);
CREATE INDEX ix_audit_created ON audit_log (created_at DESC);
CREATE INDEX ix_audit_table   ON audit_log (table_name, record_id);
CREATE INDEX ix_audit_actor   ON audit_log (actor_type, actor_id);

CREATE TABLE login_attempt (
  attempt_id   bigint GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_login_attempt PRIMARY KEY,
  email        varchar(254) NOT NULL,
  user_type    varchar(10)  NOT NULL,
  success      boolean      NOT NULL,
  ip           inet,
  attempted_at timestamptz  NOT NULL DEFAULT now(),
  CONSTRAINT ck_login_user_type CHECK (user_type IN ('member', 'staff'))
);
CREATE INDEX ix_login_attempt_email_ip ON login_attempt (email, ip, attempted_at);
CREATE INDEX ix_login_attempt_ip       ON login_attempt (ip, attempted_at);

-- Forgot-password links. Only the sha256 of the token is stored; the token itself is only in the email.
-- No audit trigger on purpose: it would copy token_hash into audit_log. The routines in 04b_auth.sql
-- write their own audit rows instead.
CREATE TABLE password_reset (
  reset_id     bigint GENERATED ALWAYS AS IDENTITY CONSTRAINT pk_password_reset PRIMARY KEY,
  student_id   int REFERENCES student (student_id) ON DELETE CASCADE,
  teacher_id   int REFERENCES teacher (teacher_id) ON DELETE CASCADE,
  staff_id     int REFERENCES staff (staff_id) ON DELETE CASCADE,
  token_hash   char(64)    NOT NULL,
  requested_at timestamptz NOT NULL DEFAULT now(),
  expires_at   timestamptz NOT NULL,
  used_at      timestamptz,
  ip           inet,
  CONSTRAINT uq_password_reset_token  UNIQUE (token_hash),
  CONSTRAINT ck_password_reset_owner  CHECK ((student_id IS NOT NULL)::int + (teacher_id IS NOT NULL)::int + (staff_id IS NOT NULL)::int = 1),
  CONSTRAINT ck_password_reset_expiry CHECK (expires_at > requested_at),
  CONSTRAINT ck_password_reset_hash   CHECK (token_hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX ix_password_reset_student ON password_reset (student_id);
CREATE INDEX ix_password_reset_teacher ON password_reset (teacher_id);
CREATE INDEX ix_password_reset_staff   ON password_reset (staff_id);
CREATE INDEX ix_password_reset_ip      ON password_reset (ip, requested_at);

-- PHP sessions when SESSION_DRIVER=db (a hosted site has no lasting disk). Only the fn_session_* routines in
-- 04b_auth.sql touch it; no audit trigger (it changes on every request).
CREATE TABLE app_session (
  id         varchar(256) CONSTRAINT pk_app_session PRIMARY KEY,
  data       text         NOT NULL DEFAULT '',
  updated_at timestamptz  NOT NULL DEFAULT now(),
  CONSTRAINT ck_app_session_id CHECK (id ~ '^[A-Za-z0-9,-]+$' AND length(id) >= 16)
);
CREATE INDEX ix_app_session_updated ON app_session (updated_at);

-- ---------------------------------------------------------------------------
-- Triggers that keep data clean
-- ---------------------------------------------------------------------------

-- Emails are stored trimmed and lower-case.
CREATE FUNCTION fn_normalize_email() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.email := lower(btrim(NEW.email));
  RETURN NEW;
END $$;

CREATE TRIGGER trg_staff_email   BEFORE INSERT OR UPDATE OF email ON staff   FOR EACH ROW EXECUTE FUNCTION fn_normalize_email();
CREATE TRIGGER trg_student_email BEFORE INSERT OR UPDATE OF email ON student FOR EACH ROW EXECUTE FUNCTION fn_normalize_email();
CREATE TRIGGER trg_teacher_email BEFORE INSERT OR UPDATE OF email ON teacher FOR EACH ROW EXECUTE FUNCTION fn_normalize_email();

-- Members sign in with one form, so a student email can never equal a teacher email.
CREATE FUNCTION fn_member_email_unique() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_email text := lower(btrim(NEW.email));
BEGIN
  -- Serialise two sign-ups with the same email arriving at the same moment.
  PERFORM pg_advisory_xact_lock(hashtext('member-email:' || v_email));
  IF TG_TABLE_NAME = 'student' THEN
    IF EXISTS (SELECT 1 FROM lms.teacher t WHERE lower(t.email) = v_email) THEN
      RAISE EXCEPTION USING ERRCODE = 'LM001',
        MESSAGE = format('The email %s already belongs to a teacher. Use a different email.', v_email);
    END IF;
  ELSE
    IF EXISTS (SELECT 1 FROM lms.student s WHERE lower(s.email) = v_email) THEN
      RAISE EXCEPTION USING ERRCODE = 'LM001',
        MESSAGE = format('The email %s already belongs to a student. Use a different email.', v_email);
    END IF;
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER trg_student_member_email BEFORE INSERT OR UPDATE OF email ON student
  FOR EACH ROW EXECUTE FUNCTION fn_member_email_unique();
CREATE TRIGGER trg_teacher_member_email BEFORE INSERT OR UPDATE OF email ON teacher
  FOR EACH ROW EXECUTE FUNCTION fn_member_email_unique();
