<#
  Drops and recreates the lms database, then runs the numbered SQL files in order.

  Run from the library folder:
    powershell -ExecutionPolicy Bypass -File database\reset.ps1

  Options:
    -Database lms_test   build a scratch copy under another name (used by run_tests.ps1)
    -NoSeed              skip the starting data (06_seed.sql and 07_*.sql): no books and no office admin
    -Tests               run tests.sql on the database afterwards (only allowed on a name containing "test")
    -Hosted              build the hosted database (Neon, Supabase, ...) from DATABASE_ADMIN_URL (set in this window
                         or in .env). The database itself is kept: only the schema lms is emptied and rebuilt.
                         No superuser is needed. See docs/DEPLOY.md.

  Reads DB_HOST, DB_PORT, DB_NAME, PGUSER, PGPASSWORD, APP_DB_PASS (and optional PSQL_BIN, PHP_BIN) from .env.
  Only the database named here is dropped. Nothing else on the server is touched.
#>
param(
    [string]$Database = '',
    [switch]$NoSeed,
    [switch]$Tests,
    [switch]$Hosted
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) {
    Write-Host 'No .env file found. Copy .env.example to .env and fill it in.' -ForegroundColor Red
    exit 1
}

$cfg = @{}
foreach ($line in Get-Content $envFile -Encoding UTF8) {
    $t = $line.Trim()
    if ($t -eq '' -or $t.StartsWith('#')) { continue }
    $i = $t.IndexOf('=')
    if ($i -lt 1) { continue }
    $k = $t.Substring(0, $i).Trim()
    $v = $t.Substring($i + 1).Trim()
    if ($v.Length -ge 2 -and (($v.StartsWith('"') -and $v.EndsWith('"')) -or ($v.StartsWith("'") -and $v.EndsWith("'")))) {
        $v = $v.Substring(1, $v.Length - 2)
    }
    $cfg[$k] = $v
}
function Get-Cfg([string]$key, [string]$default) {
    if ($cfg.ContainsKey($key) -and $cfg[$key] -ne '') { return $cfg[$key] }
    return $default
}

$db = if ($Database) { $Database } else { Get-Cfg 'DB_NAME' 'lms' }
if ($db -notmatch '^[a-z_][a-z0-9_]*$') {
    Write-Host "Database name '$db' must be lower-case letters, digits and underscores." -ForegroundColor Red
    exit 1
}
if (@('postgres', 'template0', 'template1') -contains $db) {
    Write-Host "Refusing to reset the system database '$db'." -ForegroundColor Red
    exit 1
}

$env:PGHOST = Get-Cfg 'DB_HOST' 'localhost'
$env:PGPORT = Get-Cfg 'DB_PORT' '5432'
$env:PGUSER = Get-Cfg 'PGUSER' 'postgres'
$env:PGPASSWORD = Get-Cfg 'PGPASSWORD' ''
$env:PGCLIENTENCODING = 'UTF8'
$appPass = Get-Cfg 'APP_DB_PASS' ''

# Hosted: connect with the admin connection string, split into PG* variables so the password never sits on a
# command line.
if ($Hosted) {
    if ($Tests) { Write-Host 'The tests run only on a local scratch copy, never on the hosted database.' -ForegroundColor Red; exit 1 }
    $url = $env:DATABASE_ADMIN_URL
    if (-not $url) { $url = Get-Cfg 'DATABASE_ADMIN_URL' '' }
    if ($url -notmatch '^postgres(ql)?://') {
        Write-Host 'Set DATABASE_ADMIN_URL (the hosted database admin connection string) in this window or in .env.' -ForegroundColor Red
        exit 1
    }
    $u = [System.Uri]$url
    $userInfo = $u.UserInfo.Split(':', 2)
    $env:PGUSER = [System.Uri]::UnescapeDataString($userInfo[0])
    $env:PGPASSWORD = if ($userInfo.Count -gt 1) { [System.Uri]::UnescapeDataString($userInfo[1]) } else { '' }
    $env:PGHOST = $u.Host
    $env:PGPORT = if ($u.Port -gt 0) { "$($u.Port)" } else { '5432' }
    $env:PGSSLMODE = if ($u.Query -match 'sslmode=([a-z-]+)') { $Matches[1] } else { 'require' }
    $db = [System.Uri]::UnescapeDataString($u.AbsolutePath.TrimStart('/'))
}

