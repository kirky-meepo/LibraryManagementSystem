<?php
declare(strict_types=1);

/*
 * End-to-end smoke test: the whole site through HTTP, like a person with a browser (plain PHP, curl and a cookie jar).
 *     php database/tools/smoke.php                    starts its own php -S on 127.0.0.1:8091 with MAIL_DRIVER=outbox
 *     php database/tools/smoke.php --port=8092        another port
 *     php database/tools/smoke.php --session=db       the server stores sessions in the database (SESSION_DRIVER=db)
 * Run it right after database\reset.ps1. It signs in as the office admin (OFFICE_ADMIN_EMAIL / OFFICE_ADMIN_PASSWORD)
 * and makes its own people: it changes data (two students, a librarian, requests, loans, a lost copy, a password reset,
 * a lock-out on 127.0.0.1). Run reset.ps1 again afterwards.
 * Prints PASS or FAIL per check and exits with 1 when any check failed. Passwords and tokens are never printed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/db.php';

$opts = getopt('', ['port::', 'session::']);
$port = (int) ($opts['port'] ?? 8091);
$session = ($opts['session'] ?? 'files') === 'db' ? 'db' : 'files';
$base = "http://127.0.0.1:$port";
$adminEmail = strtolower(trim((string) env('OFFICE_ADMIN_EMAIL', '')));
$adminPassword = (string) env('OFFICE_ADMIN_PASSWORD', '');
$outbox = APP_ROOT . '/storage/outbox';
$results = [];

function check(string $name, bool $ok, string $detail = ''): bool
{
    global $results;
    $results[] = [$name, $ok, $detail];
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  [$detail]" : '');
    return $ok;
}

/** A browser: one cookie jar, CSRF tokens read from the pages. */
final class Browser
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'smoke');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function request(string $method, string $path, array $fields = [], array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw = (string) curl_exec($ch);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        $head = substr($raw, 0, $size);
        return ['code' => $code, 'body' => substr($raw, $size), 'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : ''];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** Follow one redirect (the flash message is on the next page). */
    public function follow(array $r): array
    {
        return $r['location'] !== '' ? $this->get($r['location']) : $r;
    }

    public function token(string $path): string
    {
        $body = $this->get($path)['body'];
        if (preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m)) {
            return $m[1];
        }
        return '';
    }

    public function post(string $formPage, string $action, array $fields): array
    {
        return $this->request('POST', $action, $fields + ['_csrf' => $this->token($formPage)]);
    }

    public function api(string $tokenPage, string $path, array $fields): array
    {
        $t = $this->token($tokenPage);
        $r = $this->request('POST', $path, $fields + ['_csrf' => $t], ['X-CSRF-Token: ' . $t]);
        $r['json'] = json_decode($r['body'], true) ?: [];
        return $r;
    }

    public function signIn(string $kind, string $email, string $password): array
    {
        return $this->post('/login.php', '/login.php', ['kind' => $kind, 'email' => $email, 'password' => $password]);
    }
}

