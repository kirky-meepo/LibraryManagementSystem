# LibraryInventorySystem

A web system for a school library, built by a group of people at Cebu Technological University.

Librarians and admins manage books, students, teachers and staff, approve or reject book requests with a message to the borrower, issue and return books, and collect fines. Students and teachers browse the catalog, request books, read the library's messages and see what they have borrowed and owe. The library rules (limits, reservations, fines, who may do what) live in PostgreSQL routines, so no page can break them, and every change is written to an audit trail.

Built with PHP 8.2+ (no framework), PostgreSQL 15+, HTML, CSS and a little plain JavaScript.

## Requirements (Windows)

- PHP 8.2 or newer with the `pdo_pgsql` and `pgsql` extensions switched on
- PostgreSQL 15 or newer (pgAdmin 4 is useful for looking at the data)
- Git (optional, to clone the project)

## Set up in 6 steps

1. **Install PHP and PostgreSQL.** In PowerShell: `winget install PHP.PHP.8.4` and `winget install PostgreSQL.PostgreSQL.18` (or use the installers from php.net and postgresql.org). Remember the `postgres` password you choose. XAMPP or Laragon also work for PHP.
2. **Switch on the database driver.** Open the `php.ini` next to `php.exe` (copy `php.ini-development` to `php.ini` if there is none) and remove the `;` in front of `extension=pdo_pgsql`, `extension=pgsql` and `extension=mbstring`. Check with `php -m`: both pgsql lines must be listed.
3. **Get the project** into a folder named `library` (`git clone <repository> library`, or copy the folder).
4. **Create `.env`.** In the `library` folder, copy `.env.example` to `.env` and fill it in:
   - `PGPASSWORD`: the `postgres` password from step 1
   - `APP_DB_PASS` and `DB_PASS`: the same long random value (letters and digits, at least 12)
   - `OFFICE_ADMIN_EMAIL` and `OFFICE_ADMIN_PASSWORD`: the only account, the office admin (password at least 8 characters, 14 when `APP_ENV=prod`)
   - leave `DB_USER=lms_app`; `.env` is never committed
5. **Build the database:** `powershell -ExecutionPolicy Bypass -File database\reset.ps1` (Git Bash: `bash database/reset.sh`). It creates the `lms` database, the tables, routines, views, audit triggers and the three roles, the 229-title catalog and the office admin, the only account. Run it again any time to start fresh (it deletes all data).
6. **Start the site:** `php -S localhost:8000 -t public`, then open http://localhost:8000. (With XAMPP or Laragon, point the site at the `public` folder.)

Check it: `php database\tools\smoke.php` right after `database\reset.ps1` signs in as the office admin, signs up, signs in, requests, approves, issues, returns, resets a password and locks sign-in through the real pages, and prints PASS or FAIL for each (run `database\reset.ps1` again afterwards). http://localhost:8000/health.php says OK, and `powershell -ExecutionPolicy Bypass -File database\run_tests.ps1` prints 27 PASS lines.

## The account

A reset makes one account, the office admin:

| Email | Role | Password |
| --- | --- | --- |
| `OFFICE_ADMIN_EMAIL` in `.env` | Admin, named Library Management Office: everything, including staff accounts, Settings, Activity, copy limits and deleting users | `OFFICE_ADMIN_PASSWORD` in `.env` |

### Demo accounts

Every copy of this project starts with two demo accounts on its own computer: `database\reset.ps1` (or `reset.sh`) creates them. Open the sign-in page, choose **Librarian/Admin**, and use:

| Role | Email | Password | What it can do |
| --- | --- | --- | --- |
| Admin | `demo.admin@example.com` | `REDACTED` | Everything: staff accounts, Settings, Activity, copy limits, deleting users |
| Librarian | `demo.librarian@example.com` | `REDACTED` | The library desk: books, users, requests, issue and return, fines, reports, messages (no staff accounts, Settings or deleting) |

These passwords are public on purpose, so they only belong on your own computer. A hosted site (Vercel, Supabase) never gets them: `reset.ps1 -Hosted` skips them, and the author's online copy has its own private logins. Do not run `demo_accounts.sql` on a public site. Use them only to look around. Do not enter real personal data while signed in with them. There are no sample students or teachers: make your own with **Create an account** (Member side) or in Manage Users. The library starts with 229 titles in 22 categories (606 copies, all on the shelf): 12 books from `06_seed.sql` and 217 real college textbooks and references from `07_seed_catalog.sql`. `reset.ps1 -NoSeed` (or `NO_SEED=1 bash database/reset.sh`) builds an empty database without books or the office admin.

Anyone can make a student or teacher account with **Create an account** on the sign-in page (Member side). Staff accounts are only made by an admin in Manage Users.

## Folder map

