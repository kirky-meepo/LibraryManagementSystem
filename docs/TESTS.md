# Tests you can run by hand

**Note (Nov 22, 2026):** the sample people (Maria, Ella, Miguel and the rest) and `DEMO_PASSWORD` were removed; a reset now makes only the office admin and the 229 books. The dated records below describe the data as it was when each case was run. To repeat a case today, add the people it needs in Manage Users.

Run these after `database\reset.ps1` so the demo data is fresh. Start the site with `php -S localhost:8000 -t public` and open http://localhost:8000.

The demo accounts all use the password in `DEMO_PASSWORD` in `.env`:

| Account | Role | Signs in with |
| --- | --- | --- |
| admin@lms.edu | Admin | Librarian/Admin |
| maria.lopez@lms.edu | Librarian | Librarian/Admin |
| ella.ramirez@school.edu | Student | Member |
| d.reyes@school.edu | Teacher | Member |

Other seeded members (same password): miguel.santos, andrea.cruz, paolo.garcia, bea.mendoza, josh.villanueva (@school.edu, students); r.bautista, l.aquino (@school.edu, teachers; Liza Aquino is inactive).

A ticked box means the case was walked through and passed on the date shown in the heading.

**If sign-in gets locked during testing.** The lock counts failed tries per email and per IP address, and on one PC every browser shares the same IP. To unlock at once, run this as the postgres superuser (in pgAdmin or psql):

```sql
DELETE FROM lms.login_attempt WHERE NOT success;
```

## Database rules (Steps 2 to 4)

Run `powershell -ExecutionPolicy Bypass -File database\run_tests.ps1`. It rebuilds a scratch copy called lms_test and prints one PASS or FAIL line per rule (26 checks: requests, approvals, loans, fines, expiry, delete and deactivate rules, the audit trail, the database roles, the per-title copy limit, sign-up, sign-in lock-out, reset links, password changes and their audit rows, the Create and Read functions with the app-role lock-down, requests for titles with no copies, and sessions stored in the database).

- [x] All 13 checks print PASS (Nov 20, 2026).
- [x] Inserting a book with copies_available bigger than copies_total is refused by ck_book_copies.
- [x] A teacher cannot use an email that a student already has, in any letter case.
- [x] Running the reset twice gives the same row counts and the same v_dashboard_stats row.

## Step 5: sign-in, sessions and access (Nov 20, 2026)

