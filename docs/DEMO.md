# Demo script

A short tour of the LibraryInventorySystem on a freshly reset library. There are no sample people: the office admin is the only account, and you make the rest as you go.

## Before you start

1. In PowerShell, in the `library` folder: `powershell -ExecutionPolicy Bypass -File database\reset.ps1`. It ends with "Reset finished: lms".
2. Start the site: `php -S localhost:8000 -t public` and open http://localhost:8000.
3. Have `OFFICE_ADMIN_EMAIL` and `OFFICE_ADMIN_PASSWORD` from `.env` ready.

What a reset gives you: 229 titles in 22 categories (606 copies, all on the shelf), the message presets and settings, and one account, the office admin (Library Management Office).

## 1. Sign in as the office admin

Choose **Librarian/Admin**, type the office admin email and password, **Sign in**. The dashboard cards show 229 titles and nothing on loan yet.

## 2. Add people

- **Manage Users → Add user → Student**: fill in a name, email, course and year level. The pop-up shows a temporary password once; the student must choose their own at the first sign-in.
- Add a **Teacher** the same way, and a **Staff** member with the position Librarian.
- Or sign out and use **Create an account** on the sign-in page (Member side): students and teachers can sign up themselves.

## 3. A request, from asking to returning

1. Sign in as the student. **Catalog**: search, filter by category, and **Request this book** on a title.
2. Sign in as the office admin (or the librarian). **Requests**: **Approve** opens the pop-up with the pickup day, time and a ready-made message; **Approve and send message**.
3. As the student, **Messages** shows the approval with a pickup card.
4. As staff, **Issue Book** → From an approved request → **Issue book**.
5. **Issued Books** → **Mark returned**. A late, damaged or lost return adds a fine that **Fines** collects (**Mark paid**).

## 4. The rules the database keeps

- A student has at most 5 loans and open requests (a teacher 8); the 6th request is refused in plain words.
- Someone with an unpaid fine or an overdue book cannot borrow.
- A title whose copies are all out can be requested but not approved until a copy comes back; a title whose copies are all lost cannot be requested.
- Five wrong passwords lock sign-in for 15 minutes.
- A librarian opening Settings or Activity gets the 403 page.

## 5. Behind the scenes

- **Manage Users → Show → Staff** has an **Activity** column: Active while someone is signed in, Inactive after they sign out or sit idle (and for an account that never signed in).
- **Status and Reports** and its CSV downloads; **Activity** (admin) lists who did what, in plain words.
- In pgAdmin: `SELECT * FROM lms.audit_log ORDER BY audit_id DESC LIMIT 10;` and `SELECT * FROM lms.v_dashboard_stats;`.
- `powershell -ExecutionPolicy Bypass -File database\run_tests.ps1`: 27 PASS lines. `php database\tools\smoke.php` right after a reset runs the whole flow above through the real pages (27 checks).

Run `database\reset.ps1` again to start over.
