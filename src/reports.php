<?php
declare(strict_types=1);

/* Report periods and the CSV exports, shared by Status and Reports and export.php. */

const REPORT_RANGES = [
    'month' => 'this month',
    '3months' => 'the last 3 months',
    'year' => 'this year',
];

/** [key, first day (Y-m-d), label] for a period key. Defaults to the last 3 months. */
function report_range(string $key): array
{
    $key = isset(REPORT_RANGES[$key]) ? $key : '3months';
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $from = match ($key) {
        'month' => $today->modify('first day of this month'),
        '3months' => $today->modify('first day of this month')->modify('-2 months'),
        'year' => $today->setDate((int) $today->format('Y'), 1, 1),
    };
    return [$key, $from->format('Y-m-d'), REPORT_RANGES[$key]];
}

/*
 * Each export: the label, whether the period applies, and a fixed query (no input is ever joined into it).
 * Users never include a password hash: v_user_directory has no such column.
 */
const EXPORTS = [
    'books' => [
        'label' => 'Books',
        'dated' => false,
        'sql' => "SELECT isbn AS \"ISBN\", title AS \"Title\", author AS \"Author\", category AS \"Category\",
                         copies_total AS \"Total copies\", copies_available AS \"On the shelf\", on_loan AS \"On loan\",
                         reserved AS \"Reserved\", replacement_cost AS \"Replacement cost (₱)\",
                         CASE WHEN is_archived THEN 'Yes' ELSE 'No' END AS \"Archived\", date_added AS \"Date added\"
                    FROM v_book_detail ORDER BY title",
    ],
    'loans' => [
        'label' => 'Issued loans',
        'dated' => true,
        'sql' => "SELECT transaction_id AS \"Loan ID\", borrower_name AS \"Borrower\", initcap(borrower_type) AS \"Borrower type\",
                         borrower_email AS \"Email\", book_title AS \"Book\", date_borrowed AS \"Borrowed\", due_date AS \"Due\",
                         date_returned AS \"Returned\", initcap(display_status) AS \"Status\", days_late AS \"Days late\",
                         fine_so_far AS \"Fine so far (₱)\", fines_total AS \"Fines charged (₱)\", issued_by_name AS \"Issued by\"
                    FROM v_loan_detail WHERE date_borrowed >= CAST(:from AS date) ORDER BY date_borrowed, transaction_id",
    ],
    'fines' => [
        'label' => 'Fines',
        'dated' => true,
        'sql' => "SELECT fine_id AS \"Fine ID\", borrower_name AS \"Borrower\", initcap(borrower_type) AS \"Borrower type\",
                         book_title AS \"Book\", initcap(fine_type) AS \"Fine type\", amount AS \"Amount (₱)\",
                         date_issued AS \"Issued\", date_paid AS \"Paid\", initcap(status) AS \"Status\", waived_reason AS \"Waived because\"
                    FROM v_fine_detail WHERE date_issued >= CAST(:from AS date) ORDER BY date_issued, fine_id",
    ],
    'users' => [
        'label' => 'Users',
        'dated' => false,
        'sql' => "SELECT initcap(user_type) AS \"Type\", full_name AS \"Name\", email AS \"Email\", contact_number AS \"Contact number\",
                         detail AS \"Course, department or position\", CASE WHEN is_active THEN 'Active' ELSE 'Inactive' END AS \"Status\",
                         joined_on AS \"Joined\"
                    FROM v_user_directory ORDER BY user_type, last_name, first_name",
    ],
];

/** Make a cell safe to open in Excel: a value starting with = + - @ (or a tab/CR) could run as a formula. */
function csv_safe($value): string
{
    $value = $value === null ? '' : (is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value);
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}