- [x] **The four demo accounts sign in.** Choose the matching toggle, type the email and password. Admin and Maria land on /librarian/dashboard.php; Ella and D. Reyes land on /member/home.php, each showing their name and role.
- [x] **Wrong details give one message.** A wrong password and an email that does not exist both say "Wrong email or password."
- [x] **Inactive accounts are refused clearly.** Sign in as l.aquino@school.edu with the right password: "This account is inactive. Visit the library desk to reactivate it."
- [x] **A member cannot open librarian pages.** Signed in as Ella, type /librarian/dashboard.php in the address bar: the 403 page "This page is not for your account" with a button back to her home page.
- [x] **A librarian cannot open admin-only pages.** Signed in as Maria, open /librarian/settings.php: the 403 page. (Deleting users is admin-only too; see Step 9.) Admin can open it.
- [x] **6 wrong passwords lock sign-in for 15 minutes.** Try a wrong password 6 times for the same email. Try 5 says "Sign-in is now locked for 15 minutes."; try 6, and even the right password, says "Too many failed sign-ins. Sign-in is locked for 15 minutes." `SELECT * FROM lms.login_attempt` shows each try with success = false.
- [x] **A POST without a CSRF token is refused.** Sending the Sign out form without its hidden token gives "This form expired" (HTTP 400) and nothing changes.
- [x] **Pages need sign-in.** Signed out, open /member/home.php: you are sent to the sign-in page, and after signing in you come back to that page.
- [x] **Must change password.** Set `must_change_password = true` for a person, sign in: every page sends you to Change password until it is done. The current password is required, the new one needs at least 8 characters, and the audit log records `password_changed: true` without any hash.
- [x] **Idle timeout.** Leave the site open for 30 minutes (session_idle_minutes) without clicking, then click a link: you are signed out with "You were signed out after 30 minutes without activity. Sign in again to continue." (Tested Nov 21, 2026 by moving the session's last_seen time back 31 minutes instead of waiting.)

## Step 7: librarian dashboard (Nov 20, 2026)

- [x] **Card numbers come from the database.** Signed in as Maria, the six cards show 12 titles, 8 copies on loan, 2 awaiting pickup, 3 pending requests, 2 overdue and ₱3,500.00 unpaid, the same as `SELECT * FROM lms.v_dashboard_stats`.
- [x] **Every card goes somewhere real.** Total titles opens Manage Books, Copies on loan opens Issued Books, Awaiting pickup opens Requests (approved), Pending requests opens Requests (pending), Overdue opens Issued Books (overdue), Unpaid fines opens Fines.
- [x] **Needs attention.** Lists Miguel Santos (Computer Networking, 6 days late, ₱60.00 so far), Andrea Cruz (4 days late), the two pickups due today and tomorrow, the two requests older than 2 days and Cosmos (no copy on the shelf, 1 pending request). Each line links to the record.
- [x] **Recent activity** shows the latest 8 events in plain words, for example "Maria approved Ramon's request for The Pragmatic Programmer, 3 days ago".
- [x] **Fresh numbers.** Loading the dashboard runs sp_mark_overdue() and sp_expire_reservations(); their changes are logged as the system, not as the librarian.

## Step 8: Manage Books and Add New Book (Nov 20, 2026)

- [x] **List, search and filter.** Manage Books shows 12 titles with copies available / total and a chip: Available, Low (1 left) or None (0). Searching "rizal" finds the two Rizal novels; an ISBN typed without hyphens (0132350884) finds Clean Code; the Science category shows 2 books. Paging is 15 rows.
- [x] **Duplicate ISBN.** Add New Book with ISBN 0-13-235088-2 (Clean Code written as ISBN-10): "A book with this ISBN already exists: Clean Code. Edit that book instead of adding it again." No error screen.
- [x] **Wrong values are explained.** An ISBN with a wrong check digit, an empty title, 0 copies or a negative cost each get their own message next to the field.
- [x] **Add a book.** Introduction to Algorithms, ISBN 978-0-262-03384-8, 3 copies, ₱4,850.50: saved as 978-0262033848 with 3 of 3 on the shelf, and the toast offers Add another.
- [x] **Raise copies.** Edit The Pragmatic Programmer from 3 to 5 copies: available goes from 1 to 3 (fn_set_copies_total).
- [x] **Lower copies below what is out.** Set it to 1: refused with "The Pragmatic Programmer cannot go down to 1: 2 copies are on loan or reserved right now. Set 2 or more." Nothing is saved, not even other edited fields.
- [x] **Archive is blocked while copies are out.** Archive Cosmos (3 on loan): "Cosmos cannot be archived yet: 3 copies are on loan. Wait for the returns or cancel the reservations first."
- [x] **Archive and restore.** Archive El Filibusterismo: it disappears from the list until Show archived is ticked; Restore brings it back. Pending requests of an archived book are cancelled with a message to the borrower.
- [x] **Details drawer** shows on the shelf, on loan, reserved, pending requests, all-time requests and loans, ISBN, cost and who added the book.

## Step 9: Manage Users and Profile (Nov 20, 2026)

- [x] **One page, three lists.** The sidebar has one Manage Users entry. The Show dropdown switches between Students (Course, Year level), Teachers (Department) and Staff (Position); search by name or email and a Status filter (Active, Inactive, All) work with paging.
- [x] **Inactive rows** are greyed with an Inactive chip (Liza Aquino under Teachers, Status All).
- [x] **Deactivation is blocked with the reason.** Deactivate Miguel Santos: "Miguel Santos has 1 overdue book. They must be returned or cancelled before the account can be deactivated."
- [x] **Librarian limits.** As Maria: staff rows say "Admin only", no Delete appears, and a forged delete or staff edit is refused ("Only an admin can do this.").
- [x] **Admin delete.** As Admin, Delete appears only for people with no history (Josh Villanueva); others show "Has borrowing history. Deactivate instead." Deleting Ella anyway is refused by fn_delete_user with the same message.
- [x] **Nobody acts on themselves.** Admin's own row offers Edit my profile only; a forged self-deactivation says "You cannot deactivate your own account." The last active admin cannot be changed to librarian.
- [x] **One email per member.** Adding a teacher with Ella.Ramirez@school.edu (any case) is refused: "The email ella.ramirez@school.edu already belongs to a student."
- [x] **Add user.** Adding Carlo Dizon shows a temporary password once in a pop-up with a Copy button. The database holds only a bcrypt hash with must_change_password = true; the plain password is in no table, log or audit row. Carlo's first sign-in goes straight to Change password.
- [x] **Reset password** for Ella shows a new temporary password once and sets must_change_password.
- [x] **Reactivate** brings Liza Aquino back; deactivating someone with no open loans works and cancels their pending requests.
- [x] **Profile.** Maria edits her first, middle and last name and contact number; email and role are shown read-only; changing the password needs the current password.

## Step 10: Requests and the message pop-up (Nov 20, 2026)

These were run in a real (headless) Edge browser, clicking the buttons.

- [x] **Tabs and rows.** Requests has tabs Pending, Awaiting pickup, Fulfilled, Rejected, Expired and All, each with a count. Each row shows the borrower with a Student or Teacher chip, the book, when it was requested, copies left, the borrower's loans (for example 2 of 5) and any unpaid fine or overdue book.
- [x] **Approve fills in the message.** Approve on Ella's request for Clean Code: pickup date defaults to the next open day (Monday Nov 23, 2026 when tomorrow is a Saturday), time 8:00 AM, preset Standard pickup, and the message already says "Hi Ella, ... your request for “Clean Code” is approved. Please pick it up at the library on Nov 23, 2026 at 8:00 AM ... We will hold it until Nov 25, 2026 ... Maria Lopez, School Library".
- [x] **Re-rendering.** Changing the preset (Flexible pickup), date or time fills the text in again; Write my own empties it and the send button stays disabled until something is written. A Sunday pickup date says the library is closed on Sundays.
- [x] **Keyboard.** The pop-up opens with focus on Pickup date; Esc closes it and focus returns to the Approve button.
- [x] **Reject needs a reason.** Reject on Bea's request for Cosmos suggests the Unpaid fine preset (₱300.00) and fills the reason; emptying the reason disables "Reject and send message".
- [x] **Approve and send.** After "Approve and send message": the toast says "Approved. Message sent to Ella.", the row leaves Pending and appears under Awaiting pickup, Clean Code's available copies drop from 4 to 3, and the message table has a request_approved message to Ella with read_at empty (unread).
- [x] **No copy left.** Approving Bea's request for Cosmos (0 on the shelf) is refused with "No copy of “Cosmos” is free right now ..." and a button opens Reject with the All copies on loan preset already filled in.
- [x] **View message** under decided requests shows the subject, text, sent time and Read or Unread.
- [x] **Cancel reservation** on an Awaiting pickup row puts the copy back and sends the borrower a short message (fn_cancel_request; database check 2).

## Step 11: Issue Book, Issued Books and Fines (Nov 20, 2026)

Run in headless Edge, clicking through the pages.

- [x] **Issue from an approved request.** After approving Ella's request for Clean Code, Issue Book lists it with "Due if issued today: Dec 4, 2026". Issue book asks "Issue Clean Code to Ella Ramirez? It is due on Dec 4, 2026." Afterwards the toast says "Issued Clean Code to Ella. Due Dec 4, 2026.", Clean Code's available copies stay at 3 (the copy was reserved at approval), and the request becomes Fulfilled.
- [x] **Walk-in shows blocks first.** Choosing Bea and a book shows "This loan will probably be refused: Unpaid fine of ₱300.00." Issuing anyway shows the database message "Bea Mendoza has an unpaid fine of ₱300.00. It must be settled at the library desk first." Ramon can borrow Noli Me Tangere, due Dec 4, 2026.
- [x] **Return late as Good.** Mark returned on Miguel's Computer Networking says "Due Nov 14, 2026, 6 days late. An overdue fine of ₱60.00 will be added." After saving, the pop-up shows "Overdue fine: ₱60.00" before it closes, and the Fines page lists ₱60.00 overdue, unpaid.
- [x] **Return as Lost.** Database System Concepts goes from 4 to 3 total copies (available stays 3) and a lost fine of ₱3,800.00 (its replacement cost) appears.
- [x] **Damaged needs an amount.** Choosing Damaged shows the amount field; saving it empty says "Enter the damage amount in pesos, for example 300 or 300.50."
- [x] **Send reminder** (a loan due within 3 days) opens the message pop-up with the Due date reminder preset already filled in ("Hi Bea, “Discrete Mathematics and Its Applications” is due on Nov 22, 2026 ..."); Send overdue notice appears on overdue rows.
- [x] **Fines.** Totals at the top (Unpaid, Collected, Waived) come from the database. Mark paid records the payment. Waive is admin only: a librarian does not see it and a forged waive is refused; an empty reason is refused; with a reason the fine becomes Waived and the reason is shown.
- [x] **No page calculates a fine.** Overdue, damaged and lost amounts are created only by fn_return_book; the "fine so far" shown on loans comes from v_loan_detail.

## Step 12: Messages, Status and Reports, Export, Settings and Activity (Nov 21, 2026)

Run in headless Edge and with curl.

- [x] **Message to All teachers.** Choosing All teachers hides the name field; a live count shows "73 / 1,000 characters". Sending says "Message sent to 2 people (all teachers, active accounts only)." and the Sent messages log shows one row per teacher (Diana Reyes, Ramon Bautista). Liza Aquino is inactive, so she gets none.
- [x] **Message to one person.** Picking "Ella Ramirez <ella.ramirez@school.edu>" from the list sends one general message: "Message sent to Ella Ramirez."
- [x] **Sent log** filters by kind (Approvals, Rejections, Reminders, Overdue, General) and shows Read or Unread; View opens the full text.
- [x] **Charts match the database.** For the last 3 months the borrows and returns chart shows Sep 2 / 2, Oct 2 / 2, Nov 9 / 1, exactly as `SELECT month, borrows, returns FROM lms.v_monthly_activity` (set `app.report_from` the same way, or compare the same months). Fines issued add up to ₱3,580.00. Each chart has a one-line text summary, a legend (2 series), a number on every bar, a hover tooltip and a "Show the numbers as a table" view.
- [x] **Period filter** (This month, Last 3 months, This year) changes every chart and the borrows column of Books by category.
- [x] **Print.** The Status and Reports page prints on one A4 page (checked with print-to-PDF: 1 page).
- [x] **CSV export.** Books, Issued loans, Fines and Users download as UTF-8 with a byte-order mark, so ₱ in the headers and "José Rizal" show correctly in Excel. A book titled `=HYPERLINK("http://x","click")` is exported as `'=HYPERLINK(...)`, so Excel shows it as text instead of running it. The Users file has no password or hash column. A member gets 403.
- [x] **Settings (admin only).** Maria gets 403. Admin entering "whenever" for the hours and -5 for the fine sees "Write the days and hours like Mon-Fri 8:00 AM-5:00 PM." and "Use 0 or more pesos". Valid values (Mon-Sat 7:30 AM-6:00 PM, ₱12.50) are saved; Miguel's fine so far changes straight away to 6 days x ₱12.50 = ₱75.00.
- [x] **Activity (admin only).** Filtering by Admin User and Settings shows "Admin User set the fine per day to 12.50" with "setting value: 10.00 → 12.50". Filters by person, record type and dates work, 25 rows a page.

## Step 13: the member side (Nov 21, 2026)

Run in headless Edge as Ella, Maria and Bea.

- [x] **Home.** Ella sees four small cards (Borrowed now 2, Awaiting pickup 0, Unread messages 0, Fines to pay ₱0.00), each linking to its page, and a Due soon list (empty when nothing is due within 7 days).
- [x] **Catalog.** Books show as cards with a cover drawing (or as a table with the Cards | Table toggle), with search, a category filter and an Available / Low / None chip. Archived books never appear. A book Ella already asked for says Requested; a book she has says You have this book.
- [x] **Request, approve, read.** Ella requests Noli Me Tangere (confirm pop-up, then "You requested Noli Me Tangere. You will get a message when the library decides."). Maria approves it. Back as Ella, the Messages item in the sidebar shows a red 1, the new message is first and bold, and opening it shows a pickup card (Nov 23, 2026 at 8:00 AM, held until Nov 25, 2026). The badge disappears and read_at is set in the database.
- [x] **Refused in plain words.** Bea (unpaid fine) requests Clean Code: "You have an unpaid fine of ₱300.00. It must be settled at the library desk first." Her home page explains the block too.
- [x] **Nobody else's data.** Opening /member/messages.php?open=<a message id of Bea's> as Ella says "That message is not in your inbox." and shows nothing. Changing the request id in Ella's Cancel form to Bea's request is refused ("You can only cancel your own requests.") and Bea's request stays pending. Every member query is filtered by the id in the session, never one from the address bar.
- [x] **My Requests** shows status chips (Pending, Approved, Rejected, Fulfilled, Expired, Cancelled), the pickup date, time and deadline for approved requests, the reason for rejected ones, and Cancel on pending or approved ones.
- [x] **My Borrowed Books** shows each loan with an On time / Due soon / Overdue chip and days left, the fine so far, and a Fines section with unpaid and paid totals. It says to return and pay at the library desk.
- [x] **My Profile.** Name and contact number save. Changing the email without the current password is refused ("To change your email, type your current password."). Course, year level and role are shown read-only. New passwords need at least 8 characters.

## Step 15: per-title copy limit (Nov 21, 2026)

Database check 14 in `database
un_tests.ps1`, plus these by hand.

- [x] **Database rule.** fn_set_copies_total refuses a total above the title's copy_limit ("“Clean Code” can have at most 5 copies."). A plain UPDATE that sets copies_total above copy_limit is refused by ck_book_within_limit. A librarian calling fn_set_copy_limit is refused (admin only). An admin cannot set the limit below the copies the title has ("Remove N copies first"), nor outside 1 to 999.
- [x] **Add New Book.** The Copies field says "Up to 5 copies. An admin can raise the limit for a title." and 6 copies is refused with that sentence.
- [x] **Edit pop-up as Maria (librarian).** There is no Copy limit field. Setting 6 copies on a title with limit 5 shows "Copies must be a whole number from 1 to 5. An admin can raise the limit for a title." under Copies, and nothing is saved.
- [x] **Edit pop-up as admin.** The Copy limit field is shown. Limit 8 with 7 copies saves. Limit 2 with 7 copies is refused under Copies. Back to 4 copies and limit 5 saves.
- [x] **Details drawer** shows the Copy limit row for every staff member.

## Step 16: sign in, sign up, sign out and forgot password (Nov 21, 2026)

Database checks 15 to 19 in `database\run_tests.ps1`:

- [x] 15. Sign-up refuses an email that a student, teacher or staff member already has (any letter case), refuses `staff`, refuses anything that is not a password_hash() value, refuses year level 7, and refuses the 6th sign-up from one IP address in an hour.
- [x] 16. Five failed sign-ins lock the email (from any IP) and the IP (for any email) for 15 minutes; after 4 failures one try is left; the 6th try returns no account data at all. A "success" for an account that does not own the email is refused.
- [x] 17. A reset link works once and for 30 minutes; using it kills the account's other open links; an expired link is refused; unknown and inactive emails get no row and no error; a 4th open link per account and a 6th link per IP per hour quietly give nothing.
- [x] 18. fn_set_password changes only your own password; a librarian resets a student (must change at next sign-in) but not an admin; an admin resets a librarian but not themself; lms_app cannot read password_reset or call fn_auth_store_hash.
- [x] 19. audit_log has login, logout, sign_up, password_changed, password_reset_by_staff, password_reset_requested and password_reset rows, none holds a hash or a token, and the trigger adds no second row.

By hand (HTTP run against `php -S localhost:8000`, MAIL_DRIVER=outbox, APP_ENV=dev):

- [x] **Sign-in page.** The old "Ask the library desk to reset it for you" text is gone. "Forgot your password?" sits next to the Password label on both tabs. "New to the library? Create an account" shows on the Member side and disappears on the Librarian/Admin side (also without JavaScript, through CSS).
- [x] **Sign in and out.** Ella signs in and lands on Home. Sign out (POST) says "You are signed out." and adds a logout row to the audit trail. Opening /logout.php in the address bar does not sign anyone out.
- [x] **Create an account.** Student | Teacher switch shows Course and Year level or Department. An email that a member or staff member already has is refused ("That email already has an account. Sign in, or use Forgot your password."), so is a 5-character password. Sending user_type=staff by hand is treated as a student and needs a course. A good form says "Account created. Sign in to continue." and the new student can sign in.
- [x] **Forgot your password.** Any email gets the same sentence: "If that email belongs to an account, we sent a reset link. It works for 30 minutes." Only for a real, active account does the Demo mode box appear, with the link and the outbox file name. Liza (inactive) and an unknown email get no box.
- [x] **Reset password.** The link greets Ella by name, sends no referrer and is not cached. Different passwords are refused. A good password says "Password changed. Sign in with your new password.", the new password works, and the outbox holds a "Your password was changed" notice to Ella. The same link, and a made-up one, show "This reset link has expired or was already used. Ask for a new one." with an Ask for a new link button.
- [x] **Lock-out messages unchanged.** Four wrong passwords say "Wrong email or password.", the 5th "Wrong email or password. Sign-in is now locked for 15 minutes.", the 6th "Too many failed sign-ins. Sign-in is locked for 15 minutes. Wait, then try again."
- [x] **Staff reset still works.** Maria resets a student's password from Manage Users (temporary password shown once); resetting an admin is refused ("Only an admin can reset staff passwords.").
- [x] **Change password** refuses a wrong current password and saves a good one ("Your password is changed.").
- [x] **360px.** Sign in, Create an account, Forgot your password and Reset password have no sideways scrolling at 360px and no JavaScript errors.

## Step 17: Create and Read functions per member (Nov 21, 2026)

Database checks 20 to 24 in `database\run_tests.ps1`:

- [x] 20. fn_create_book stores an ISBN-10 as ISBN-13, refuses the same ISBN in another form, a wrong check digit and 6 copies, and tidies the category to an existing spelling; fn_list_books finds titles by words and by ISBN digits, with total_count, and "only available" leaves out titles with no copy on the shelf; fn_get_book shows copy_limit 5; fn_update_book_details refuses another book's ISBN and can fix the letter case of a category.
- [x] 21. fn_create_fine refuses a missing loan (LM002), ₱0, an unknown kind and a 2nd overdue fine on one loan; a good fine is unpaid; fn_list_fines and fn_get_fine read it back.
- [x] 22. A librarian cannot create staff; an admin can; new students, teachers and staff must change their temporary password; a duplicate email (any letter case) and a non-hash are refused; fn_update_staff checks the email; the lists and gets return the right rows.
- [x] 23. lms_app cannot SELECT password_hash but can read the other columns, cannot UPDATE password_hash, cannot INSERT people or books or UPDATE books; the functions still work for it; no function but fn_login_lookup returns a password hash.
- [x] 24. fn_list_transactions filters by active and past, by person and by date; fn_get_transaction shows the loan; fn_update_my_profile changes only your own details.

By hand (HTTP run against `php -S localhost:8000` as Admin, Maria, Ella and Diana):

- [x] All 28 staff pages and tabs (including page 9 of a short list, a one-loan link and searches) and all 8 member pages per member load without a database error after the lock-down.
- [x] Manage Books says "Showing 1 to 12 of 12 books"; Manage Users, Students lists through fn_list_students with its pager.
- [x] Add New Book saves through fn_create_book ("… is added with 3 copies"), the same ISBN typed as ISBN-13 is refused, and the edit pop-up saves through fn_update_book_details and fn_set_copies_total.
- [x] Add user makes a student (temporary password shown once) and, as admin, a staff member; Maria cannot add staff. Editing staff goes through fn_update_staff.
- [x] Fines has **Add fine** (any staff). ₱0 is refused ("The amount must be more than ₱0.00 …"); ₱150 saves "A ₱150.00 damaged fine is added for …".
- [x] Profile (staff) and My Profile (Ella, Diana) save through fn_update_my_profile.
- [x] The Catalog never shows archived titles and still marks books Ella has; My Borrowed Books lists her loans and fines.

## Step 18: textbook catalog, borrowers and loans (Nov 21, 2026)

- [x] `database\reset.ps1` runs 07_seed_catalog.sql; its own checks pass (14 titles with no copy on the shelf, 21 with one, every copy on the shelf, on loan or reserved, no loan before its book or borrower existed, nobody over the limit, 5 overdue in all). `reset.ps1 -NoSeed` skips 06 and 07.
- [x] `php database\tools\verify_isbns.php`: 206 ISBNs, 205 verified with Open Library; the one left is The Pragmatic Programmer from the original 12 (Open Library has no title for it). 23 titles have no ISBN.
- [x] The 24 database checks still pass with the bigger seed.
- [x] Every staff and member page loads in under half a second with 229 titles and 92 loans; Manage Books page 16 and Catalog page 10 work.
- [x] Kristine Dela Cruz (5 of 5) asks for Campbell Biology: "You have reached the borrowing limit: 5 of 5 loans and open requests are in use."
- [x] Status and Reports shows 22 categories, the top 10 and the monthly charts without overlap.

## Step 19: end to end (Nov 21, 2026)

- [x] `database\run_tests.ps1`: 25 of 25 checks pass. New check 25: a title whose copies are all on loan can be requested (it waits) but not approved; a title with no copies left at all cannot be requested.
- [x] Counts after a reset: 229 titles in 22 categories; 24 students and 8 teachers; 61 open loans; on the shelf 14 titles at 0, 21 at 1, 194 at 2 or more. Consistency: for all 229 titles copies_total - copies_available = open loans + approved requests, and none is over its copy limit (0 violations).
- [x] `php database\tools\smoke.php` (its own php -S on 127.0.0.1:8091 with MAIL_DRIVER=outbox): 27 of 27 PASS.

| Smoke check | Result |
| --- | --- |
| copies add up and no title is over its limit (229 titles) | PASS |
| php -S answers and health.php says OK | PASS |
| sign-in page: Forgot link on both tabs, Create an account on Member only | PASS |
| Sign Up as a new student | PASS |
| the new student signs in | PASS |
| the new titles are in the Catalog, with paging (229 books) | PASS |
| Catalog search finds a new title | PASS |
| Catalog category filter | PASS |
| an available title can be requested | PASS |
| a title with no copy on the shelf can be requested and waits | PASS |
| sign out, and audit_log has the login and logout rows | PASS |
| Maria signs in | PASS |
| Maria approves the request, issues the book and returns it | PASS |
| a waiting request cannot be approved while no copy is free | PASS |
| an empty title (every copy lost) cannot be requested | PASS |
| Manage Users, Fines and Issued Books show the new data | PASS |
| a librarian gets the 403 page on admin pages | PASS |
| the admin opens Settings and Activity | PASS |
| forgot password for josh.villanueva writes the link to storage/outbox | PASS |
| the link resets the password | PASS |
| a "Your password was changed" notice is written | PASS |
| the new password signs in | PASS |
| the old password fails | PASS |
| the same link a second time is refused | PASS |
| an unknown email gets the same neutral message and no email | PASS |
| forgot password also works for a staff account | PASS |
| five wrong passwords lock the account; the 6th try is refused | PASS |

- [x] Live email (MAIL_DRIVER=brevo only in the commands' environment; 2 emails in all). `test_mail.php` to DEMO_INBOX: HTTP 201, Brevo events "requests" then **delivered** (7:49 PM). The real Forgot your password form for the Demo Admin plus-address on a php -S with MAIL_DRIVER=brevo and APP_ENV=prod: the neutral sentence, no Demo mode box, a password_reset_requested audit row (actor system, no token or hash), no outbox fallback; Brevo events "requests" then **delivered** (7:50 PM). The key can read the transactional logs.
- [x] Final `database\reset.ps1`, storage/outbox emptied.

## Step 20, local part: hosting settings (Nov 22, 2026)

- [x] Database check 26: sessions are stored, replaced, expired (fn_session_gc) and destroyed only through fn_session_*; lms_app cannot read app_session; a bad session id is refused. 26 of 26 checks pass.
- [x] `smoke.php --session=db` with `DB_EMULATE_PREPARES=1` in the server's environment: 27 of 27 PASS, and 10 sessions were stored in lms.app_session (none in files).
- [x] `smoke.php` with the defaults (file sessions, normal prepares): 27 of 27 PASS, so nothing changed on this PC.
- [x] `APP_ENV=prod` with a 12-character DEMO_PASSWORD: seed_passwords.php refuses it before touching the database.

## Step 20, hosting part (Nov 22, 2026)

The hosted path was tested against a local stand-in for Supabase: a separate database owned by an admin role that is **not** a superuser (LOGIN, CREATEROLE, CREATEDB, with only the ADMIN option on the lms roles, as a hosted provider gives). It was removed afterwards. No cloud account was touched.

- [x] `reset.ps1 -Hosted` with `DATABASE_ADMIN_URL` pointing at the stand-in: all nine SQL files and the office admin password ran without a superuser.
- [x] The stand-in's database-wide settings and PUBLIC's CONNECT stayed untouched (a shared database); PUBLIC cannot use the `lms` schema.
- [x] `smoke.php --session=db` against it with `APP_ENV=prod`, `DB_EMULATE_PREPARES=1` and `LOG_TO_STDERR=1`: 27 of 27 PASS.
- [x] Locally, unchanged: 26 of 26 database checks and a normal reset.
- [ ] The container (`Dockerfile.vercel`) was not built here: Docker is not installed. Vercel's first deploy is its first build.
