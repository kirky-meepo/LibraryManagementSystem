# Database hardening

How the LibraryInventorySystem follows the five layers and the patch-management note from the Group 7 hardening presentation. Each layer says what this project does, the exact lines to change on Windows, and how to check it.

The server here is PostgreSQL 18 on Windows, installed in `C:\Program Files\PostgreSQL\18`. Its configuration files are in the data directory:

- `C:\Program Files\PostgreSQL\18\data\postgresql.conf`
- `C:\Program Files\PostgreSQL\18\data\pg_hba.conf`

The project scripts never edit these files. Make the changes yourself: open Notepad **as administrator**, edit the file, save it, then restart or reload as noted. Take a copy of each file first.

## What was found on this PC (Nov 20, 2026)

| Setting | Found | Target |
| --- | --- | --- |
| listen_addresses | `*` (every network card) | `localhost` |
| port | 5432 | 5432 |
| pg_hba.conf rules | all `scram-sha-256`, no `trust` | keep |
| password_encryption | scram-sha-256 | keep |
| log_connections / log_disconnections | off | on |
| log_statement | none | ddl |
| logging_collector | on | keep |
| Extensions in the lms database | plpgsql only | keep |
| Login roles | postgres (superuser), lms_app | keep; the site uses only lms_app |

## Layer 1: Network

**What it does here.** The site and PostgreSQL run on the same PC, so the database should not accept connections from any other machine.

**Change** in `postgresql.conf` (search for each name and replace the line):

```conf
listen_addresses = 'localhost'      # was '*'
port = 5432
```

`listen_addresses` needs a restart. In PowerShell **as administrator**:

```powershell
Restart-Service postgresql-x64-18
```

Also check that Windows Firewall has no rule that lets other machines reach port 5432:

```powershell
Get-NetFirewallRule -Enabled True -Direction Inbound | Where-Object DisplayName -like '*Postgre*' | Format-Table DisplayName, Action, Profile
```

If a rule shows Allow, disable it: `Disable-NetFirewallRule -DisplayName "<the name shown>"`.

**Check.** `psql -U postgres -c "SHOW listen_addresses;"` prints `localhost`.

## Layer 2: Authentication

**What it does here.** Every connection needs a password, checked with SCRAM-SHA-256. There is no `trust` rule, so nobody connects without a password, not even from this PC.

This PC already has the right rules. The active lines in `pg_hba.conf` should read:

```conf
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             all                                     scram-sha-256
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
local   replication     all                                     scram-sha-256
host    replication     all             127.0.0.1/32            scram-sha-256
host    replication     all             ::1/128                 scram-sha-256
```

In `postgresql.conf` make the default explicit:

```conf
password_encryption = scram-sha-256
```

After editing `pg_hba.conf`, reload with `psql -U postgres -c "SELECT pg_reload_conf();"`.

Passwords in this project:

- The `postgres` superuser password lives only in `.env` (PGPASSWORD), which Git ignores. The reset and test scripts use it; the site never does.
- The `lms_app` password is `APP_DB_PASS` in `.env`. `database/05_roles.sql` sets it with statement logging switched off for that one command, so it never reaches the server log.
- Site passwords are hashed with PHP `password_hash` (bcrypt) and checked with `password_verify`. Only the hash ever reaches the database, and the routines refuse any value that is not password_hash() output.
- The sign-in rules live in the database (`database/04b_auth.sql`): `fn_login_lookup` applies the lock-out (5 failed sign-ins in 15 minutes for the same email or IP lock sign-in for 15 minutes) and returns no account data at all while locked; `fn_record_login` writes `login_attempt` and the audit row.
- **Create an account** makes students and teachers only, never staff (`fn_register_member`). One email belongs to one person across students, teachers and staff. At most 5 accounts per IP address per hour.
- **Forgot your password** works for every active account. PHP makes a 32-byte random token; the database stores only its sha256 (`password_reset.token_hash`), so a copy of the database cannot be used to reset anyone. A link works for 30 minutes and only once, and using it kills every other open link of that account. At most 3 open links per account and 5 per IP address per hour. The page gives the same answer for every email, so it cannot be used to find out who has an account. The link is built from `APP_URL`, never from the request's Host header, and the reset page sends `Referrer-Policy: no-referrer` and `Cache-Control: no-store`. After a reset the owner gets a "Your password was changed" email.

**Check.**

```sql
SELECT rolname, left(rolpassword, 14) AS starts_with FROM pg_authid WHERE rolcanlogin;
-- every row starts with SCRAM-SHA-256$
SELECT line_number, type, database, user_name, address, auth_method FROM pg_hba_file_rules;
-- no row says trust
```

## Layer 3: Authorization

**What it does here.** Least privilege with three roles from `database/05_roles.sql`:

| Role | Can log in | What it may do |
| --- | --- | --- |
| lms_owner | No | Owns every table, view and routine in the lms database. Only used through the superuser that runs the SQL files. |
| lms_app | Yes (the PHP site) | SELECT on tables and views, but on staff, student and teacher only the columns without `password_hash`. UPDATE only on `library_setting.setting_value`. No INSERT or UPDATE on people or books: every change goes through a routine. EXECUTE on the routines. No DELETE anywhere, no DDL, not a superuser. |
| lms_report | No (until you enable it) | SELECT on the ten views only. |

Every state change (copies, requests, loans, returns, fines, messages, deactivation, delete) goes through a SECURITY DEFINER routine owned by lms_owner. The app role cannot even update `book.copies_available`, `transaction` or `fine` directly, so a bug in a PHP page cannot break the library rules.

The copy limit is the same idea at column level: lms_app may INSERT into `book` only the columns `isbn, title, author, category, copies_total, copies_available, replacement_cost, added_by_id, date_added`, so a new title always starts with the default limit of 5, and it has no UPDATE on `copy_limit`. Only `fn_set_copy_limit` changes it, and that routine checks the caller is an admin. `ck_book_within_limit` refuses any total above the limit even if a routine has a bug.

`REVOKE ALL ... FROM PUBLIC` is applied to the database, the `public` and `lms` schemas, every table, sequence and routine, and to future routines through default privileges.

To let a reporting tool sign in as lms_report:

```sql
ALTER ROLE lms_report LOGIN PASSWORD 'a-long-random-password';
```

The app role cannot read `password_reset` at all, cannot insert into `login_attempt` (only `fn_record_login` does), and cannot run the internal helper `fn_auth_store_hash`: a new hash is stored only through `fn_set_password` (your own account), `fn_staff_reset_password` (the Manage Users rules) or `fn_reset_password` (a valid link).

Since Step 17 the site reads and writes people, books, loans and fines only through the Create and Read functions in `database/04c_crud.sql` (and the views), so the app role needs no direct write on those tables at all, and no function except `fn_login_lookup` ever returns a password hash.

**Check.** `database\run_tests.ps1` prints PASS for checks 11 to 13, 18 and 23: the app role cannot change copies, delete loans, deactivate people, create tables, read reset links, store a hash directly, read or write `password_hash`, or insert and update people and books; the report role reads views but not tables; PUBLIC has no access.

## Layer 4: Attack surface

**What it does here.**

- **Extensions.** The lms database has only `plpgsql`, which PostgreSQL needs for the routines. No other extension is installed or needed. Check with `\dx` in psql.
- **Sample databases.** The project creates only `lms` (and `lms_test` while tests run). Run `\l` in psql, and drop any sample or practice database you no longer need with `DROP DATABASE name;`. Never drop `postgres`, `template0` or `template1`.
- **Default accounts.** The only login roles are `postgres` and `lms_app`. The site never connects as `postgres` or any superuser: `.env` sets `DB_USER=lms_app`. `lms_owner` and `lms_report` cannot log in.
- **Superuser password.** The `postgres` password was reset during setup to a temporary value. Change it to a long private one, then update `PGPASSWORD` in `.env`:

  ```sql
  \password postgres
  ```

  (`\password` sends the password already hashed, so it never appears in any log.)
- **Web side.** Only `public/` is served. `src/`, `database/`, `.env` and `storage/logs/` sit outside the web root.

**Check.** `SELECT rolname, rolsuper FROM pg_roles WHERE rolcanlogin;` shows postgres (superuser) and lms_app (not superuser), nothing else.

## Layer 5: Host files and logging

**Data directory permissions.** The data directory should be readable only by the PostgreSQL service account, SYSTEM and Administrators. Look at it with:

```powershell
icacls "C:\Program Files\PostgreSQL\18\data"
```

If `Users`, `Authenticated Users` or `Everyone` appears, remove it (PowerShell as administrator):

```powershell
icacls "C:\Program Files\PostgreSQL\18\data" /remove:g "Users" "Authenticated Users" "Everyone"
```

**Server logging.** Add or change these lines in `postgresql.conf`:

```conf
logging_collector = on                 # already on here
log_connections = on                   # PostgreSQL 18 reads "on" as receipt,authentication,authorization
log_disconnections = on
log_statement = 'ddl'                  # every CREATE, ALTER, DROP is written to the log
log_line_prefix = '%m [%p] %q%u@%d '   # time, process, user@database
```

