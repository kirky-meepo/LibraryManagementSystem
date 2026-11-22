# Member task sheet: Create and Read per table

PC318 Group 7. Each member owns one entity. Create and Read are done now as stored functions in `database/04c_crud.sql` (Transaction's Create was already in `database/02_routines.sql`). Update and Delete are for Finals; the table at the end lists what already exists for them.

Every function is `SECURITY DEFINER` with `search_path = lms, pg_temp`, PUBLIC cannot run it, it never returns `password_hash`, and it raises plain sentences with the project's codes (LM001 a broken rule, LM002 not found, LM004 not allowed). Every list function takes optional filters plus `p_limit` (default 25) and `p_offset` (default 0) and returns `total_count` on every row, which the page pagers use.

## Create and Read

| Entity | Owner | Create (function, page) | Read (functions, pages) |
| --- | --- | --- | --- |
| Book | Rochie Ann B. Surban | `fn_create_book(isbn, title, author, category, copies_total, replacement_cost, staff_id)` → book_id. Add New Book (`public/librarian/book_add.php`). Up to 5 copies (the default copy limit), ISBN-10 or 13 checked and stored as ISBN-13, a duplicate ISBN refused, category tidied. | `fn_list_books(search, category, only_available, include_archived, limit, offset)` with copy_limit and availability; `fn_get_book(book_id)`; `fn_list_categories(include_archived)`. Manage Books, the member Catalog and its category list, Requests (book filter), the Edit book pop-up. |
| Student | Beverly Kate Abalde | `fn_create_student(first, middle, last, course, year_level, contact, email, password_hash, staff_id)` → student_id, must change the temporary password at first sign-in. Manage Users, Add user (`public/api/user_save.php`). | `fn_list_students(search, active, limit, offset)`; `fn_get_student(id)`; `fn_list_courses()`. Manage Users, Students; My Profile; every signed-in student page (`current_user()`). |
| Teacher | Beverly Kate Abalde | `fn_create_teacher(first, middle, last, department, contact, email, password_hash, staff_id)` → teacher_id. Manage Users, Add user. | `fn_list_teachers(search, active, limit, offset)`; `fn_get_teacher(id)`; `fn_list_departments()`. Manage Users, Teachers; My Profile. |
| Staff | Khem Cafino | `fn_create_staff(first, middle, last, email, password_hash, position, contact, acting_staff_id, date_hired)` → staff_id. Admin only. Manage Users, Add user (Staff). | `fn_list_staff(search, active, limit, offset)`; `fn_get_staff(id)`. Manage Users, Staff; Profile; every signed-in staff page. |
| Transaction | Kirk Pugnaria | `fn_issue_book(request_id, staff_id)` (an approved request) and `fn_issue_book_direct(student_id, teacher_id, book_id, staff_id)` (walk-in), both in `02_routines.sql`. Issue Book (`public/librarian/issue.php`). Nothing in the site inserts into `transaction` directly. | `fn_list_transactions(status, search, date_from, date_to, student_id, teacher_id, limit, offset)`; `fn_get_transaction(id)`. Issued Books (tabs, search, one loan), My Borrowed Books (members always pass their own id from the session), the Catalog's "You have this book", the Add fine loan list. |
| Fine | Danriel Jaramel | `fn_create_fine(transaction_id, fine_type, amount, staff_id)` → fine_id, for a fine added by hand: the loan must exist, the kind is overdue, lost or damaged, the amount is above ₱0.00, and a loan gets at most one overdue fine. Fines, **Add fine** (staff only). Overdue and lost fines still come from `fn_return_book`. | `fn_list_fines(status, search, student_id, teacher_id, limit, offset)`; `fn_get_fine(id)`. Fines (tabs, search), My Borrowed Books (fines section). |

Helpers in the same file: `fn_like` (safe "contains" search), `fn_page_limit` / `fn_page_offset`, `fn_check_person`, `fn_isbn_normalize`, `fn_tidy_category`, `fn_check_book`.

## Update and Delete (for Finals): what already exists

| Entity | Update | Delete |
| --- | --- | --- |
| Book | `fn_update_book_details` (catalogue details, Step 17), `fn_set_copies_total`, `fn_set_copy_limit` (admin), `fn_set_book_archived` | None on purpose: a book with loans or requests is archived, never deleted. |
| Student and Teacher | `fn_update_student`, `fn_update_teacher` (Step 17), `fn_update_my_profile` (your own details), `fn_set_user_active`, `fn_set_password` / `fn_staff_reset_password` / `fn_reset_password` | `fn_delete_user` (admin, only without history) |
| Staff | `fn_update_staff` (admin, never the last active admin, Step 17), `fn_update_my_profile`, `fn_set_user_active`, the password routines | `fn_delete_user` (admin, never yourself) |
| Transaction | `fn_return_book` (good, damaged, lost), `sp_mark_overdue` | None: a loan is history. Fines, the audit trail and reports point at it, and a deleted loan would make a paid fine meaningless. |
| Fine | `fn_pay_fine`, `fn_waive_fine` (admin, with a reason) | None: a fine is money history. A wrong fine is waived with a reason, so the record of what happened stays. |

## Direct queries replaced in Step 17

Every SELECT, INSERT or UPDATE on staff, student, teacher, book, transaction or fine in `public/` and `src/` now goes through a function above or a `v_*` view:

| Where | Was | Now |
| --- | --- | --- |
| src/auth.php `current_user()` | SELECT from staff, student, teacher | `fn_get_staff`, `fn_get_student`, `fn_get_teacher` |
| src/books.php `book_categories()` | SELECT DISTINCT category FROM book | `fn_list_categories` |
| src/books.php `validate_book()` | duplicate ISBN SELECT on book | `v_book_detail` (and `fn_check_book` in the database) |
| src/users.php `validate_user()` | email SELECT on the person's table | `v_user_directory` |
| src/activity.php | actor names from staff, student, teacher; JOIN book | `v_user_directory`, `v_book_detail` |
| public/librarian/book_add.php | INSERT INTO book | `fn_create_book` |
| public/api/book_save.php | UPDATE book; current book from the view | `fn_update_book_details`, `fn_get_book` |
| public/librarian/books.php | SELECT title FROM book; the list | `fn_get_book`, `fn_list_books` |
| public/librarian/requests.php | SELECT book for the filter | `fn_get_book` |
| public/member/catalog.php | SELECT title FROM book; categories; SELECT FROM transaction; the list | `fn_get_book`, `fn_list_categories`, `fn_list_transactions`, `fn_list_books` |
| public/librarian/users.php | the list; DISTINCT course, department | `fn_list_students` / `fn_list_teachers` / `fn_list_staff`, `fn_list_courses`, `fn_list_departments` |
| public/api/user_save.php | INSERT and UPDATE on the person's table; the last-admin count on staff | `fn_create_*`, `fn_update_*` (the last-admin rule moved into `fn_update_staff`) |
| public/librarian/profile.php | SELECT and UPDATE staff | `fn_get_staff`, `fn_update_my_profile` |
| public/member/profile.php | SELECT, email check and UPDATE on student or teacher | `fn_get_student` / `fn_get_teacher`, `v_user_directory`, `fn_update_my_profile` |
| public/librarian/issued.php | the list and tab counts; SELECT FROM fine | `fn_list_transactions`, `fn_get_transaction`, `v_fine_detail` |
| public/member/borrowed.php | loans and fines | `fn_list_transactions`, `fn_list_fines` |
| public/librarian/fines.php | the list; the fine for Pay and Waive | `fn_list_fines`, `fn_get_fine`, and the new Add fine through `fn_create_fine` |
| public/api/loan_return.php | SELECT FROM fine after a return | `v_fine_detail` |

After this, `lms_app` lost every direct right on password hashes and every INSERT or UPDATE on staff, student, teacher and book (`database/05_roles.sql`). Database check 23 proves it.
