-- 04c_crud.sql: Create and Read for each group member's table (docs/MEMBER_TASKS.md).
--   Book         Rochie Ann B. Surban   fn_create_book, fn_list_books, fn_get_book (+ fn_list_categories, fn_update_book_details)
--   Student      Beverly Kate Abalde    fn_create_student, fn_list_students, fn_get_student, and the same for teachers
--   Staff        Khem Cafino            fn_create_staff, fn_list_staff, fn_get_staff (+ fn_update_staff, fn_update_my_profile)
--   Transaction  Kirk Pugnaria          create = fn_issue_book / fn_issue_book_direct (02_routines.sql);
--                                       fn_list_transactions, fn_get_transaction
--   Fine         Danriel Jaramel        fn_create_fine, fn_list_fines, fn_get_fine
-- All are SECURITY DEFINER with search_path = lms, pg_temp, and PUBLIC cannot run them (05_roles.sql revokes it).
-- They return plain tables and never password_hash. Every list takes optional filters plus p_limit and p_offset
-- (default 25 and 0) and returns total_count (count(*) OVER ()) on every row, so the pagers keep working.
-- Errors use the existing codes: LM001 a rule, LM002 not found, LM004 not allowed.
\set ON_ERROR_STOP on
SET search_path = lms, public;
SET client_min_messages = warning;

-- ===========================================================================
-- Shared helpers
-- ===========================================================================