These take effect after `psql -U postgres -c "SELECT pg_reload_conf();"`. The logs are written to `C:\Program Files\PostgreSQL\18\data\log\`.

**Our own audit trail.** The `audit_log` table records every insert, update and delete on staff, student, teacher, book, book_request, transaction, fine and library_setting: who did it (staff, student, teacher or system, with the id), what changed (old and new values) and when. Password hashes are never stored, only `password_changed: true`. Admins read it on the Activity page.

Signing in and out, sign-ups and password changes are audited by the routines themselves (`login`, `logout`, `sign_up`, `password_changed`, `password_reset_by_staff`, `password_reset_requested`, `password_reset`). None of these rows holds a password, a hash or a token. `password_reset` has no audit trigger on purpose: it would copy the token hash.

**Site logs.** With `APP_ENV=prod`, the site shows a short error page and writes the details to `storage/logs/` (outside `public/`), never to the browser.

**Email and the API key.**

- `MAIL_DRIVER=outbox` (the default) writes every email, including live reset links, to `storage/outbox/`. That folder is outside `public/`, is not committed (`.gitignore`), and should be emptied after a demo. The Demo mode box on Forgot your password appears only when `APP_ENV=dev` and the outbox driver is on and a link was really made.
- `MAIL_DRIVER=brevo` sends through Brevo over HTTPS with certificate checks on. `BREVO_API_KEY` lives only in `.env` (never committed, never in `.env.example`). If Brevo fails, the log line says only the HTTP status: never the key, the token or the address. The command-line tools never print the key either.
- Use a Brevo key with **Restricted** access (Transactional email only). If a key is ever pasted somewhere public or into a chat, delete it in Brevo and make a new one.

**On a public host.** Set the secrets only as the host's environment variables (they win over `.env`, which is never uploaded). `APP_ENV=prod` makes `seed_passwords.php` refuse an `OFFICE_ADMIN_PASSWORD` shorter than 14 characters. `SESSION_DRIVER=db` keeps sessions in `app_session`, which only the `fn_session_*` routines can touch. The session cookie is Secure when the request is HTTPS or the proxy says `X-Forwarded-Proto: https`. Only with `TRUST_PROXY=1` does the lock-out trust `X-Real-IP` / `X-Forwarded-For` (Vercel overwrites both); otherwise a visitor could fake them. `LOG_TO_STDERR=1` sends error details to the platform's log instead of a disk file. `DB_SSLMODE=require` encrypts the database connection.

**On a shared hosted database (Supabase, Neon).** The provider's admin is not a superuser and the database is shared with the provider's own services, so the setup does not revoke database-wide access or change database-wide settings there. It closes only the `lms` schema: PUBLIC cannot use it, its tables or its routines, and `lms_app` carries its own search_path and timezone. Supabase's data API exposes only the `public` schema, so the library's tables are not reachable through it.

**Check.**

```sql
SHOW log_connections; SHOW log_disconnections; SHOW log_statement;
SELECT actor_type, actor_id, action, table_name, created_at FROM lms.audit_log ORDER BY audit_id DESC LIMIT 10;
```

## Cross-cutting: patch management

PostgreSQL releases a minor version (18.x) about every three months with security and bug fixes. Minor updates keep the data format, so no dump and restore is needed.

1. Check what is installed:

   ```powershell
   psql -U postgres -c "SELECT version();"
   php -v
   ```

   This PC runs PostgreSQL 18.6 and PHP 8.4.27 (Nov 20, 2026).
2. Compare with the latest release at https://www.postgresql.org/support/versioning/ and https://www.php.net/downloads.
3. Back up first (see `database/backup.md`).
4. Update PostgreSQL with the EDB Windows installer for the newest 18.x (or Stack Builder from the Start menu). It stops the service, replaces the program files and starts it again. Update PHP with `winget upgrade PHP.PHP.8.4`.
5. Run `database\run_tests.ps1` and open `/health.php` to confirm everything still works.

Check once a month, and right away when a security release is announced.

## Before hardening and after hardening

| Area | Before hardening | After hardening |
| --- | --- | --- |
| Network | Listens on every network card (`*`) | Listens on `localhost` only; no firewall rule for 5432 |
| Authentication | SCRAM-SHA-256 rules, but the superuser password was a temporary value | SCRAM-SHA-256 everywhere, no `trust`, private superuser password, app password only in `.env`, sign-in lockout on the site |
| Authorization | Everything owned by `postgres`; PUBLIC can run functions by default | `lms_owner` owns everything, `lms_app` gets the minimum, `lms_report` reads views only, PUBLIC revoked |
| Attack surface | The site could have used the superuser | Site uses `lms_app` (not a superuser); only `plpgsql`; only needed login roles; secrets outside `public/` |
| Host files and logging | No connection or DDL logging | Data directory limited to the service account; connections, disconnections and DDL logged; `audit_log` records every change with its actor |
| Patch management | No routine | Version check, backup, minor update, tests, once a month |
