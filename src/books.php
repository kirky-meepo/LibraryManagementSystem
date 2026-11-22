<?php
declare(strict_types=1);

/* Book input rules shared by Add New Book and the edit pop-up. */

/** How many copies a new title may have until an admin raises its copy_limit (matches the column default). */
const DEFAULT_COPY_LIMIT = 5;

/**
 * Check an ISBN-10 or ISBN-13 (hyphens and spaces allowed) and return it as ISBN-13 written 978-XXXXXXXXXX.
 * ISBN-10s are converted, so the same book can never be added twice under two ISBN forms.
 * Returns null when the format or check digit is wrong.
 */
function isbn_normalize(string $raw): ?string
{
    $digits = strtoupper(preg_replace('/[\s-]+/', '', $raw) ?? '');
    if (preg_match('/^\d{9}[\dX]$/', $digits)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($digits[$i] === 'X' ? 10 : (int) $digits[$i]) * (10 - $i);
        }
        if ($sum % 11 !== 0) {
            return null;
        }
        $digits = '978' . substr($digits, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $digits[$i] * ($i % 2 ? 3 : 1);
        }
        $digits .= (string) ((10 - $sum % 10) % 10);
    }
    if (!preg_match('/^97[89]\d{10}$/', $digits)) {
        return null;
    }
    $sum = 0;
    for ($i = 0; $i < 13; $i++) {
        $sum += (int) $digits[$i] * ($i % 2 ? 3 : 1);
    }
    if ($sum % 10 !== 0) {
        return null;
    }
    return substr($digits, 0, 3) . '-' . substr($digits, 3);
}

/** Categories already in use, for the suggestion list and filters (fn_list_categories). */
function book_categories(bool $includeArchived = true): array
{
    return q_all('SELECT category FROM fn_list_categories(:arch)', [':arch' => $includeArchived ? 'true' : 'false']);
}

/**
 * Validate the book form. Returns [clean values, errors by field name].
 * $exceptId: the book being edited (so its own ISBN does not count as a duplicate).
 * $limit: the most copies this title may have (copy_limit; a new book starts at DEFAULT_COPY_LIMIT).
 */
function validate_book(array $in, ?int $exceptId = null, int $limit = DEFAULT_COPY_LIMIT): array
{
    $errors = [];
    $clean = [
        'isbn' => null,
        'title' => trim((string) ($in['title'] ?? '')),
        'author' => trim((string) ($in['author'] ?? '')),
        'category' => trim((string) ($in['category'] ?? '')),
        'copies_total' => null,
        'replacement_cost' => null,
    ];

    $isbnRaw = trim((string) ($in['isbn'] ?? ''));
    if ($isbnRaw !== '') {
        $isbn = isbn_normalize($isbnRaw);
        if ($isbn === null) {
            $errors['isbn'] = 'This is not a valid ISBN. Type the 10 or 13 digits from the back cover; hyphens are fine.';
        } else {
            $clean['isbn'] = $isbn;
            $dupe = q_row(
                "SELECT book_id, title FROM v_book_detail WHERE replace(isbn, '-', '') = :digits AND book_id IS DISTINCT FROM CAST(:id AS int)",
                [':digits' => str_replace('-', '', $isbn), ':id' => $exceptId]
            );
            if ($dupe) {
                $errors['isbn'] = 'A book with this ISBN already exists: ' . $dupe['title'] . '. Edit that book instead of adding it again.';
            }
        }
    }

    if ($clean['title'] === '') {
        $errors['title'] = 'Type the title.';
    } elseif (mb_strlen($clean['title']) > 200) {
        $errors['title'] = 'The title can be at most 200 characters.';
    }
    if ($clean['author'] === '') {
        $errors['author'] = 'Type the author.';
    } elseif (mb_strlen($clean['author']) > 150) {
        $errors['author'] = 'The author can be at most 150 characters.';
    }
    if ($clean['category'] === '') {
        $errors['category'] = 'Pick a category from the list or type a new one.';
    } elseif (mb_strlen($clean['category']) > 60) {
        $errors['category'] = 'The category can be at most 60 characters.';
    }

    $copies = trim((string) ($in['copies_total'] ?? ''));
    if (!preg_match('/^\d{1,3}$/', $copies) || (int) $copies < 1 || (int) $copies > $limit) {
        $errors['copies_total'] = $limit === 1
            ? 'This title can have only 1 copy. An admin can raise the limit.'
            : "Copies must be a whole number from 1 to $limit. An admin can raise the limit for a title.";
    } else {
        $clean['copies_total'] = (int) $copies;
    }

    $cost = str_replace([',', '₱', ' '], '', trim((string) ($in['replacement_cost'] ?? '')));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $cost)) {
        $errors['replacement_cost'] = 'Replacement cost must be 0 or more pesos, for example 2450 or 2450.50.';
    } else {
        $clean['replacement_cost'] = number_format((float) $cost, 2, '.', '');
    }

    return [$clean, $errors];
}

/** Status chip for a book's shelf count. */
function availability_chip(int $available, bool $archived = false): string
{
    if ($archived) {
        return '<span class="chip">Archived</span>';
    }
    return match (true) {
        $available <= 0 => '<span class="chip chip-bad">None</span>',
        $available === 1 => '<span class="chip chip-warn">Low</span>',
        default => '<span class="chip chip-ok">Available</span>',
    };
}
