# Put the library online (Supabase + Vercel)

After these steps anyone can open the site at `https://<your-project>.vercel.app`, any time, without your PC.

- **Supabase** holds the data (PostgreSQL). It cannot run PHP, so it is only the database.
- **Vercel** runs the website as a container built from `Dockerfile.vercel`.

Both have free plans. Budget about an hour the first time.

## Before you start

- The project is on GitHub, and the repository's top folder is the `library` folder (the one with `Dockerfile.vercel`). `.env` is never pushed (`.gitignore` keeps it out).
- `.env` on your PC has `APP_DB_PASS`, `OFFICE_ADMIN_EMAIL` and `OFFICE_ADMIN_PASSWORD`, and `OFFICE_ADMIN_PASSWORD` has **at least 14 characters** (the hosted setup refuses a shorter one).
- A Supabase account and a Vercel account (both can sign in with GitHub).
- For real password-reset emails: a **new** restricted Brevo key (see "Brevo on Vercel" below).

## 1. Create the database on Supabase

1. Go to supabase.com → **New project**.
   - **Name:** `library`.
   - **Database password:** click **Generate a password** and save it in a password manager. Letters and digits only make the next step easier.
   - **Region:** click **Need a specific region?** and choose Southeast Asia (Singapore), the closest to the Philippines. ("Auto" may pick another region, such as Tokyo; that also works, as long as Vercel uses the same one in step 3.)
   - **Plan:** Free. Click **Create new project** and wait until it is ready.
2. Click **Connect** (top of the project page) → **Connection string** → **Session pooler**. Copy the URI. It looks like:
   `postgresql://postgres.abcdefghijklmnop:[YOUR-PASSWORD]@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres`
   Put your database password in place of `[YOUR-PASSWORD]`. If the password has symbols, write `@` as `%40`, `!` as `%21`, `#` as `%23`.
   (The Session pooler works from any home or school network. The "Direct connection" needs IPv6, which many networks lack.)
3. In the same dialog, choose **Transaction pooler** and note its host and port (port **6543**) and the project ref (the `abcdefghijklmnop` part). The website uses these.

## 2. Build the tables, routines, books and the office admin (from your PC, once)

In PowerShell, in the `library` folder. The connection string stays in this window only:

```powershell
$env:DATABASE_ADMIN_URL = 'postgresql://postgres.abcdefghijklmnop:YOUR-PASSWORD@aws-0-ap-southeast-1.pooler.supabase.com:5432/postgres?sslmode=require'
powershell -ExecutionPolicy Bypass -File database\reset.ps1 -Hosted
Remove-Item Env:DATABASE_ADMIN_URL
```

