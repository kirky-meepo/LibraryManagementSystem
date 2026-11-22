#!/usr/bin/env bash
# Drops and recreates the lms database, then runs the numbered SQL files in order.
#
# Run from the library folder (Git Bash on Windows, or any Unix shell):
#   bash database/reset.sh             # the database named DB_NAME in .env
#   bash database/reset.sh lms_test    # a scratch copy under another name
#   NO_SEED=1 bash database/reset.sh   # skip the starting data (06_seed.sql and 07_*.sql): no books, no office admin
#   HOSTED=1 bash database/reset.sh    # the hosted database in DATABASE_ADMIN_URL: keeps the database, rebuilds schema lms
#
# Reads DB_HOST, DB_PORT, DB_NAME, PGUSER, PGPASSWORD, APP_DB_PASS (and optional PSQL_BIN, PHP_BIN) from .env.
# Only the database named here is dropped. Nothing else on the server is touched.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(dirname "$here")"
env_file="$root/.env"
if [[ ! -f "$env_file" ]]; then
  echo "No .env file found. Copy .env.example to .env and fill it in." >&2
  exit 1
fi

declare -A cfg=()
while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line%$'\r'}"
  [[ -z "${line// }" || "${line#"${line%%[![:space:]]*}"}" == \#* ]] && continue
  [[ "$line" != *=* ]] && continue
  key="$(echo "${line%%=*}" | xargs)"
  val="${line#*=}"
  val="${val#"${val%%[![:space:]]*}"}"; val="${val%"${val##*[![:space:]]}"}"
  if [[ ${#val} -ge 2 && ( ( "${val:0:1}" == '"' && "${val: -1}" == '"' ) || ( "${val:0:1}" == "'" && "${val: -1}" == "'" ) ) ]]; then
    val="${val:1:${#val}-2}"
  fi
  cfg["$key"]="$val"
done < "$env_file"
get_cfg() { local v="${cfg[$1]:-}"; [[ -n "$v" ]] && echo "$v" || echo "$2"; }

db="${1:-$(get_cfg DB_NAME lms)}"
if [[ ! "$db" =~ ^[a-z_][a-z0-9_]*$ ]]; then
  echo "Database name '$db' must be lower-case letters, digits and underscores." >&2; exit 1
fi
case "$db" in postgres|template0|template1) echo "Refusing to reset the system database '$db'." >&2; exit 1;; esac

export PGHOST="$(get_cfg DB_HOST localhost)"
export PGPORT="$(get_cfg DB_PORT 5432)"
export PGUSER="$(get_cfg PGUSER postgres)"
export PGPASSWORD="$(get_cfg PGPASSWORD '')"
export PGCLIENTENCODING=UTF8
app_pass="$(get_cfg APP_DB_PASS '')"

# Hosted: the admin connection string, split into PG* variables so the password never sits on a command line.
if [[ -n "${HOSTED:-}" ]]; then
  [[ -n "${RUN_TESTS:-}" ]] && { echo "The tests run only on a local scratch copy, never on the hosted database." >&2; exit 1; }
  url="${DATABASE_ADMIN_URL:-$(get_cfg DATABASE_ADMIN_URL '')}"
  re='^postgres(ql)?://([^:@/]+)(:([^@]*))?@([^:/?]+)(:([0-9]+))?/([^?]+)(\?(.*))?$'
  if [[ ! "$url" =~ $re ]]; then
    echo "Set DATABASE_ADMIN_URL (the hosted database admin connection string) in the environment or in .env." >&2; exit 1
  fi
  urldecode() { local s="${1//+/ }"; printf '%b' "${s//%/\\x}"; }
  export PGUSER="$(urldecode "${BASH_REMATCH[2]}")" PGPASSWORD="$(urldecode "${BASH_REMATCH[4]}")"
  export PGHOST="${BASH_REMATCH[5]}" PGPORT="${BASH_REMATCH[7]:-5432}"
  db="$(urldecode "${BASH_REMATCH[8]}")"
  query="${BASH_REMATCH[10]}"
  if [[ "$query" =~ (^|&)sslmode=([a-z-]+) ]]; then export PGSSLMODE="${BASH_REMATCH[2]}"; else export PGSSLMODE=require; fi
fi

psql_bin="$(get_cfg PSQL_BIN '')"
if [[ -z "$psql_bin" ]]; then psql_bin="$(command -v psql || true)"; fi
if [[ -z "$psql_bin" ]]; then
  psql_bin="$(ls -d /c/Program\ Files/PostgreSQL/*/bin/psql.exe 2>/dev/null | sort -V | tail -1 || true)"
fi
if [[ -z "$psql_bin" ]]; then echo "psql was not found. Install PostgreSQL or set PSQL_BIN in .env." >&2; exit 1; fi

run_psql() { "$psql_bin" -X -q -v ON_ERROR_STOP=1 "$@"; }

if [[ -n "${HOSTED:-}" ]]; then
  echo "Rebuilding the schema lms on the hosted database (the database itself is kept)"
  run_psql -d "$db" -c "DROP SCHEMA IF EXISTS lms CASCADE;"
else
  echo "Resetting database '$db' on $PGHOST:$PGPORT as $PGUSER"
  run_psql -d postgres -c "DROP DATABASE IF EXISTS $db WITH (FORCE);"
  run_psql -d postgres -c "CREATE DATABASE $db ENCODING 'UTF8' TEMPLATE template0;"
fi

ran_seed=0
shopt -s nullglob
files=("$here"/0*.sql)
if [[ ${#files[@]} -eq 0 ]]; then echo "  no SQL files yet (the database is empty)"; fi
for f in "${files[@]}"; do
  name="$(basename "$f")"
  if [[ -n "${NO_SEED:-}" && ( "$name" == 06_* || "$name" == 07_* ) ]]; then continue; fi
  echo "  running $name"
  run_psql -d "$db" -v "app_pass=$app_pass" -v "office_email=$(get_cfg OFFICE_ADMIN_EMAIL '')" -f "$f"
  [[ "$name" == 06_* ]] && ran_seed=1
done

if [[ $ran_seed -eq 1 && -f "$here/seed_passwords.php" && ( -n "${HOSTED:-}" || "$db" == "$(get_cfg DB_NAME lms)" ) ]]; then
  php_bin="$(get_cfg PHP_BIN '')"
  [[ -z "$php_bin" ]] && php_bin="$(command -v php || true)"
  if [[ -z "$php_bin" ]]; then
    lad="${LOCALAPPDATA:-/nonexistent}"
    command -v cygpath >/dev/null 2>&1 && lad="$(cygpath -u "$lad")"
    php_bin="$(ls -d "$lad"/Microsoft/WinGet/Packages/PHP.PHP.*/php.exe 2>/dev/null | head -1 || true)"
  fi
  if [[ -z "$php_bin" ]]; then
    echo "php was not found, so the office admin password was not set. Set PHP_BIN in .env." >&2
  else
    echo "  setting the office admin password"
    "$php_bin" "$here/seed_passwords.php" ${HOSTED:+--hosted}
  fi
fi

# A local copy also gets the two demo accounts (Demo Admin, Demo Librarian). Never on a hosted database, never on a
# scratch copy: a public site must not start with published passwords (docs/DEPLOY.md).
if [[ $ran_seed -eq 1 && -z "${HOSTED:-}" && "$db" == "$(get_cfg DB_NAME lms)" && -f "$here/demo_accounts.sql" ]]; then
  echo "  adding the demo accounts (Demo Admin, Demo Librarian)"
  run_psql -d "$db" -f "$here/demo_accounts.sql"
fi

echo "Reset finished: $db"

if [[ -n "${RUN_TESTS:-}" ]]; then
  echo "Running tests.sql on $db"
  "$psql_bin" -X -q -v ON_ERROR_STOP=1 -d "$db" -f "$here/tests.sql" || { echo "Some checks failed (see FAIL lines above)." >&2; exit 1; }
fi
