<?php
declare(strict_types=1);

/*
 * Sessions in the database (SESSION_DRIVER=db). A hosted site such as Vercel has no lasting disk and may run
 * several copies at once, so PHP's default session files would be lost between requests.
 * Only the routines fn_session_read / write / destroy / gc (database/04b_auth.sql) touch the app_session table.
 * The idle timeout, the new session id at sign-in and CSRF stay in PHP (src/auth.php, src/csrf.php).
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $data = q_val('SELECT fn_session_read(:id)', [':id' => $id]);
            return $data === null ? '' : (string) $data;
        } catch (Throwable $e) {
            log_error($e);
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            q('SELECT fn_session_write(:id, :data)', [':id' => $id, ':data' => $data]);
            return true;
        } catch (Throwable $e) {
            log_error($e);   // the page is already sent; the next request simply starts a fresh session
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            q('SELECT fn_session_destroy(:id)', [':id' => $id]);
            return true;
        } catch (Throwable $e) {
            log_error($e);
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return (int) q_val('SELECT fn_session_gc(:max)', [':max' => $max_lifetime]);
        } catch (Throwable $e) {
            log_error($e);
            return false;
        }
    }

    /** With session.use_strict_mode, only ids that exist are accepted; anything else gets a new id. */
    public function validateId(string $id): bool
    {
        try {
            return q_val('SELECT fn_session_read(:id)', [':id' => $id]) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
