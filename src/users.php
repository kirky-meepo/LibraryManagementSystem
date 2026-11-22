<?php
declare(strict_types=1);

/* People: the three account kinds, validation for the Add/Edit user pop-up, and temporary passwords. */

const USER_TYPES = [
    'student' => ['label' => 'Students', 'one' => 'student', 'table' => 'student', 'key' => 'student_id'],
    'teacher' => ['label' => 'Teachers', 'one' => 'teacher', 'table' => 'teacher', 'key' => 'teacher_id'],
    'staff' => ['label' => 'Staff', 'one' => 'staff member', 'table' => 'staff', 'key' => 'staff_id'],
];

/** A temporary password that is easy to read aloud: three groups from letters and digits that do not look alike. */
function temporary_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 3; $g++) {
        $part = '';
        for ($i = 0; $i < 4; $i++) {
            $part .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $part;
    }
    return implode('-', $groups);
}

/** Can this signed-in person change this account at all? */
function can_manage_user(array $me, string $type): bool
{
    return $type !== 'staff' || $me['role'] === 'admin';
}

/**
 * Validate the Add/Edit user form. Returns [clean values, errors by field].
 * $exceptId: the person being edited (their own email is not a duplicate).
 */
function validate_user(string $type, array $in, ?int $exceptId = null): array
{
    $errors = [];
    $text = static fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max + 1);
    $clean = [
        'first_name' => $text('first_name', 60),
        'middle_name' => $text('middle_name', 60),
        'last_name' => $text('last_name', 60),
        'email' => mb_strtolower($text('email', 254)),
        'contact_number' => $text('contact_number', 20),
    ];

    foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $key => $label) {
        if ($clean[$key] === '') {
            $errors[$key] = "Type the $label.";
        } elseif (mb_strlen($clean[$key]) > 60) {
            $errors[$key] = 'Use at most 60 characters.';
        }
    }
    if (mb_strlen($clean['middle_name']) > 60) {
        $errors['middle_name'] = 'Use at most 60 characters.';
    }
    $clean['middle_name'] = $clean['middle_name'] === '' ? null : $clean['middle_name'];

    if ($clean['email'] === '' || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($clean['email']) > 254) {
        $errors['email'] = 'Type a full email address, for example juan.cruz@school.edu.';
    } else {
        $meta = USER_TYPES[$type];
        $taken = q_val(
            'SELECT 1 FROM v_user_directory WHERE user_type = :t AND email = :e AND user_id IS DISTINCT FROM CAST(:id AS int)',
            [':t' => $type, ':e' => $clean['email'], ':id' => $exceptId]
        );
        if ($taken) {
            $errors['email'] = 'Another ' . $meta['one'] . ' already uses this email.';
        }
    }

    if ($clean['contact_number'] === '') {
        $clean['contact_number'] = null;
    } elseif (mb_strlen($clean['contact_number']) > 20 || !preg_match('/^[0-9+()\-\s]{7,20}$/', $clean['contact_number'])) {
        $errors['contact_number'] = 'Use digits, spaces, + ( ) or -, up to 20 characters, for example 0917 555 0142.';
    }

    if ($type === 'student') {
        $clean['course'] = $text('course', 80);
        $year = trim((string) ($in['year_level'] ?? ''));
        if ($clean['course'] === '') {
            $errors['course'] = 'Pick a course from the list or type a new one.';
        } elseif (mb_strlen($clean['course']) > 80) {
            $errors['course'] = 'Use at most 80 characters.';
        }
        if (!preg_match('/^[1-6]$/', $year)) {
            $errors['year_level'] = 'Choose a year level from 1 to 6.';
        } else {
            $clean['year_level'] = (int) $year;
        }
    } elseif ($type === 'teacher') {
        $clean['department'] = $text('department', 80);
        if ($clean['department'] === '') {
            $errors['department'] = 'Pick a department from the list or type a new one.';
        } elseif (mb_strlen($clean['department']) > 80) {
            $errors['department'] = 'Use at most 80 characters.';
        }
    } else {
        $clean['position'] = (string) ($in['position'] ?? '');
        if (!in_array($clean['position'], ['librarian', 'admin'], true)) {
            $errors['position'] = 'Choose Librarian or Admin.';
        }
        $hired = trim((string) ($in['date_hired'] ?? ''));
        if ($hired === '') {
            $clean['date_hired'] = null;
        } else {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $hired);
            if (!$d || $d->format('Y-m-d') !== $hired || $hired > today()) {
                $errors['date_hired'] = 'Choose a date that is not in the future.';
            } else {
                $clean['date_hired'] = $hired;
            }
        }
    }
    return [$clean, $errors];
}