It ends with "office admin password set" and "Reset finished: postgres". It made the schema `lms` (the tables, routines, views, roles and audit trail), the 229 books, and the office admin with `OFFICE_ADMIN_PASSWORD`. The roles are `lms_owner`, `lms_app` (the website's login, password `APP_DB_PASS`) and `lms_report`. The database itself and Supabase's own schemas are not touched.

**Run it only this once.** Running it again rebuilds the schema and **deletes all online data** (accounts, loans, fines).

## 3. Create the website on Vercel

1. Go to vercel.com → **Add New…** → **Project** → **Import** your GitHub repository.
2. **Framework Preset:** Vercel reads `Dockerfile.vercel` and uses its Container preset. If it shows another preset, open the dropdown and choose the container option (or "Other"). **Root Directory:** the folder that holds `Dockerfile.vercel` (leave it empty when that is the repository's top folder).
3. Open **Environment Variables** and add these before you click Deploy:

   | Name | Value | Secret |
   | --- | --- | --- |
   | `APP_ENV` | `prod` | |
   | `APP_URL` | `https://<your-project>.vercel.app` (fix it after the first deploy) | |
   | `DB_HOST` | the Transaction pooler host, for example `aws-0-ap-southeast-1.pooler.supabase.com` | |
   | `DB_PORT` | `6543` | |
   | `DB_NAME` | `postgres` | |
   | `DB_USER` | `lms_app.<project-ref>`, for example `lms_app.abcdefghijklmnop` | |
   | `DB_PASS` | the `APP_DB_PASS` value from your `.env` | yes |
   | `DB_SSLMODE` | `require` | |
   | `DB_EMULATE_PREPARES` | `1` (the Transaction pooler keeps no prepared statements) | |
   | `SESSION_DRIVER` | `db` (Vercel has no lasting disk, so sessions live in the database) | |
   | `TRUST_PROXY` | `1` (the sign-in lock-out then counts each visitor's real address) | |
   | `LOG_TO_STDERR` | `1` (errors appear under Vercel → your project → Logs) | |
   | `MAIL_DRIVER` | `brevo` | |
   | `BREVO_API_KEY` | the **new** restricted Brevo key | yes |
   | `MAIL_FROM` | your verified Brevo sender | |
   | `MAIL_FROM_NAME` | `Library Management Office` | |

   Do **not** add `PGUSER`, `PGPASSWORD`, `APP_DB_PASS`, `OFFICE_ADMIN_EMAIL`, `OFFICE_ADMIN_PASSWORD` or `DATABASE_ADMIN_URL`: the website never needs them.
4. Click **Deploy**. The first build takes a few minutes (it builds the container).
5. **Settings → Functions → Function Region:** choose the same region as your Supabase project, so every page is fast: Singapore = **sin1**, Tokyo = **hnd1** (the pooler host shows it: `ap-southeast-1` is Singapore, `ap-northeast-1` is Tokyo).
6. Copy the site's address (for example `https://library-abc.vercel.app`), set `APP_URL` to it under **Settings → Environment Variables**, then **Deployments → … → Redeploy**. Emailed reset links use `APP_URL`.

## 4. Check it

- `https://<your-project>.vercel.app/health.php` says `OK`.
- Sign in with **Librarian/Admin** as the office admin.
- Search the **Catalog**.
- **Manage Users → Add user → Staff → Librarian** for a groupmate. They get a temporary password once and choose their own at the first sign-in.
- On a phone: **Create an account** as a student, request a book. As the office admin: approve it in **Requests**, then **Issue Book**.
- **Forgot your password?** with an address you can open: the email arrives (check Junk too).
- **Sign out.**

## Demo accounts

`database/demo_accounts.sql` adds two shared accounts, and nothing else:

| Account | Email | Role |
| --- | --- | --- |
| Demo Admin | `demo.admin@example.com` | Admin: everything, including staff, Settings, Activity and deleting users |
| Demo Librarian | `demo.librarian@example.com` | Librarian: books, users, requests, loans, fines, reports, messages |

The passwords are published on purpose in the README ("Demo accounts"), because these are demo accounts; the file itself holds only their bcrypt hashes. Running it again creates a missing account and puts both passwords back, which is handy if someone changed one. A reset never runs it. It needs only the website's own login (`lms_app`), not the database admin. In PowerShell, in the `library` folder:

```powershell
$env:PGPASSWORD = 'the APP_DB_PASS value from your .env'
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" "host=<session pooler host> port=5432 dbname=postgres user=lms_app.<project-ref> sslmode=require" -f database\demo_accounts.sql
Remove-Item Env:PGPASSWORD
```

It ends with a small table listing both accounts as active. On this PC: `& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h localhost -d lms -f database\demo_accounts.sql`.

Anyone with the Demo Admin password can add or delete users and change settings. To take access away, sign in as the office admin and use **Manage Users → Staff → Deactivate** (or **Reset password**).

## Brevo on Vercel

- Make a **new** key for the deployment instead of reusing the local one: Brevo → **SMTP & API** → **API keys** → **Generate a new API key**, Access **Restricted**, **Transactional email: All**, nothing else. Put it only in Vercel's `BREVO_API_KEY`.
- Delete the old key that was pasted into a chat.
- Keys expire after 1 year, or after 90 days without use. If reset emails stop, make a new key.
- A free-mail sender (Gmail, Outlook) is replaced by Brevo's own sending address, so mail can come from a Brevo address or land in Junk. A domain you own, authenticated with DKIM and DMARC in Brevo, fixes that; then only `MAIL_FROM` changes.
- The free plan sends 300 emails a day. Brevo uses HTTPS, so no SMTP settings are needed.

## Good to know

- **Pausing:** a free Supabase project pauses after 7 days with no visits. The site then shows "The database is not reachable right now". Open the Supabase dashboard and click **Restore project**; it takes a few minutes.
- **First visit after a quiet time** takes a few seconds while Vercel starts the container (a "cold start").
- **Connections:** the website uses the Transaction pooler (port 6543), so many visitors share a few database connections.
- **No disk online:** `storage/outbox` and `storage/logs` do not exist on Vercel. Emails go through Brevo; errors go to Vercel's Logs.
- **Updates:** push to GitHub and Vercel rebuilds the site by itself. A change to the database files needs step 2 again, which **wipes the online data**. Before real use that is fine; after it, make a backup first (Supabase → **Database → Backups**).
- **Supabase's data API** only shows the `public` schema; the library lives in the `lms` schema, so it is not exposed there. Leave Supabase → **Settings → API → Exposed schemas** at `public`.
- **Neon instead of Supabase** works the same way: use Neon's connection string in step 2. On Vercel use the "pooled" host with `DB_USER=lms_app`, `DB_NAME=neondb` and `DB_PORT=5432`.
- The container image was not built on the development PC (Docker is not installed there), so Vercel's first deploy is its first build. If it fails, the build log on Vercel says why.
