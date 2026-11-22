<?php
declare(strict_types=1);

/*
 * Member side helpers. Every member query is filtered by the signed-in person's own id from the session,
 * never by an id from the address bar, so nobody can read another person's requests, loans, fines or messages.
 */

/**
 * The columns and id that identify the signed-in member.
 * col: student_id or teacher_id (as used by book_request, transaction and the views)
 * msg: recipient_student_id or recipient_teacher_id (message, v_inbox)
 */
function member_scope(array $me): array
{
    $isStudent = $me['type'] === 'student';
    return [
        'id' => $me['id'],
        'col' => $isStudent ? 'student_id' : 'teacher_id',
        'msg' => $isStudent ? 'recipient_student_id' : 'recipient_teacher_id',
        'student_id' => $isStudent ? $me['id'] : null,
        'teacher_id' => $isStudent ? null : $me['id'],
    ];
}

/** Database rule messages name the borrower ("Ella Ramirez has ..."); members read them as "You have ...". */
function member_words(string $message, array $me): string
{
    $name = $me['name'];
    return strtr($message, [
        $name . "'s library account" => 'Your library account',
        $name . ' already has ' => 'You already have ',
        $name . ' has ' => 'You have ',
    ]);
}

/** Gradient pair for a catalog cover, fixed per category so a shelf reads as one colour family. */
function cover_style(string $category): array
{
    $pairs = [
        ['#011f4b', '#03396c'], ['#03396c', '#005b96'], ['#005b96', '#011f4b'],
        ['#6497b1', '#b3cde0'], ['#011f4b', '#005b96'], ['#b3cde0', '#6497b1'],
    ];
    return $pairs[abs(crc32(mb_strtolower($category))) % count($pairs)];
}

/** Days-left chip for a loan: On time, Due soon (3 days or less) or Overdue. */
function due_chip(array $loan): string
{
    if ($loan['display_status'] === 'overdue') {
        return '<span class="chip chip-bad">Overdue</span><span class="sub">' . h(plural((int) $loan['days_late'], 'day')) . ' late</span>';
    }
    $left = (int) $loan['days_left'];
    if ($left <= 3) {
        return '<span class="chip chip-warn">Due soon</span><span class="sub">' . ($left === 0 ? 'Due today' : h(plural($left, 'day')) . ' left') . '</span>';
    }
    return '<span class="chip chip-ok">On time</span><span class="sub">' . h(plural($left, 'day')) . ' left</span>';
}

/** Keep loan and request statuses fresh for the member (logged as the system). */
function refresh_statuses(): void
{
    tx(function (PDO $pdo) {
        $pdo->exec('CALL sp_mark_overdue()');
        $pdo->exec('CALL sp_expire_reservations()');
    });
}