# Find psql: .env, then PATH, then the newest PostgreSQL install folder.
$psql = Get-Cfg 'PSQL_BIN' ''
if (-not $psql) {
    $cmd = Get-Command psql -ErrorAction SilentlyContinue
    if ($cmd) { $psql = $cmd.Source }
}
if (-not $psql) {
    $psql = Get-ChildItem 'C:\Program Files\PostgreSQL\*\bin\psql.exe' -ErrorAction SilentlyContinue |
        Sort-Object { try { [double]$_.Directory.Parent.Name } catch { 0 } } -Descending |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $psql) {
    Write-Host 'psql was not found. Install PostgreSQL or set PSQL_BIN in .env.' -ForegroundColor Red
    exit 1
}

function Invoke-Psql([string[]]$psqlArgs) {
    & $psql -X -q -v ON_ERROR_STOP=1 @psqlArgs
    if ($LASTEXITCODE -ne 0) {
        Write-Host "psql failed (exit $LASTEXITCODE). Fix the error above and run the reset again." -ForegroundColor Red
        exit $LASTEXITCODE
    }
}

if ($Hosted) {
    Write-Host 'Rebuilding the schema lms on the hosted database (the database itself is kept)'
    Invoke-Psql @('-d', $db, '-c', 'DROP SCHEMA IF EXISTS lms CASCADE;')
} else {
    Write-Host "Resetting database '$db' on $($env:PGHOST):$($env:PGPORT) as $($env:PGUSER)"
    Invoke-Psql @('-d', 'postgres', '-c', "DROP DATABASE IF EXISTS $db WITH (FORCE);")
    Invoke-Psql @('-d', 'postgres', '-c', "CREATE DATABASE $db ENCODING 'UTF8' TEMPLATE template0;")
}

$files = Get-ChildItem (Join-Path $PSScriptRoot '0*.sql') | Sort-Object Name
$ranSeed = $false
foreach ($f in $files) {
    if ($NoSeed -and ($f.Name -like '06_*' -or $f.Name -like '07_*')) { continue }
    Write-Host "  running $($f.Name)"
    Invoke-Psql @('-d', $db, '-v', "app_pass=$appPass", '-v', "office_email=$(Get-Cfg 'OFFICE_ADMIN_EMAIL' '')", '-f', $f.FullName)
    if ($f.Name -like '06_*') { $ranSeed = $true }
}
if ($files.Count -eq 0) { Write-Host '  no SQL files yet (the database is empty)' }

# Give the office admin its password (only for the real or hosted database, not scratch copies).
$seedPasswords = Join-Path $PSScriptRoot 'seed_passwords.php'
if ($ranSeed -and (Test-Path $seedPasswords) -and ($Hosted -or $db -eq (Get-Cfg 'DB_NAME' 'lms'))) {
    $php = Get-Cfg 'PHP_BIN' ''
    if (-not $php) {
        $cmd = Get-Command php -ErrorAction SilentlyContinue
        if ($cmd) { $php = $cmd.Source }
    }
    if (-not $php) {
        $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.*\php.exe" -ErrorAction SilentlyContinue |
            Select-Object -First 1 -ExpandProperty FullName
    }
    if (-not $php) {
        Write-Host 'php was not found, so the office admin password was not set. Set PHP_BIN in .env.' -ForegroundColor Yellow
    } else {
        Write-Host '  setting the office admin password'
        $phpArgs = @($seedPasswords)
        if ($Hosted) { $phpArgs += '--hosted' }
        & $php @phpArgs
        if ($LASTEXITCODE -ne 0) { Write-Host 'seed_passwords.php failed.' -ForegroundColor Red; exit $LASTEXITCODE }
    }
}

# A local copy also gets the two demo accounts (Demo Admin, Demo Librarian). Never on a hosted database, never on a
# scratch copy: a public site must not start with published passwords (docs/DEPLOY.md).
$demoAccounts = Join-Path $PSScriptRoot 'demo_accounts.sql'
if ($ranSeed -and -not $Hosted -and $db -eq (Get-Cfg 'DB_NAME' 'lms') -and (Test-Path $demoAccounts)) {
    Write-Host '  adding the demo accounts (Demo Admin, Demo Librarian)'
    Invoke-Psql @('-d', $db, '-f', $demoAccounts)
}

Write-Host "Reset finished: $db" -ForegroundColor Green

if ($Tests) {
    Write-Host "Running tests.sql on $db"
    & $psql -X -q -v ON_ERROR_STOP=1 -d $db -f (Join-Path $PSScriptRoot 'tests.sql')
    if ($LASTEXITCODE -ne 0) { Write-Host 'Some checks failed (see FAIL lines above).' -ForegroundColor Red; exit 1 }
}