```
library/
  README.md          this file
  .env.example       settings to copy into .env (never commit .env)
  docs/
    SPEC.md          the database: 15 tables, routines, views, roles, message presets
    ERD.md           entity relationship diagram (Mermaid)
    HARDENING.md     database hardening: five layers, before and after
    TESTS.md         tests to run by hand, all ticked
    DEMO.md          a 10-minute demo script with exact clicks
    MEMBER_TASKS.md  who owns which table: its Create and Read functions and pages
  database/
    01_schema.sql … 07_seed_catalog.sql   tables, routines, views, audit, sign-in routines (04b_auth.sql),
                                  Create and Read functions (04c_crud.sql), roles, demo data (06 and 07, run in order)
    tests.sql        database checks (PASS/FAIL), run with run_tests.ps1 on a scratch copy
    reset.ps1 / reset.sh          rebuild the database
    run_tests.ps1 / run_tests.sh  rebuild a scratch copy (lms_test) and run tests.sql
    seed_passwords.php            gives the office admin OFFICE_ADMIN_PASSWORD
    demo_accounts.sql             the two shared demo accounts (Demo Admin, Demo Librarian), run by hand
    tools/test_mail.php           send one test email through Brevo
    tools/mail_status.php         what Brevo did with a message (delivered, bounced, ...)
    tools/verify_isbns.php        check every ISBN's check digit and title with Open Library
    tools/smoke.php               end-to-end test through HTTP (starts its own php -S with the outbox)
    backup.md        pg_dump and restore commands
  src/               PHP code outside the web root: config, database, sign-in, CSRF, helpers, page parts
  Dockerfile.vercel, deploy/      the container Vercel builds (docs/DEPLOY.md)
  public/            the web root
    login.php, logout.php, signup.php, forgot_password.php, reset_password.php, change_password.php, health.php, styleguide.php
    librarian/       staff pages (dashboard, books, users, requests, issue, issued, fines, status, export, messages, profile, settings, activity)
    member/          student and teacher pages (home, catalog, requests, messages, borrowed, profile)
    api/             JSON endpoints for the pop-ups
    assets/          css, js, self-hosted fonts, book drawings
  storage/logs/      error details when APP_ENV=prod (not committed)
  storage/outbox/    emails written as text files when MAIL_DRIVER=outbox (not committed)
```

## Security in short

Every query uses prepared statements; every page checks the role; every form carries a CSRF token; output is escaped; passwords use `password_hash`; five failed sign-ins lock sign-in for 15 minutes; the site connects as `lms_app`, which can only change state through the database routines. Signing in, signing up, signing out and password resets also run through routines (`database/04b_auth.sql`): reset links are random, stored only as a sha256, work once for 30 minutes, and the page never says whether an email has an account. See docs/HARDENING.md for the server side.

## Email: outbox or Brevo

`MAIL_DRIVER` in `.env` decides where email goes (`src/mail.php`):

- `outbox` (the default): each message is written to `storage/outbox/<time>-<random>.txt`. Nothing leaves the PC. On a developer PC (`APP_ENV=dev`), Forgot your password also shows a **Demo mode** box with the reset link and the file name.
- `brevo`: the message is sent through Brevo's API. If Brevo refuses it or cannot be reached, the message is written to the outbox instead, a one-line note with only the HTTP status goes to `storage/logs/`, and the page still shows its normal answer.

A real environment variable wins over `.env`, so `MAIL_DRIVER=outbox` can be forced for a test without editing the file.

## Real password-reset email

The Brevo account, sender and key for this project already exist. To set it up again on another account:

1. Create a free account at brevo.com.
2. Go to **Senders, Domains & Dedicated IPs** and open the **Senders** tab (not Domains: that tab only accepts a domain you own). Add a sender with a mailbox you can open, then type the 6-digit code Brevo emails to it.
3. Go to **SMTP & API**, **API keys**, and generate a key with **Access** set to **Restricted** and **Transactional email** set to **All**. Copy it; Brevo shows it once.
4. In `.env` set `MAIL_FROM` (the verified sender), `MAIL_FROM_NAME` and `BREVO_API_KEY`. Keep `MAIL_DRIVER=outbox` for now. Never commit `.env` and never paste the key anywhere else; if it leaks, delete it in Brevo and make a new one.
5. Run `php database\tools\test_mail.php you@example.com`. It always uses Brevo and prints `OK  HTTP 201  messageId <...>`, or a short error (never the key). A 201 means Brevo accepted the message, not that it was delivered.
6. Run `php database\tools\mail_status.php "<messageId>"` (or the address) to see what happened: requests, delivered, deferred, bounce, blocked. A 401 or 403 there means the key cannot read the email logs.
7. Set `MAIL_DRIVER=brevo`, restart `php -S`, and try **Forgot your password?** on the sign-in page.

**Why the email may come from a Brevo address or land in Junk.** Gmail, Outlook and other free-mail providers publish rules (DMARC) that forbid other services from sending in their name. So Brevo replaces a free-mail sender with its own sending address, and receiving servers treat the message with suspicion. That is expected with a free-mail sender, so check Junk. The real fix is a domain you own: add it in Brevo under Domains, publish the DKIM and DMARC records Brevo gives you, and then only `MAIL_FROM` changes.

**If PHP on Windows reports a certificate problem.** `src/mail.php` keeps certificate checks on and, on Windows, uses the Windows certificate store (`CURLSSLOPT_NATIVE_CA`). If that is not enough, download `cacert.pem` from https://curl.se/docs/caextract.html, save it next to `php.exe` (for example `C:\php\extras\ssl\cacert.pem`), and in `php.ini` set both `curl.cainfo` and `openssl.cafile` to that path. Never switch verification off.

## Put it online

To let anyone use the library any time without your PC, follow docs/DEPLOY.md: the data goes to Supabase (PostgreSQL), the website to Vercel (a container built from `Dockerfile.vercel`). `database\reset.ps1 -Hosted` builds the online database from your PC once.

## Lost admin password

1. On the sign-in page, choose **Librarian/Admin**, click **Forgot your password?** and type the admin email. Reset links work for staff too, as long as you can open that mailbox (with `MAIL_DRIVER=outbox`, the link is in `storage/outbox/`).
2. Or sign in as another admin (if you made one in Manage Users), open Manage Users, Staff, and use **Reset password** on the locked-out admin. They choose a new password at the next sign-in.
3. You can also rebuild everything with `database\reset.ps1`, which gives the office admin `OFFICE_ADMIN_PASSWORD` again (this deletes all data).
