# Backup and restore

Run these in PowerShell from the `library` folder. They use the PostgreSQL 18 tools in `C:\Program Files\PostgreSQL\18\bin`. Each command asks for the `postgres` password; to skip the prompt for one window, run `$env:PGPASSWORD = "<the postgres password>"` first (do not save it in a script).

## Back up

```powershell
& "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe" -h localhost -U postgres -d lms -F c -f "lms-$(Get-Date -Format yyyy-MM-dd).backup"
```

This writes one compressed file, for example `lms-2026-11-21.backup`, with every table, view, routine, trigger and grant in the lms database. Keep backups outside the project folder (they contain personal data and password hashes), and never commit them.

The roles (lms_owner, lms_app, lms_report) belong to the whole server, not to the database. Save them too when you move to another server:

```powershell
& "C:\Program Files\PostgreSQL\18\bin\pg_dumpall.exe" -h localhost -U postgres --roles-only -f "roles-$(Get-Date -Format yyyy-MM-dd).sql"
```

## Restore

Restore into a new, empty database first and check it before you replace anything:

```powershell
& "C:\Program Files\PostgreSQL\18\bin\createdb.exe" -h localhost -U postgres -O lms_owner lms_restored
& "C:\Program Files\PostgreSQL\18\bin\pg_restore.exe" -h localhost -U postgres -d lms_restored --exit-on-error "lms-2026-11-21.backup"
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -h localhost -U postgres -d lms_restored -c "SELECT * FROM lms.v_dashboard_stats;"
```

To put the backup back into `lms` itself (this replaces what is there now):

```powershell
& "C:\Program Files\PostgreSQL\18\bin\pg_restore.exe" -h localhost -U postgres -d lms --clean --if-exists --exit-on-error "lms-2026-11-21.backup"
```

If the roles are missing on a new server, run the roles file first: `psql -h localhost -U postgres -d postgres -f roles-2026-11-21.sql`, then set the lms_app password again (`ALTER ROLE lms_app PASSWORD '...';` with the value of `APP_DB_PASS` in `.env`).

## Check that it worked

Open http://localhost:8000/health.php (it says OK) and sign in. The demo data can always be rebuilt from scratch with `database\reset.ps1`, but a backup is the only way to keep real loans, fines and messages.