-- "contains" pattern for ILIKE, with %, _ and \ taken literally (the same as like_contains() in PHP).
CREATE OR REPLACE FUNCTION fn_like(p_text text) RETURNS text
LANGUAGE sql IMMUTABLE SET search_path = lms, pg_temp AS $$
  SELECT '%' || replace(replace(replace(coalesce(p_text, ''), '\', '\\'), '%', '\%'), '_', '\_') || '%'
$$;

CREATE OR REPLACE FUNCTION fn_page_limit(p_limit int) RETURNS int
LANGUAGE sql IMMUTABLE AS $$ SELECT least(greatest(coalesce(p_limit, 25), 1), 500) $$;

CREATE OR REPLACE FUNCTION fn_page_offset(p_offset int) RETURNS int
LANGUAGE sql IMMUTABLE AS $$ SELECT greatest(coalesce(p_offset, 0), 0) $$;

-- Names, email and contact number, with the same sentences the forms use.
CREATE OR REPLACE FUNCTION fn_check_person(p_first text, p_middle text, p_last text, p_email text, p_contact text)
RETURNS void LANGUAGE plpgsql IMMUTABLE SET search_path = lms, pg_temp AS $$
BEGIN
  IF coalesce(btrim(p_first), '') = '' THEN PERFORM fn_rule('Type the first name.'); END IF;
  IF coalesce(btrim(p_last), '') = '' THEN PERFORM fn_rule('Type the last name.'); END IF;
  IF char_length(btrim(p_first)) > 60 OR char_length(btrim(p_last)) > 60 OR char_length(btrim(coalesce(p_middle, ''))) > 60 THEN
    PERFORM fn_rule('Names can be at most 60 characters.');
  END IF;
  IF p_email IS NOT NULL AND (char_length(btrim(p_email)) > 254 OR btrim(p_email) !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$') THEN
    PERFORM fn_rule('Type a full email address, for example juan.cruz@school.edu.');
  END IF;
  IF nullif(btrim(coalesce(p_contact, '')), '') IS NOT NULL AND btrim(p_contact) !~ '^[0-9+()\-\s]{7,20}$' THEN
    PERFORM fn_rule('Use digits, spaces, + ( ) or -, up to 20 characters, for example 0917 555 0142.');
  END IF;
END $$;

-- ===========================================================================
-- BOOK (Rochie Ann B. Surban)
-- ===========================================================================

-- ISBN-10 or ISBN-13 (hyphens and spaces allowed) as ISBN-13 written 978-XXXXXXXXXX, or NULL when the check digit
-- is wrong. The same rule as isbn_normalize() in src/books.php, so a title can never be added twice under two forms.
CREATE OR REPLACE FUNCTION fn_isbn_normalize(p_raw text) RETURNS text
LANGUAGE plpgsql IMMUTABLE SET search_path = lms, pg_temp AS $$
DECLARE
  d text := upper(regexp_replace(coalesce(p_raw, ''), '[\s-]+', '', 'g'));
  s int := 0;
BEGIN
  IF d ~ '^\d{9}[\dX]$' THEN
    FOR i IN 1..10 LOOP
      s := s + (CASE WHEN substr(d, i, 1) = 'X' THEN 10 ELSE substr(d, i, 1)::int END) * (11 - i);
    END LOOP;
    IF s % 11 <> 0 THEN RETURN NULL; END IF;
    d := '978' || left(d, 9);
    s := 0;
    FOR i IN 1..12 LOOP
      s := s + substr(d, i, 1)::int * (CASE WHEN i % 2 = 0 THEN 3 ELSE 1 END);
    END LOOP;
    d := d || ((10 - s % 10) % 10)::text;
  END IF;
  IF d !~ '^97[89]\d{10}$' THEN RETURN NULL; END IF;
  s := 0;
  FOR i IN 1..13 LOOP
    s := s + substr(d, i, 1)::int * (CASE WHEN i % 2 = 0 THEN 3 ELSE 1 END);
  END LOOP;
  IF s % 10 <> 0 THEN RETURN NULL; END IF;
  RETURN left(d, 3) || '-' || substr(d, 4);
END $$;

-- Category tidy-up: trimmed, inner spaces collapsed, and the spelling of an existing category when only the
-- letter case differs ("software engineering" joins "Software Engineering"). The book being edited does not count,
-- so fixing the letter case of a category only that book uses still works.
CREATE OR REPLACE FUNCTION fn_tidy_category(p_category text, p_except_book_id int DEFAULT NULL) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT coalesce((SELECT b.category::text FROM book b
                    WHERE lower(b.category) = lower(x.c) AND b.book_id IS DISTINCT FROM p_except_book_id
                    ORDER BY b.book_id LIMIT 1), x.c)
    FROM (SELECT regexp_replace(btrim(coalesce(p_category, '')), '\s+', ' ', 'g') AS c) x
$$;

-- Title, author, category and cost rules shared by create and edit. Returns the ISBN in its stored form.
CREATE OR REPLACE FUNCTION fn_check_book(p_isbn text, p_title text, p_author text, p_category text, p_cost numeric, p_except_id int)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_isbn text; v_dupe text;
BEGIN
  IF nullif(btrim(coalesce(p_isbn, '')), '') IS NOT NULL THEN
    v_isbn := fn_isbn_normalize(p_isbn);
    IF v_isbn IS NULL THEN
      PERFORM fn_rule('This is not a valid ISBN. Type the 10 or 13 digits from the back cover; hyphens are fine.');
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('isbn:' || v_isbn));
    SELECT b.title INTO v_dupe FROM book b
     WHERE replace(b.isbn, '-', '') = replace(v_isbn, '-', '') AND b.book_id IS DISTINCT FROM p_except_id;
    IF v_dupe IS NOT NULL THEN
      PERFORM fn_rule(format('A book with this ISBN already exists: %s. Edit that book instead of adding it again.', v_dupe));
    END IF;
  END IF;
  IF coalesce(btrim(p_title), '') = '' THEN PERFORM fn_rule('Type the title.'); END IF;
  IF char_length(btrim(p_title)) > 200 THEN PERFORM fn_rule('The title can be at most 200 characters.'); END IF;
  IF coalesce(btrim(p_author), '') = '' THEN PERFORM fn_rule('Type the author.'); END IF;
  IF char_length(btrim(p_author)) > 150 THEN PERFORM fn_rule('The author can be at most 150 characters.'); END IF;
  IF coalesce(btrim(p_category), '') = '' THEN PERFORM fn_rule('Pick a category from the list or type a new one.'); END IF;
  IF char_length(btrim(p_category)) > 60 THEN PERFORM fn_rule('The category can be at most 60 characters.'); END IF;
  IF p_cost IS NULL OR p_cost < 0 OR p_cost > 99999999.99 THEN
    PERFORM fn_rule('Replacement cost must be 0 or more pesos, for example 2450 or 2450.50.');
  END IF;
  RETURN v_isbn;
END $$;

-- Add New Book. Every copy goes on the shelf (trg_book_new_copies). A new title may have up to 5 copies
-- (the copy_limit default); only an admin raises a title's limit afterwards (fn_set_copy_limit).
CREATE OR REPLACE FUNCTION fn_create_book(p_isbn text, p_title text, p_author text, p_category text, p_copies_total int,
                                          p_replacement_cost numeric, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  c_default_limit constant int := 5;   -- the DEFAULT of book.copy_limit in 01_schema.sql
  v_category text := fn_tidy_category(p_category);
  v_isbn text;
  v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  v_isbn := fn_check_book(p_isbn, p_title, p_author, v_category, p_replacement_cost, NULL);
  IF p_copies_total IS NULL OR p_copies_total < 1 OR p_copies_total > c_default_limit THEN
    PERFORM fn_rule(format('Copies must be a whole number from 1 to %s. An admin can raise the limit for a title.', c_default_limit));
  END IF;
  INSERT INTO book (isbn, title, author, category, copies_total, copies_available, replacement_cost, added_by_id)
  VALUES (v_isbn, btrim(p_title), btrim(p_author), v_category, p_copies_total, p_copies_total, round(p_replacement_cost, 2), p_staff_id)
  RETURNING book_id INTO v_id;
  RETURN v_id;
END $$;

-- Edit book: catalogue details only. Copies go through fn_set_copies_total, the limit through fn_set_copy_limit.
CREATE OR REPLACE FUNCTION fn_update_book_details(p_book_id int, p_isbn text, p_title text, p_author text, p_category text,
                                                  p_replacement_cost numeric, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_category text := fn_tidy_category(p_category, p_book_id); v_isbn text;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM 1 FROM book b WHERE b.book_id = p_book_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That book no longer exists. Reload the page.', 'LM002'); END IF;
  v_isbn := fn_check_book(p_isbn, p_title, p_author, v_category, p_replacement_cost, p_book_id);
  UPDATE book b
     SET isbn = v_isbn, title = btrim(p_title), author = btrim(p_author), category = v_category,
         replacement_cost = round(p_replacement_cost, 2)
   WHERE b.book_id = p_book_id;
END $$;

-- Manage Books and the member Catalog. Archived titles only when asked; "only available" means a copy on the shelf.
CREATE OR REPLACE FUNCTION fn_list_books(p_search text DEFAULT NULL, p_category text DEFAULT NULL,
                                         p_only_available boolean DEFAULT false, p_include_archived boolean DEFAULT false,
                                         p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (book_id int, isbn text, title text, author text, category text, copies_total int, copies_available int,
               replacement_cost numeric, added_by_id int, date_added date, is_archived boolean, added_by_name text,
               on_loan int, total_loans int, reserved int, pending_requests int, total_requests int, availability text,
               copy_limit int, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.book_id, v.isbn::text, v.title::text, v.author::text, v.category::text, v.copies_total, v.copies_available,
         v.replacement_cost::numeric, v.added_by_id, v.date_added, v.is_archived, v.added_by_name::text,
         v.on_loan, v.total_loans, v.reserved, v.pending_requests, v.total_requests, v.availability::text, v.copy_limit,
         count(*) OVER ()
    FROM v_book_detail v
   WHERE (coalesce(p_include_archived, false) OR NOT v.is_archived)
     AND (NOT coalesce(p_only_available, false) OR v.copies_available > 0)
     AND (coalesce(p_category, '') = '' OR v.category = p_category)
     AND (coalesce(btrim(p_search), '') = ''
          OR v.title ILIKE fn_like(btrim(p_search)) OR v.author ILIKE fn_like(btrim(p_search))
          OR replace(coalesce(v.isbn, ''), '-', '') ILIKE fn_like(replace(btrim(p_search), '-', '')))
   ORDER BY v.is_archived, v.title, v.book_id
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

-- One book with its counts, or no row.
CREATE OR REPLACE FUNCTION fn_get_book(p_book_id int)
RETURNS TABLE (book_id int, isbn text, title text, author text, category text, copies_total int, copies_available int,
               replacement_cost numeric, added_by_id int, date_added date, is_archived boolean, added_by_name text,
               on_loan int, total_loans int, reserved int, pending_requests int, total_requests int, availability text,
               copy_limit int)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.book_id, v.isbn::text, v.title::text, v.author::text, v.category::text, v.copies_total, v.copies_available,
         v.replacement_cost::numeric, v.added_by_id, v.date_added, v.is_archived, v.added_by_name::text,
         v.on_loan, v.total_loans, v.reserved, v.pending_requests, v.total_requests, v.availability::text, v.copy_limit
    FROM v_book_detail v WHERE v.book_id = p_book_id
$$;

-- Categories in use, for the filters and the Add New Book suggestions (members never see archived-only ones).
CREATE OR REPLACE FUNCTION fn_list_categories(p_include_archived boolean DEFAULT true)
RETURNS TABLE (category text, titles bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT b.category::text, count(*) FROM book b
   WHERE coalesce(p_include_archived, true) OR NOT b.is_archived
   GROUP BY b.category ORDER BY b.category
$$;

-- ===========================================================================
-- STUDENT and TEACHER (Beverly Kate Abalde)
-- ===========================================================================

-- Manage Users, Add user: a new student with a temporary password they must change at the first sign-in.
CREATE OR REPLACE FUNCTION fn_create_student(p_first_name text, p_middle_name text, p_last_name text, p_course text,
                                             p_year_level int, p_contact_number text, p_email text, p_password_hash text,
                                             p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, ''))); v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF coalesce(btrim(p_course), '') = '' THEN PERFORM fn_rule('Pick a course from the list or type a new one.'); END IF;
  IF char_length(btrim(p_course)) > 80 THEN PERFORM fn_rule('The course can be at most 80 characters.'); END IF;
  IF p_year_level IS NULL OR p_year_level NOT BETWEEN 1 AND 6 THEN PERFORM fn_rule('Choose a year level from 1 to 6.'); END IF;
  PERFORM fn_auth_check_hash(p_password_hash);
  IF EXISTS (SELECT 1 FROM student s WHERE s.email = v_email) THEN
    PERFORM fn_rule('Another student already uses this email.');
  END IF;
  INSERT INTO student (first_name, middle_name, last_name, course, year_level, contact_number, email, password_hash, must_change_password)
  VALUES (btrim(p_first_name), nullif(btrim(coalesce(p_middle_name, '')), ''), btrim(p_last_name), btrim(p_course), p_year_level,
          nullif(btrim(coalesce(p_contact_number, '')), ''), v_email, p_password_hash, true)
  RETURNING student_id INTO v_id;
  RETURN v_id;
END $$;

CREATE OR REPLACE FUNCTION fn_update_student(p_student_id int, p_first_name text, p_middle_name text, p_last_name text,
                                             p_course text, p_year_level int, p_contact_number text, p_email text, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, '')));
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM 1 FROM student s WHERE s.student_id = p_student_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That account no longer exists. Reload the page.', 'LM002'); END IF;
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF coalesce(btrim(p_course), '') = '' THEN PERFORM fn_rule('Pick a course from the list or type a new one.'); END IF;
  IF char_length(btrim(p_course)) > 80 THEN PERFORM fn_rule('The course can be at most 80 characters.'); END IF;
  IF p_year_level IS NULL OR p_year_level NOT BETWEEN 1 AND 6 THEN PERFORM fn_rule('Choose a year level from 1 to 6.'); END IF;
  IF EXISTS (SELECT 1 FROM student s WHERE s.email = v_email AND s.student_id <> p_student_id) THEN
    PERFORM fn_rule('Another student already uses this email.');
  END IF;
  UPDATE student s
     SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''), last_name = btrim(p_last_name),
         course = btrim(p_course), year_level = p_year_level, contact_number = nullif(btrim(coalesce(p_contact_number, '')), ''),
         email = v_email
   WHERE s.student_id = p_student_id;
END $$;

-- Manage Users, Students: the same columns as v_user_directory, plus total_count. p_active NULL means everyone.
CREATE OR REPLACE FUNCTION fn_list_students(p_search text DEFAULT NULL, p_active boolean DEFAULT NULL,
                                            p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (user_type text, user_id int, full_name text, email text, contact_number text, detail text, is_active boolean,
               joined_on date, first_name text, middle_name text, last_name text, course text, year_level smallint,
               department text, "position" text, must_change_password boolean, has_history boolean, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT u.user_type, u.user_id, u.full_name::text, u.email::text, u.contact_number::text, u.detail::text, u.is_active,
         u.joined_on, u.first_name::text, u.middle_name::text, u.last_name::text, u.course::text, u.year_level,
         u.department::text, u.position::text, u.must_change_password, u.has_history, count(*) OVER ()
    FROM v_user_directory u
   WHERE u.user_type = 'student'
     AND (p_active IS NULL OR u.is_active = p_active)
     AND (coalesce(btrim(p_search), '') = '' OR u.full_name ILIKE fn_like(btrim(p_search)) OR u.email ILIKE fn_like(btrim(p_search))
          OR concat_ws(' ', u.first_name, u.middle_name, u.last_name) ILIKE fn_like(btrim(p_search)))
   ORDER BY u.is_active DESC, u.last_name, u.first_name, u.user_id
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

CREATE OR REPLACE FUNCTION fn_get_student(p_student_id int)
RETURNS TABLE (student_id int, first_name text, middle_name text, last_name text, course text, year_level smallint,
               contact_number text, email text, is_active boolean, must_change_password boolean, date_registered date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT s.student_id, s.first_name::text, s.middle_name::text, s.last_name::text, s.course::text, s.year_level,
         s.contact_number::text, s.email::text, s.is_active, s.must_change_password, s.date_registered
    FROM student s WHERE s.student_id = p_student_id
$$;

CREATE OR REPLACE FUNCTION fn_list_courses()
RETURNS TABLE (course text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT DISTINCT s.course::text FROM student s ORDER BY 1
$$;

-- Teachers: the same trio.
CREATE OR REPLACE FUNCTION fn_create_teacher(p_first_name text, p_middle_name text, p_last_name text, p_department text,
                                             p_contact_number text, p_email text, p_password_hash text, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, ''))); v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF coalesce(btrim(p_department), '') = '' THEN PERFORM fn_rule('Pick a department from the list or type a new one.'); END IF;
  IF char_length(btrim(p_department)) > 80 THEN PERFORM fn_rule('The department can be at most 80 characters.'); END IF;
  PERFORM fn_auth_check_hash(p_password_hash);
  IF EXISTS (SELECT 1 FROM teacher t WHERE t.email = v_email) THEN
    PERFORM fn_rule('Another teacher already uses this email.');
  END IF;
  INSERT INTO teacher (first_name, middle_name, last_name, department, contact_number, email, password_hash, must_change_password)
  VALUES (btrim(p_first_name), nullif(btrim(coalesce(p_middle_name, '')), ''), btrim(p_last_name), btrim(p_department),
          nullif(btrim(coalesce(p_contact_number, '')), ''), v_email, p_password_hash, true)
  RETURNING teacher_id INTO v_id;
  RETURN v_id;
END $$;

CREATE OR REPLACE FUNCTION fn_update_teacher(p_teacher_id int, p_first_name text, p_middle_name text, p_last_name text,
                                             p_department text, p_contact_number text, p_email text, p_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, '')));
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  PERFORM 1 FROM teacher t WHERE t.teacher_id = p_teacher_id FOR UPDATE;
  IF NOT FOUND THEN PERFORM fn_rule('That account no longer exists. Reload the page.', 'LM002'); END IF;
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF coalesce(btrim(p_department), '') = '' THEN PERFORM fn_rule('Pick a department from the list or type a new one.'); END IF;
  IF char_length(btrim(p_department)) > 80 THEN PERFORM fn_rule('The department can be at most 80 characters.'); END IF;
  IF EXISTS (SELECT 1 FROM teacher t WHERE t.email = v_email AND t.teacher_id <> p_teacher_id) THEN
    PERFORM fn_rule('Another teacher already uses this email.');
  END IF;
  UPDATE teacher t
     SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''), last_name = btrim(p_last_name),
         department = btrim(p_department), contact_number = nullif(btrim(coalesce(p_contact_number, '')), ''), email = v_email
   WHERE t.teacher_id = p_teacher_id;
END $$;

CREATE OR REPLACE FUNCTION fn_list_teachers(p_search text DEFAULT NULL, p_active boolean DEFAULT NULL,
                                            p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (user_type text, user_id int, full_name text, email text, contact_number text, detail text, is_active boolean,
               joined_on date, first_name text, middle_name text, last_name text, course text, year_level smallint,
               department text, "position" text, must_change_password boolean, has_history boolean, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT u.user_type, u.user_id, u.full_name::text, u.email::text, u.contact_number::text, u.detail::text, u.is_active,
         u.joined_on, u.first_name::text, u.middle_name::text, u.last_name::text, u.course::text, u.year_level,
         u.department::text, u.position::text, u.must_change_password, u.has_history, count(*) OVER ()
    FROM v_user_directory u
   WHERE u.user_type = 'teacher'
     AND (p_active IS NULL OR u.is_active = p_active)
     AND (coalesce(btrim(p_search), '') = '' OR u.full_name ILIKE fn_like(btrim(p_search)) OR u.email ILIKE fn_like(btrim(p_search))
          OR concat_ws(' ', u.first_name, u.middle_name, u.last_name) ILIKE fn_like(btrim(p_search)))
   ORDER BY u.is_active DESC, u.last_name, u.first_name, u.user_id
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

CREATE OR REPLACE FUNCTION fn_get_teacher(p_teacher_id int)
RETURNS TABLE (teacher_id int, first_name text, middle_name text, last_name text, department text, contact_number text,
               email text, is_active boolean, must_change_password boolean, date_registered date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT t.teacher_id, t.first_name::text, t.middle_name::text, t.last_name::text, t.department::text,
         t.contact_number::text, t.email::text, t.is_active, t.must_change_password, t.date_registered
    FROM teacher t WHERE t.teacher_id = p_teacher_id
$$;

CREATE OR REPLACE FUNCTION fn_list_departments()
RETURNS TABLE (department text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT DISTINCT t.department::text FROM teacher t ORDER BY 1
$$;

-- ===========================================================================
-- STAFF (Khem Cafino)
-- ===========================================================================

-- Manage Users, Add staff: admin only. Temporary password, changed at the first sign-in.
CREATE OR REPLACE FUNCTION fn_create_staff(p_first_name text, p_middle_name text, p_last_name text, p_email text,
                                           p_password_hash text, p_position text, p_contact_number text,
                                           p_acting_staff_id int, p_date_hired date DEFAULT NULL)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, ''))); v_id int;
BEGIN
  PERFORM fn_check_staff(p_acting_staff_id, true);
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF p_position IS NULL OR p_position NOT IN ('librarian', 'admin') THEN PERFORM fn_rule('Choose Librarian or Admin.'); END IF;
  IF p_date_hired > current_date THEN PERFORM fn_rule('Choose a date that is not in the future.'); END IF;
  PERFORM fn_auth_check_hash(p_password_hash);
  IF EXISTS (SELECT 1 FROM staff s WHERE s.email = v_email) THEN
    PERFORM fn_rule('Another staff member already uses this email.');
  END IF;
  INSERT INTO staff (first_name, middle_name, last_name, email, password_hash, position, contact_number, date_hired, must_change_password)
  VALUES (btrim(p_first_name), nullif(btrim(coalesce(p_middle_name, '')), ''), btrim(p_last_name), v_email, p_password_hash,
          p_position, nullif(btrim(coalesce(p_contact_number, '')), ''), p_date_hired, true)
  RETURNING staff_id INTO v_id;
  RETURN v_id;
END $$;

-- Manage Users, Edit staff: admin only. The last active admin cannot become a librarian.
CREATE OR REPLACE FUNCTION fn_update_staff(p_staff_id int, p_first_name text, p_middle_name text, p_last_name text,
                                           p_email text, p_position text, p_contact_number text, p_date_hired date,
                                           p_acting_staff_id int)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_email text := lower(btrim(coalesce(p_email, ''))); v_old text; v_name text;
BEGIN
  PERFORM fn_check_staff(p_acting_staff_id, true);
  SELECT s.position, s.first_name || ' ' || s.last_name INTO v_old, v_name FROM staff s WHERE s.staff_id = p_staff_id FOR UPDATE;
  IF v_old IS NULL THEN PERFORM fn_rule('That account no longer exists. Reload the page.', 'LM002'); END IF;
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF p_position IS NULL OR p_position NOT IN ('librarian', 'admin') THEN PERFORM fn_rule('Choose Librarian or Admin.'); END IF;
  IF p_date_hired > current_date THEN PERFORM fn_rule('Choose a date that is not in the future.'); END IF;
  IF v_old = 'admin' AND p_position <> 'admin'
     AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.position = 'admin' AND s.is_active AND s.staff_id <> p_staff_id) THEN
    PERFORM fn_rule(format('%s is the last active admin. Make another admin first.', v_name));
  END IF;
  IF EXISTS (SELECT 1 FROM staff s WHERE s.email = v_email AND s.staff_id <> p_staff_id) THEN
    PERFORM fn_rule('Another staff member already uses this email.');
  END IF;
  UPDATE staff s
     SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''), last_name = btrim(p_last_name),
         email = v_email, position = p_position, contact_number = nullif(btrim(coalesce(p_contact_number, '')), ''),
         date_hired = p_date_hired
   WHERE s.staff_id = p_staff_id;
END $$;

-- Profile and My Profile: your own name and contact number, and (members) your email. NULL email keeps it.
-- The transaction's actor must be this account. PHP checks the current password before an email change.
CREATE OR REPLACE FUNCTION fn_update_my_profile(p_user_type text, p_user_id int, p_first_name text, p_middle_name text,
                                                p_last_name text, p_contact_number text, p_email text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE
  v_actor_type text := nullif(current_setting('app.actor_type', true), '');
  v_actor_id   text := nullif(current_setting('app.actor_id', true), '');
  v_email      text := lower(btrim(p_email));
  v_rows       int;
BEGIN
  IF v_actor_type IS DISTINCT FROM p_user_type OR v_actor_id IS DISTINCT FROM p_user_id::text THEN
    PERFORM fn_rule('You can only change your own details here.', 'LM004');
  END IF;
  PERFORM fn_check_person(p_first_name, p_middle_name, p_last_name, v_email, p_contact_number);
  IF p_user_type = 'staff' THEN
    IF v_email IS NOT NULL THEN PERFORM fn_rule('An admin changes staff emails in Manage Users.', 'LM004'); END IF;
    UPDATE staff s SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''),
           last_name = btrim(p_last_name), contact_number = nullif(btrim(coalesce(p_contact_number, '')), '')
     WHERE s.staff_id = p_user_id;
  ELSIF p_user_type = 'student' THEN
    IF v_email IS NOT NULL AND EXISTS (SELECT 1 FROM student s WHERE s.email = v_email AND s.student_id <> p_user_id) THEN
      PERFORM fn_rule('Someone else already uses this email.');
    END IF;
    UPDATE student s SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''),
           last_name = btrim(p_last_name), contact_number = nullif(btrim(coalesce(p_contact_number, '')), ''),
           email = coalesce(v_email, s.email)
     WHERE s.student_id = p_user_id;
  ELSIF p_user_type = 'teacher' THEN
    IF v_email IS NOT NULL AND EXISTS (SELECT 1 FROM teacher t WHERE t.email = v_email AND t.teacher_id <> p_user_id) THEN
      PERFORM fn_rule('Someone else already uses this email.');
    END IF;
    UPDATE teacher t SET first_name = btrim(p_first_name), middle_name = nullif(btrim(coalesce(p_middle_name, '')), ''),
           last_name = btrim(p_last_name), contact_number = nullif(btrim(coalesce(p_contact_number, '')), ''),
           email = coalesce(v_email, t.email)
     WHERE t.teacher_id = p_user_id;
  ELSE
    PERFORM fn_rule('Unknown account type.', 'LM004');
  END IF;
  GET DIAGNOSTICS v_rows = ROW_COUNT;
  IF v_rows = 0 THEN PERFORM fn_rule('That account no longer exists. Sign in again.', 'LM002'); END IF;
