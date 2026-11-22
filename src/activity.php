<?php
declare(strict_types=1);

/*
 * The audit trail in plain words, for the dashboard (Recent activity) and the admin Activity page.
 * "Maria approved Ella's request for Clean Code", "The system marked Computer Networking overdue (Miguel Santos)".
 */

const SETTING_LABELS = [
    'library_name' => 'library name',
    'library_hours' => 'library hours',
    'loan_days_student' => 'loan days for students',
    'loan_days_teacher' => 'loan days for teachers',
    'max_loans_student' => 'borrowing limit for students',
    'max_loans_teacher' => 'borrowing limit for teachers',
    'fine_per_day' => 'fine per day',
    'pickup_window_days' => 'pickup window',
    'session_idle_minutes' => 'sign-out after idle minutes',
];

/**
 * Audit rows with the names needed to describe them.
 * $filters: actor ('staff:2'), table, from (Y-m-d), to (Y-m-d), meaningful (bool, hides bookkeeping rows).
 */
function activity_rows(array $filters, int $limit, int $offset = 0): array
{
    [$where, $params] = activity_where($filters);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    return q_all(
        "SELECT a.audit_id, a.actor_type, a.actor_id, a.action, a.table_name, a.record_id, a.detail, a.created_at,
                (SELECT d.full_name FROM v_user_directory d WHERE d.user_type = a.actor_type AND d.user_id = a.actor_id) AS actor_name,
                r.borrower_name AS req_borrower, r.book_title AS req_book,
                l.borrower_name AS loan_borrower, l.book_title AS loan_book,
                f.borrower_name AS fine_borrower, f.book_title AS fine_book, f.amount AS fine_amount, f.fine_type,
                b.title AS book_title,
                u.full_name AS user_name
           FROM audit_log a
           LEFT JOIN v_request_detail r ON a.table_name = 'book_request' AND r.request_id::text = a.record_id
           LEFT JOIN v_loan_detail l    ON a.table_name = 'transaction' AND l.transaction_id::text = a.record_id
           LEFT JOIN v_fine_detail f    ON a.table_name = 'fine' AND f.fine_id::text = a.record_id
           LEFT JOIN v_book_detail b    ON a.table_name = 'book' AND b.book_id::text = a.record_id
           LEFT JOIN v_user_directory u ON a.table_name IN ('student', 'teacher', 'staff')
                                       AND u.user_type = a.table_name AND u.user_id::text = a.record_id
          WHERE $where
          ORDER BY a.created_at DESC, a.audit_id DESC
          LIMIT :limit OFFSET :offset",
        $params
    );
}

function activity_count(array $filters): int
{
    [$where, $params] = activity_where($filters);
    return (int) q_val("SELECT count(*) FROM audit_log a WHERE $where", $params);
}

/** WHERE clause for the filters. Only fixed SQL text is joined; every value is a bound parameter. */
function activity_where(array $filters): array
{
    $sql = ['true'];
    $params = [];
    if (!empty($filters['meaningful'])) {
        // Copy-count bookkeeping and the fulfilled step (the loan line says it) add nothing for people.
        $sql[] = "NOT (a.table_name = 'book' AND a.action = 'update'
                       AND NOT jsonb_exists_any(a.detail, ARRAY['title','author','category','isbn','replacement_cost','copies_total','is_archived']))";
        $sql[] = "NOT (a.table_name = 'book_request' AND a.action = 'update'
                       AND coalesce(a.detail -> 'status' ->> 'new', 'fulfilled') = 'fulfilled')";
        // Signing in and out happens all day; the Activity page still shows it with the filter off.
        $sql[] = "a.action NOT IN ('login', 'logout')";
    }
    if (!empty($filters['actor']) && preg_match('/^(staff|student|teacher):(\d{1,9})$/', $filters['actor'], $m)) {
        $sql[] = 'a.actor_type = :actor_type AND a.actor_id = :actor_id';
        $params[':actor_type'] = $m[1];
        $params[':actor_id'] = (int) $m[2];
    } elseif (($filters['actor'] ?? '') === 'system') {
        $sql[] = "a.actor_type = 'system'";
    }
    if (!empty($filters['table'])) {
        $sql[] = 'a.table_name = :table';
        $params[':table'] = $filters['table'];
    }
    if (!empty($filters['from'])) {
        $sql[] = 'a.created_at >= CAST(:from AS date)';
        $params[':from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $sql[] = "a.created_at < CAST(:to AS date) + 1";
        $params[':to'] = $filters['to'];
    }
    return [implode(' AND ', $sql), $params];
}

function first_word(?string $name): string
{
    $name = trim((string) $name);
    return $name === '' ? '' : explode(' ', $name)[0];
}