function flash_text(string $html): string
{
    return preg_match('/class="toast[^"]*"[^>]*>.*?<p[^>]*>(.*?)<\/p>/s', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
}

function redirected_to(array $r, string $path): bool
{
    return in_array($r['code'], [302, 303], true) && str_contains($r['location'], $path);
}

function outbox_files(string $dir): array
{
    return glob($dir . '/*.txt') ?: [];
}

/** The newest outbox message to this address written after $before (a list of files that already existed). */
function new_mail(string $dir, array $before, string $to): ?string
{
    foreach (array_diff(outbox_files($dir), $before) as $file) {
        $text = (string) file_get_contents($file);
        if (stripos($text, "To: $to\n") === 0) {
            return $text;
        }
    }
    return null;
}

function reset_path(?string $mail): ?string
{
    return $mail && preg_match('#/reset_password\.php\?token=[0-9a-f]{64}#', $mail, $m) ? $m[0] : null;
}

function next_open_day(): string
{
    $d = new DateTimeImmutable('today');
    while ((int) $d->format('N') > 5) {
        $d = $d->modify('+1 day');
    }
    return $d->format('Y-m-d');
}

if ($adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "OFFICE_ADMIN_EMAIL or OFFICE_ADMIN_PASSWORD is empty in .env.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Counts and consistency, straight from the database (as the site's own role)
// ---------------------------------------------------------------------------
echo "Counts and consistency\n";
$books = q_all('SELECT * FROM fn_list_books(NULL, NULL, false, true, 500, 0)');
$counts = ['none' => 0, 'low' => 0, 'available' => 0];
$bad = [];
foreach ($books as $b) {
    if ($b['is_archived']) {
        continue;
    }
    $counts[$b['availability']]++;
    if ((int) $b['copies_total'] - (int) $b['copies_available'] !== (int) $b['on_loan'] + (int) $b['reserved']) {
        $bad[] = $b['title'];
    }
    if ((int) $b['copies_total'] > (int) $b['copy_limit']) {
        $bad[] = $b['title'] . ' (over its limit)';
    }
}
$people = q_row("SELECT count(*) FILTER (WHERE borrower_type = 'student') AS students, count(*) FILTER (WHERE borrower_type = 'teacher') AS teachers,
                        sum(active_loans) AS open_loans FROM v_borrower_status");
$categories = count(q_all('SELECT category FROM fn_list_categories(true)'));
printf("  %d titles in %d categories; %d students and %d teachers; %d open loans.\n", count($books), $categories,
    $people['students'], $people['teachers'], $people['open_loans']);
printf("  On the shelf: %d titles at 0, %d at 1, %d at 2 or more.\n", $counts['none'], $counts['low'], $counts['available']);
check('copies_total - copies_available = open loans + approved requests, and no title is over its limit', !$bad, $bad ? implode(', ', array_slice($bad, 0, 5)) : count($books) . ' titles checked');

// ---------------------------------------------------------------------------
// Start the server with the outbox, whatever .env says
// ---------------------------------------------------------------------------
$log = tempnam(sys_get_temp_dir(), 'smokesrv');
$env = array_merge(getenv(), ['MAIL_DRIVER' => 'outbox', 'SESSION_DRIVER' => $session]);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', APP_ROOT . '/public'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, APP_ROOT, $env);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start php -S.\n");
    exit(1);
}
register_shutdown_function(static function () use ($server, $log) {
    proc_terminate($server);
    @unlink($log);
});
$up = false;
for ($i = 0; $i < 40 && !$up; $i++) {
    usleep(250000);
    $ch = curl_init("$base/health.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $up = str_starts_with((string) curl_exec($ch), 'OK');
    unset($ch);
}
echo "\nSite on $base (MAIL_DRIVER=outbox, SESSION_DRIVER=$session)\n";
if (!check('php -S answers and health.php says OK', $up)) {
    exit(1);
}

// ---------------------------------------------------------------------------
// The sign-in page
// ---------------------------------------------------------------------------
$guest = new Browser($base);
$member = $guest->get('/login.php')['body'];
$staffTab = $guest->get('/login.php?kind=staff')['body'];
check('sign-in page: "Forgot your password?" on both tabs, "Create an account" on the Member tab only',
    str_contains($member, 'href="/forgot_password.php"') && str_contains($staffTab, 'href="/forgot_password.php"')
    && (bool) preg_match('/data-type-section="member">\s*New to the library\? <a href="\/signup.php">Create an account/', $member)
    && (bool) preg_match('/data-type-section="member" hidden>/', $staffTab)
    && !str_contains($member, 'Ask the library desk'));

// ---------------------------------------------------------------------------
// Two new students: sign up, sign in, the catalog
// ---------------------------------------------------------------------------
$stamp = date('His') . random_int(10, 99);
$email = "smoke.student$stamp@school.edu";
$password = 'Smoke-' . bin2hex(random_bytes(6));
$s = new Browser($base);
$r = $s->post('/signup.php', '/signup.php', ['user_type' => 'student', 'first_name' => 'Smoke', 'last_name' => 'Tester', 'course' => 'BS Information Technology',
    'year_level' => '1', 'email' => $email, 'password' => $password, 'confirm_password' => $password]);
check('Sign Up as a new student', redirected_to($r, '/login.php') && str_contains(flash_text($s->follow($r)['body']), 'Account created'));
$r = $s->signIn('member', $email, $password);
check('the new student signs in', redirected_to($r, '/member/home.php'), $r['location']);
$studentId = (int) q_val('SELECT user_id FROM fn_list_students(:e, true, 1, 0)', [':e' => $email]);
// A second student, who borrows the books the first one then asks for.
$email2 = "smoke.second$stamp@school.edu";
$password2 = 'Smoke-' . bin2hex(random_bytes(6));
(new Browser($base))->post('/signup.php', '/signup.php', ['user_type' => 'student', 'first_name' => 'Smoke', 'last_name' => 'Second',
    'course' => 'BS Biology', 'year_level' => '2', 'email' => $email2, 'password' => $password2, 'confirm_password' => $password2]);
$student2 = (int) q_val('SELECT user_id FROM fn_list_students(:e, true, 1, 0)', [':e' => $email2]);

$page1 = $s->get('/member/catalog.php')['body'];
$page2 = $s->get('/member/catalog.php?page=2')['body'];
$found = preg_match('/of (\d+) books/', $page1, $m) ? (int) $m[1] : 0;
check('the titles are in the Catalog, with paging', $found >= 200 && str_contains($page2, 'Page 2 of') && $page1 !== $page2, "$found books");
$search = $s->get('/member/catalog.php?q=' . rawurlencode('Thermodynamics'))['body'];
check('Catalog search finds a title', str_contains($search, 'Thermodynamics: An Engineering Approach') && str_contains($search, 'of 1 book'));
$health = $s->get('/member/catalog.php?view=table&category=' . rawurlencode('Health Sciences'))['body'];
check('Catalog category filter', str_contains($health, 'Fundamentals of Nursing') && !str_contains($health, 'Thermodynamics') && str_contains($health, 'of 10 books'));

// ---------------------------------------------------------------------------
// The office admin lends the only copy of a title to the second student
// ---------------------------------------------------------------------------
$m = new Browser($base);
$r = $m->signIn('staff', $adminEmail, $adminPassword);
check('the office admin signs in', redirected_to($r, '/librarian/dashboard.php'));
$pharma = (int) q_val("SELECT book_id FROM fn_list_books('Basic & Clinical Pharmacology', NULL, false, false, 1, 0)");
$m->post('/librarian/issue.php', '/librarian/issue.php', ['action' => 'issue_direct', 'borrower' => "student:$student2", 'book_id' => (string) $pharma,
    'back' => '/librarian/issue.php']);

// An available title can be requested; a title whose only copy is out can be requested too, and waits.
$campbell = (int) q_val("SELECT book_id FROM fn_list_books('Campbell Biology', NULL, false, false, 1, 0)");
$r = $s->post('/member/catalog.php', '/member/catalog.php', ['book_id' => (string) $campbell]);
check('an available title can be requested', str_contains(flash_text($s->follow($r)['body']), 'You requested Campbell Biology'));
$r = $s->post('/member/catalog.php', '/member/catalog.php', ['book_id' => (string) $pharma]);
check('a title with no copy on the shelf can be requested and waits', str_contains(flash_text($s->follow($r)['body']), 'You requested Basic & Clinical Pharmacology'));

$r = $s->post('/member/home.php', '/logout.php', []);
$audit = q_row("SELECT count(*) FILTER (WHERE action = 'login') AS logins, count(*) FILTER (WHERE action = 'logout') AS logouts
                  FROM audit_log WHERE actor_type = 'student' AND actor_id = :id", [':id' => $studentId]);
check('sign out, and audit_log has the login and logout rows', redirected_to($r, '/login.php') && (int) $audit['logins'] >= 1 && (int) $audit['logouts'] >= 1,
    "login {$audit['logins']}, logout {$audit['logouts']}");

// ---------------------------------------------------------------------------
// The office admin: approve, issue, return; a lost copy; the empty title
// ---------------------------------------------------------------------------
$requestId = (int) q_val("SELECT request_id FROM v_request_detail WHERE borrower_email = :e AND book_id = :b AND status = 'pending'",
    [':e' => $email, ':b' => $campbell]);
$day = next_open_day();
$approve = $m->api('/librarian/requests.php', '/api/request_decide.php', ['action' => 'approve', 'request_id' => (string) $requestId,
    'pickup_date' => $day, 'pickup_time' => '09:00', 'subject' => 'Your request for Campbell Biology is approved',
    'body' => 'Hi Smoke, please pick up Campbell Biology at the library desk. Bring your school ID.']);
$waitId = (int) q_val("SELECT request_id FROM v_request_detail WHERE borrower_email = :e AND book_id = :b AND status = 'pending'",
    [':e' => $email, ':b' => $pharma]);
$noCopy = $m->api('/librarian/requests.php', '/api/request_decide.php', ['action' => 'approve', 'request_id' => (string) $waitId,
    'pickup_date' => $day, 'pickup_time' => '09:00', 'subject' => 'Ready', 'body' => 'Ready for pickup.']);
$r = $m->post('/librarian/issue.php', '/librarian/issue.php', ['action' => 'issue_request', 'request_id' => (string) $requestId, 'back' => '/librarian/issue.php']);
$issued = flash_text($m->follow($r)['body']);
$loanId = (int) q_val("SELECT transaction_id FROM fn_list_transactions('active', NULL, NULL, NULL, :s, NULL, 5, 0) WHERE book_id = :b",
    [':s' => $studentId, ':b' => $campbell]);
$return = $m->api('/librarian/issued.php', '/api/loan_return.php', ['transaction_id' => (string) $loanId, 'condition' => 'good']);
check('the admin approves the request, issues the book and returns it',
    ($approve['json']['ok'] ?? false) && str_contains($issued, 'Issued Campbell Biology') && ($return['json']['ok'] ?? false),
    trim(($approve['json']['error'] ?? '') . ' ' . $issued . ' ' . ($return['json']['message'] ?? $return['json']['error'] ?? '')));
check('a waiting request cannot be approved while no copy is free', ($noCopy['json']['ok'] ?? true) === false
    && str_contains((string) ($noCopy['json']['error'] ?? ''), 'No copy of'));

// The only copy of another title is lost; then the title cannot be requested at all.
$brock = (int) q_val("SELECT book_id FROM fn_list_books('Brock Biology of Microorganisms', NULL, false, false, 1, 0)");
$m->post('/librarian/issue.php', '/librarian/issue.php', ['action' => 'issue_direct', 'borrower' => "student:$student2", 'book_id' => (string) $brock,
    'back' => '/librarian/issue.php']);
$lostLoan = (int) q_val("SELECT transaction_id FROM fn_list_transactions('active', NULL, NULL, NULL, :s, NULL, 5, 0) WHERE book_id = :b",
    [':s' => $student2, ':b' => $brock]);
$lost = $m->api('/librarian/issued.php', '/api/loan_return.php', ['transaction_id' => (string) $lostLoan, 'condition' => 'lost']);
$s->signIn('member', $email, $password);
$r = $s->post('/member/catalog.php', '/member/catalog.php', ['book_id' => (string) $brock]);
$refused = flash_text($s->follow($r)['body']);
check('an empty title (every copy lost) cannot be requested', ($lost['json']['ok'] ?? false) && str_contains($refused, 'no copies left'), $refused);

// ---------------------------------------------------------------------------
// Pages with the new data; admin-only pages; a librarian is turned away from them
// ---------------------------------------------------------------------------
$users = $m->get('/librarian/users.php?type=student&status=all&q=smoke')['body'];
$fines = $m->get('/librarian/fines.php?tab=all')['body'];
$issuedPage = $m->get('/librarian/issued.php?tab=borrowed')['body'];
check('Manage Users, Fines and Issued Books show the new data',
    str_contains($users, 'Smoke Tester') && str_contains($users, 'Smoke Second') && str_contains($fines, 'Smoke Second')
    && str_contains($issuedPage, 'Basic &amp; Clinical Pharmacology'));
$settings = $m->get('/librarian/settings.php');
$activity = $m->get('/librarian/activity.php?all=1');
check('the admin opens Settings and Activity', $settings['code'] === 200 && $activity['code'] === 200 && str_contains($activity['body'], 'signed in'));

// The admin adds a librarian (temporary password, shown once); the librarian sets a password, then is refused admin pages.
$librarianEmail = "smoke.librarian$stamp@lms.edu";
$added = $m->api('/librarian/users.php?type=staff', '/api/user_save.php', ['mode' => 'add', 'user_type' => 'staff', 'first_name' => 'Smoke',
    'last_name' => 'Librarian', 'email' => $librarianEmail, 'position' => 'librarian']);
$temporary = (string) ($added['json']['secret']['password'] ?? '');
$lib = new Browser($base);
$r = $lib->signIn('staff', $librarianEmail, $temporary);
$libPassword = 'Smoke-' . bin2hex(random_bytes(6));
$changed = $lib->post('/change_password.php', '/change_password.php', ['current_password' => $temporary, 'new_password' => $libPassword,
    'confirm_password' => $libPassword]);
$forbidden = [$lib->get('/librarian/settings.php'), $lib->get('/librarian/activity.php')];
check('a new librarian must change the temporary password, then gets the 403 page on admin pages',
    ($added['json']['ok'] ?? false) && redirected_to($r, '/change_password.php') && in_array($changed['code'], [302, 303], true)
    && $forbidden[0]['code'] === 403 && $forbidden[1]['code'] === 403 && str_contains($forbidden[0]['body'], 'This page is not for your account'));

// ---------------------------------------------------------------------------
// Forgot password (outbox)
// ---------------------------------------------------------------------------
$f = new Browser($base);
$before = outbox_files($outbox);
$r = $f->post('/forgot_password.php', '/forgot_password.php', ['email' => $email]);
$neutral = 'If that email belongs to an account, we sent a reset link. It works for 30 minutes.';
$mail = new_mail($outbox, $before, $email);
$link = reset_path($mail);
check('forgot password for the student writes the link to storage/outbox', str_contains($r['body'], $neutral) && $link !== null);
$newPassword = 'Smoke-' . bin2hex(random_bytes(6));
$before = outbox_files($outbox);
$r = $f->post((string) $link, '/reset_password.php', ['token' => substr((string) $link, -64), 'new_password' => $newPassword, 'confirm_password' => $newPassword]);
check('the link resets the password', redirected_to($r, '/login.php') && str_contains(flash_text($f->follow($r)['body']), 'Password changed'));
$notice = new_mail($outbox, $before, $email);
check('a "Your password was changed" notice is written', $notice !== null && str_contains($notice, 'Subject: Your password was changed'));
check('the new password signs in', redirected_to((new Browser($base))->signIn('member', $email, $newPassword), '/member/home.php'));
$old = (new Browser($base))->signIn('member', $email, $password);
check('the old password fails', $old['code'] === 200 && str_contains($old['body'], 'Wrong email or password'));
check('the same link a second time is refused', str_contains($f->get((string) $link)['body'], 'This reset link has expired or was already used'));
$before = outbox_files($outbox);
$r = $f->post('/forgot_password.php', '/forgot_password.php', ['email' => 'nobody.' . $stamp . '@school.edu']);
check('an unknown email gets the same neutral message and no email', str_contains($r['body'], $neutral) && array_diff(outbox_files($outbox), $before) === []);
$before = outbox_files($outbox);
$f->post('/forgot_password.php', '/forgot_password.php', ['email' => $librarianEmail]);
$staffLink = reset_path(new_mail($outbox, $before, $librarianEmail));
check('forgot password also works for a staff account', $staffLink !== null && str_contains($f->get($staffLink)['body'], 'Hi Smoke'));

// ---------------------------------------------------------------------------
// Lock-out last: it also locks 127.0.0.1 for 15 minutes
// ---------------------------------------------------------------------------
// A good sign-in first, so only these tries count for this address (the lock counts failures since the last success).
(new Browser($base))->signIn('staff', $adminEmail, $adminPassword);
$l = new Browser($base);
$messages = [];
for ($i = 1; $i <= 6; $i++) {
    $r = $l->signIn('member', $email2, 'not-the-password-' . $i);
    $messages[] = preg_match('/id="login-error">.*?<p>(.*?)<\/p>/s', $r['body'], $mm) ? html_entity_decode($mm[1]) : '';
}
check('five wrong passwords lock the account; the 6th try is refused',
    str_contains($messages[4], 'now locked for 15 minutes') && str_contains($messages[5], 'Too many failed sign-ins'));

$failed = array_filter($results, fn($r) => !$r[1]);
printf("\n%d of %d checks passed.\n", count($results) - count($failed), count($results));
echo "The smoke test changed data and locked sign-in from 127.0.0.1. Run database\\reset.ps1 now.\n";
exit($failed ? 1 : 0);