END $$;

CREATE OR REPLACE FUNCTION fn_list_staff(p_search text DEFAULT NULL, p_active boolean DEFAULT NULL,
                                         p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (user_type text, user_id int, full_name text, email text, contact_number text, detail text, is_active boolean,
               joined_on date, first_name text, middle_name text, last_name text, course text, year_level smallint,
               department text, "position" text, must_change_password boolean, has_history boolean, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT u.user_type, u.user_id, u.full_name::text, u.email::text, u.contact_number::text, u.detail::text, u.is_active,
         u.joined_on, u.first_name::text, u.middle_name::text, u.last_name::text, u.course::text, u.year_level,
         u.department::text, u.position::text, u.must_change_password, u.has_history, count(*) OVER ()
    FROM v_user_directory u
   WHERE u.user_type = 'staff'
     AND (p_active IS NULL OR u.is_active = p_active)
     AND (coalesce(btrim(p_search), '') = '' OR u.full_name ILIKE fn_like(btrim(p_search)) OR u.email ILIKE fn_like(btrim(p_search))
          OR concat_ws(' ', u.first_name, u.middle_name, u.last_name) ILIKE fn_like(btrim(p_search)))
   ORDER BY u.is_active DESC, u.last_name, u.first_name, u.user_id
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

CREATE OR REPLACE FUNCTION fn_get_staff(p_staff_id int)
RETURNS TABLE (staff_id int, first_name text, middle_name text, last_name text, email text, "position" text,
               contact_number text, date_hired date, is_active boolean, must_change_password boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT s.staff_id, s.first_name::text, s.middle_name::text, s.last_name::text, s.email::text, s.position::text,
         s.contact_number::text, s.date_hired, s.is_active, s.must_change_password
    FROM staff s WHERE s.staff_id = p_staff_id
$$;

-- ===========================================================================
-- TRANSACTION (Kirk Pugnaria). Create = fn_issue_book (from an approved request) and fn_issue_book_direct (walk-in).
-- ===========================================================================

-- Issued Books and My Borrowed Books. p_status: borrowed, overdue, returned, lost (as shown, so a borrowed loan past
-- its due date counts as overdue), active (borrowed or overdue), past (returned or lost), or NULL / all.
-- Members always pass their own id from the session.
CREATE OR REPLACE FUNCTION fn_list_transactions(p_status text DEFAULT NULL, p_search text DEFAULT NULL,
                                                p_date_from date DEFAULT NULL, p_date_to date DEFAULT NULL,
                                                p_student_id int DEFAULT NULL, p_teacher_id int DEFAULT NULL,
                                                p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (transaction_id int, student_id int, teacher_id int, book_id int, staff_id int, request_id int,
               date_borrowed date, due_date date, date_returned date, status text, display_status text,
               borrower_type text, borrower_id int, borrower_first_name text, borrower_name text, borrower_email text,
               book_title text, book_author text, isbn text, replacement_cost numeric, issued_by_name text,
               days_late int, days_left int, fine_so_far numeric, fines_total numeric, fines_unpaid numeric, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.transaction_id, v.student_id, v.teacher_id, v.book_id, v.staff_id, v.request_id,
         v.date_borrowed, v.due_date, v.date_returned, v.status::text, v.display_status::text,
         v.borrower_type, v.borrower_id, v.borrower_first_name::text, v.borrower_name::text, v.borrower_email::text,
         v.book_title::text, v.book_author::text, v.isbn::text, v.replacement_cost::numeric, v.issued_by_name::text,
         v.days_late, v.days_left, v.fine_so_far::numeric, v.fines_total::numeric, v.fines_unpaid::numeric,
         count(*) OVER ()
    FROM v_loan_detail v
   WHERE (p_status IS NULL OR p_status = 'all' OR v.display_status = p_status
          OR (p_status = 'active' AND v.status IN ('borrowed', 'overdue'))
          OR (p_status = 'past' AND v.status IN ('returned', 'lost')))
     AND (p_date_from IS NULL OR v.date_borrowed >= p_date_from)
     AND (p_date_to IS NULL OR v.date_borrowed <= p_date_to)
     AND (p_student_id IS NULL OR v.student_id = p_student_id)
     AND (p_teacher_id IS NULL OR v.teacher_id = p_teacher_id)
     AND (coalesce(btrim(p_search), '') = '' OR v.borrower_name ILIKE fn_like(btrim(p_search))
          OR v.borrower_email ILIKE fn_like(btrim(p_search)) OR v.book_title ILIKE fn_like(btrim(p_search)))
   ORDER BY CASE WHEN p_status IN ('borrowed', 'active') THEN v.due_date END,
            CASE WHEN p_status = 'overdue' THEN v.days_late END DESC,
            CASE WHEN p_status IN ('borrowed', 'active', 'overdue') THEN v.transaction_id END,
            coalesce(v.date_returned, v.due_date) DESC, v.transaction_id DESC
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

CREATE OR REPLACE FUNCTION fn_get_transaction(p_transaction_id int)
RETURNS TABLE (transaction_id int, student_id int, teacher_id int, book_id int, staff_id int, request_id int,
               date_borrowed date, due_date date, date_returned date, status text, display_status text,
               borrower_type text, borrower_id int, borrower_first_name text, borrower_name text, borrower_email text,
               book_title text, book_author text, isbn text, replacement_cost numeric, issued_by_name text,
               days_late int, days_left int, fine_so_far numeric, fines_total numeric, fines_unpaid numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.transaction_id, v.student_id, v.teacher_id, v.book_id, v.staff_id, v.request_id,
         v.date_borrowed, v.due_date, v.date_returned, v.status::text, v.display_status::text,
         v.borrower_type, v.borrower_id, v.borrower_first_name::text, v.borrower_name::text, v.borrower_email::text,
         v.book_title::text, v.book_author::text, v.isbn::text, v.replacement_cost::numeric, v.issued_by_name::text,
         v.days_late, v.days_left, v.fine_so_far::numeric, v.fines_total::numeric, v.fines_unpaid::numeric
    FROM v_loan_detail v WHERE v.transaction_id = p_transaction_id
$$;

-- ===========================================================================
-- FINE (Danriel Jaramel). Overdue and lost fines still come from fn_return_book.
-- ===========================================================================

-- Fines, Add fine: a fine the desk adds by hand (for example damage found later). At most one overdue fine per loan.
CREATE OR REPLACE FUNCTION fn_create_fine(p_transaction_id int, p_fine_type text, p_amount numeric, p_staff_id int)
RETURNS int LANGUAGE plpgsql SECURITY DEFINER SET search_path = lms, pg_temp AS $$
DECLARE v_title text; v_id int;
BEGIN
  PERFORM fn_check_staff(p_staff_id);
  SELECT b.title INTO v_title FROM transaction x JOIN book b ON b.book_id = x.book_id
   WHERE x.transaction_id = p_transaction_id FOR UPDATE OF x;
  IF v_title IS NULL THEN PERFORM fn_rule('That loan does not exist. Choose a loan from the list.', 'LM002'); END IF;
  IF p_fine_type IS NULL OR p_fine_type NOT IN ('overdue', 'lost', 'damaged') THEN
    PERFORM fn_rule('Choose the kind of fine: overdue, lost or damaged.');
  END IF;
  IF p_amount IS NULL OR p_amount <= 0 OR p_amount > 99999999.99 THEN
    PERFORM fn_rule('The amount must be more than ₱0.00, for example 300 or 300.50.');
  END IF;
  IF p_fine_type = 'overdue' AND EXISTS (SELECT 1 FROM fine f WHERE f.transaction_id = p_transaction_id AND f.fine_type = 'overdue') THEN
    PERFORM fn_rule(format('The loan of “%s” already has an overdue fine. A loan gets at most one.', v_title));
  END IF;
  INSERT INTO fine (transaction_id, fine_type, amount) VALUES (p_transaction_id, p_fine_type, round(p_amount, 2))
  RETURNING fine_id INTO v_id;
  RETURN v_id;
END $$;

-- Fines and the member fines section. p_status: unpaid, paid, waived, or NULL / all.
-- A member's own list shows unpaid fines first.
CREATE OR REPLACE FUNCTION fn_list_fines(p_status text DEFAULT NULL, p_search text DEFAULT NULL,
                                         p_student_id int DEFAULT NULL, p_teacher_id int DEFAULT NULL,
                                         p_limit int DEFAULT 25, p_offset int DEFAULT 0)
RETURNS TABLE (fine_id int, transaction_id int, fine_type text, amount numeric, date_issued date, date_paid date, status text,
               waived_reason text, book_id int, date_borrowed date, due_date date, date_returned date, loan_status text,
               student_id int, teacher_id int, borrower_type text, borrower_id int, borrower_name text, borrower_email text,
               book_title text, total_count bigint)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.fine_id, v.transaction_id, v.fine_type::text, v.amount::numeric, v.date_issued, v.date_paid, v.status::text,
         v.waived_reason, v.book_id, v.date_borrowed, v.due_date, v.date_returned, v.loan_status::text,
         v.student_id, v.teacher_id, v.borrower_type, v.borrower_id, v.borrower_name::text, v.borrower_email::text,
         v.book_title::text, count(*) OVER ()
    FROM v_fine_detail v
   WHERE (p_status IS NULL OR p_status = 'all' OR v.status = p_status)
     AND (p_student_id IS NULL OR v.student_id = p_student_id)
     AND (p_teacher_id IS NULL OR v.teacher_id = p_teacher_id)
     AND (coalesce(btrim(p_search), '') = '' OR v.borrower_name ILIKE fn_like(btrim(p_search))
          OR v.borrower_email ILIKE fn_like(btrim(p_search)) OR v.book_title ILIKE fn_like(btrim(p_search)))
   ORDER BY CASE WHEN p_student_id IS NOT NULL OR p_teacher_id IS NOT NULL THEN v.status <> 'unpaid' END,
            v.date_issued DESC, v.fine_id DESC
   LIMIT fn_page_limit(p_limit) OFFSET fn_page_offset(p_offset)
$$;

CREATE OR REPLACE FUNCTION fn_get_fine(p_fine_id int)
RETURNS TABLE (fine_id int, transaction_id int, fine_type text, amount numeric, date_issued date, date_paid date, status text,
               waived_reason text, book_id int, date_borrowed date, due_date date, date_returned date, loan_status text,
               student_id int, teacher_id int, borrower_type text, borrower_id int, borrower_name text, borrower_email text,
               book_title text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = lms, pg_temp AS $$
  SELECT v.fine_id, v.transaction_id, v.fine_type::text, v.amount::numeric, v.date_issued, v.date_paid, v.status::text,
         v.waived_reason, v.book_id, v.date_borrowed, v.due_date, v.date_returned, v.loan_status::text,
         v.student_id, v.teacher_id, v.borrower_type, v.borrower_id, v.borrower_name::text, v.borrower_email::text,
         v.book_title::text
    FROM v_fine_detail v WHERE v.fine_id = p_fine_id
$$;

-- PUBLIC runs none of these (05_roles.sql also revokes everything in the schema from PUBLIC).
DO $$
DECLARE r record;
BEGIN
  FOR r IN
    SELECT p.oid::regprocedure AS sig
      FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
     WHERE n.nspname = 'lms'
       AND p.proname IN ('fn_like', 'fn_page_limit', 'fn_page_offset', 'fn_check_person', 'fn_isbn_normalize', 'fn_tidy_category',
                         'fn_check_book', 'fn_create_book', 'fn_update_book_details', 'fn_list_books', 'fn_get_book',
                         'fn_list_categories', 'fn_create_student', 'fn_update_student', 'fn_list_students', 'fn_get_student',
                         'fn_list_courses', 'fn_create_teacher', 'fn_update_teacher', 'fn_list_teachers', 'fn_get_teacher',
                         'fn_list_departments', 'fn_create_staff', 'fn_update_staff', 'fn_update_my_profile', 'fn_list_staff',
                         'fn_get_staff', 'fn_list_transactions', 'fn_get_transaction', 'fn_create_fine', 'fn_list_fines', 'fn_get_fine')
  LOOP
    EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', r.sig);
  END LOOP;
END $$;