/** One audit row as a sentence. Names come from the joins; deleted records fall back to the stored detail. */
function activity_sentence(array $row, bool $fullNames = false): string
{
    $detail = is_array($row['detail']) ? $row['detail'] : (json_decode((string) $row['detail'], true) ?: []);
    $short = fn(?string $n) => $fullNames ? (string) $n : first_word($n);
    $actor = match ($row['actor_type']) {
        'system' => 'The system',
        default => $row['actor_name'] ? $short($row['actor_name']) : ucfirst($row['actor_type']) . ' #' . $row['actor_id'],
    };
    $new = static fn(string $key) => is_array($detail[$key] ?? null) ? ($detail[$key]['new'] ?? null) : null;
    $old = static fn(string $key) => is_array($detail[$key] ?? null) ? ($detail[$key]['old'] ?? null) : null;
    $action = $row['action'];

    switch ($row['table_name']) {
        case 'book_request':
            $borrower = $row['req_borrower'] ?? 'a borrower';
            $book = $row['req_book'] ?? 'a book';
            $ownSide = in_array($row['actor_type'], ['student', 'teacher'], true);
            if ($action === 'insert') {
                return $ownSide ? "$actor requested $book" : "$actor made a request for $borrower: $book";
            }
            return match ($new('status')) {
                'approved' => "$actor approved " . possessive($short($borrower)) . " request for $book",
                'rejected' => "$actor rejected " . possessive($short($borrower)) . " request for $book",
                'cancelled' => $ownSide ? "$actor cancelled a request for $book" : "$actor cancelled " . possessive($short($borrower)) . " request for $book",
                'expired' => possessive($borrower) . " reservation for $book expired",
                'fulfilled' => "$book was picked up by $borrower",
                default => "$actor updated a request for $book",
            };

        case 'transaction':
            $borrower = $row['loan_borrower'] ?? 'a borrower';
            $book = $row['loan_book'] ?? 'a book';
            if ($action === 'insert') {
                return "$actor issued $book to $borrower";
            }
            return match ($new('status')) {
                'returned' => "$actor marked $book returned by $borrower",
                'lost' => "$actor marked $book lost ($borrower)",
                'overdue' => "$book became overdue ($borrower)",
                default => "$actor updated the loan of $book",
            };

        case 'fine':
            $borrower = $row['fine_borrower'] ?? 'a borrower';
            $amount = peso($row['fine_amount'] ?? ($detail['amount'] ?? 0));
            $type = $row['fine_type'] ?? ($detail['fine_type'] ?? '');
            if ($action === 'insert') {
                return "$amount $type fine for $borrower (" . ($row['fine_book'] ?? 'a book') . ')';
            }
            return match ($new('status')) {
                'paid' => "$actor recorded a payment of $amount from $borrower",
                'waived' => "$actor waived " . possessive($short($borrower)) . " $amount fine",
                default => "$actor updated a fine for $borrower",
            };

        case 'book':
            $title = $row['book_title'] ?? ($detail['title'] ?? 'a book');
            if ($action === 'insert') {
                return "$actor added $title";
            }
            if ($action === 'delete') {
                return "$actor deleted $title";
            }
            if ($new('is_archived') === true) {
                return "$actor archived $title";
            }
            if ($new('is_archived') === false) {
                return "$actor restored $title";
            }
            if (isset($detail['copies_total']) && count(array_diff(array_keys($detail), ['copies_total', 'copies_available'])) === 0) {
                return "Copies of $title went from " . (int) $old('copies_total') . ' to ' . (int) $new('copies_total');
            }
            return "$actor updated the details of $title";

        case 'student':
        case 'teacher':
        case 'staff':
            $name = $row['user_name'] ?? trim(($detail['first_name'] ?? '') . ' ' . ($detail['last_name'] ?? ''));
            $name = $name !== '' ? $name : 'an account';
            $kind = $row['table_name'] === 'staff' ? 'staff member' : $row['table_name'];
            $self = $row['actor_type'] === $row['table_name'] && (string) $row['actor_id'] === (string) $row['record_id'];
            switch ($action) {
                case 'login':
                    return "$actor signed in";
                case 'logout':
                    return "$actor signed out";
                case 'sign_up':
                    return $self ? "$actor created a $kind account" : "$actor added $kind $name";
                case 'password_changed':
                    return "$actor changed their password";
                case 'password_reset_by_staff':
                    return "$actor reset the password for $name";
                case 'password_reset_requested':
                    return "A password reset link was sent to $name";
                case 'password_reset':
                    return "$actor set a new password with a reset link";
            }
            if ($action === 'insert') {
                return "$actor added $kind $name";
            }
            if ($action === 'delete') {
                return "$actor deleted $kind $name";
            }
            if ($new('is_active') === false) {
                return "$actor deactivated $name";
            }
            if ($new('is_active') === true) {
                return "$actor reactivated $name";
            }
            if (!empty($detail['password_changed'])) {
                return $self ? "$actor changed their password" : "$actor reset the password for $name";
            }
            return "$actor updated the details of $name";

        case 'library_setting':
            $label = SETTING_LABELS[$row['record_id']] ?? str_replace('_', ' ', (string) $row['record_id']);
            $value = $new('setting_value') ?? ($detail['setting_value'] ?? '');
            return "$actor set the $label to $value";
    }
    return "$actor changed " . str_replace('_', ' ', $row['table_name']);
}

/** Icon for an activity line. */
function activity_icon(array $row): string
{
    if (in_array($row['action'], ['password_changed', 'password_reset_by_staff', 'password_reset_requested', 'password_reset'], true)) {
        return 'key';
    }
    return match ($row['table_name']) {
        'book_request' => 'inbox',
        'transaction' => 'issue',
        'fine' => 'coins',
        'book' => 'books',
        'library_setting' => 'settings',
        default => 'user',
    };
}
