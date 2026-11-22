<?php
declare(strict_types=1);

/*
 * Check every ISBN in the lms database:
 *     php database/tools/verify_isbns.php            one line per problem, then a summary
 *     php database/tools/verify_isbns.php --all      one line per book
 *     php database/tools/verify_isbns.php --json     the ISBNs that did not verify, as a JSON list
 * 1. The check digit (the same rule as Add New Book).
 * 2. Open Library (https://openlibrary.org/api/books, 40 ISBNs per request) must know the ISBN, and its title must
 *    match ours. When Open Library does not know an ISBN, Google Books is asked instead (it may refuse when its
 *    free daily quota is used up; then the ISBN counts as not verified).
 * Only public book identifiers are sent. Exit code 0 when every ISBN verified, 1 otherwise.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../src/config.php';
require __DIR__ . '/../../src/helpers.php';
require __DIR__ . '/../../src/db.php';
require __DIR__ . '/../../src/books.php';

const USER_AGENT = 'LibraryInventorySystem-isbn-check/1.0 (school project)';
$showAll = in_array('--all', $argv, true);
$asJson = in_array('--json', $argv, true);

try {
    $books = q_all("SELECT book_id, isbn, title, author FROM fn_list_books(NULL, NULL, false, true, 500, 0) WHERE isbn IS NOT NULL ORDER BY book_id");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the books. Check that PostgreSQL is running and .env is filled in.\n");
    exit(1);
}

/** GET a JSON document. Returns [status, decoded or null]. Certificate checks stay on. */
function get_json(string $url): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => USER_AGENT,
             CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
    if (PHP_OS_FAMILY === 'Windows' && trim((string) ini_get('curl.cainfo')) === '' && defined('CURLSSLOPT_NATIVE_CA')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    unset($ch);
    return [$status, $body === false ? null : json_decode((string) $body, true)];
}

/** Lower-case words without punctuation or accents, for comparing titles. */
function words(string $s): array
{
    static $accents = ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', '’' => "'"];
    $s = strtr(mb_strtolower($s), $accents);
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return array_values(array_filter(explode(' ', $s), fn($w) => $w !== '' && !in_array($w, ['the', 'a', 'an', 'and', 'of'], true)));
}

/** Our title and theirs match when the main titles (before a colon) share most words. */
function titles_match(string $ours, string $theirs): bool
{
    $a = words(explode(':', $ours)[0]);
    $b = words(explode(':', $theirs)[0]);
    if (!$a || !$b) {
        return false;
    }
    $common = count(array_intersect($a, $b));
    return $common / min(count($a), count($b)) >= 0.75;
}

$results = [];
$byDigits = [];
foreach ($books as $b) {
    $digits = str_replace('-', '', (string) $b['isbn']);
    if (isbn_normalize($digits) === null) {
        $results[$b['book_id']] = ['BAD CHECK DIGIT', $b, ''];
        continue;
    }
    $byDigits[$digits] = $b;
}

// Open Library, 40 at a time.
foreach (array_chunk(array_keys($byDigits), 40) as $chunk) {
    $keys = implode(',', array_map(fn($d) => 'ISBN:' . $d, $chunk));
    [$status, $data] = get_json('https://openlibrary.org/api/books?' . http_build_query(['bibkeys' => $keys, 'format' => 'json', 'jscmd' => 'data']));
    if ($status !== 200 || !is_array($data)) {
        fwrite(STDERR, "Open Library did not answer (HTTP $status). Try again later.\n");
        exit(1);
    }
    foreach ($chunk as $d) {
        $b = $byDigits[$d];
        $hit = $data['ISBN:' . $d] ?? null;
        if ($hit && trim((string) ($hit['title'] ?? '')) !== '') {   // a record without a title cannot confirm anything
            $theirs = trim(($hit['title'] ?? '') . (isset($hit['subtitle']) ? ': ' . $hit['subtitle'] : ''));
            $results[$b['book_id']] = [titles_match($b['title'], $theirs) ? 'OK' : 'MISMATCH', $b, 'Open Library: ' . $theirs];
        }
    }
    usleep(300000);
}

// Google Books for the ones Open Library does not know.
$googleBlocked = false;
foreach ($byDigits as $d => $b) {
    if (isset($results[$b['book_id']])) {
        continue;
    }
    if ($googleBlocked) {
        $results[$b['book_id']] = ['NOT FOUND', $b, 'not in Open Library; Google Books quota used up'];
        continue;
    }
    [$status, $data] = get_json('https://www.googleapis.com/books/v1/volumes?q=isbn:' . $d);
    if ($status === 429 || $status === 403) {
        $googleBlocked = true;
        $results[$b['book_id']] = ['NOT FOUND', $b, 'not in Open Library; Google Books quota used up'];
        continue;
    }
    $item = $data['items'][0]['volumeInfo'] ?? null;
    if (!$item) {
        $results[$b['book_id']] = ['NOT FOUND', $b, 'not in Open Library or Google Books'];
        continue;
    }
    $theirs = trim(($item['title'] ?? '') . (isset($item['subtitle']) ? ': ' . $item['subtitle'] : ''));
    $results[$b['book_id']] = [titles_match($b['title'], $theirs) ? 'OK' : 'MISMATCH', $b, 'Google Books: ' . $theirs];
    usleep(300000);
}

ksort($results);
$failed = array_filter($results, fn($r) => $r[0] !== 'OK');
if ($asJson) {
    echo json_encode(array_values(array_map(fn($r) => str_replace('-', '', (string) $r[1]['isbn']), $failed))), "\n";
    exit($failed ? 1 : 0);
}
foreach ($results as [$verdict, $b, $note]) {
    if ($showAll || $verdict !== 'OK') {
        printf("%-16s %s  %s%s\n", $verdict, $b['isbn'], $b['title'], $note !== '' ? "  [$note]" : '');
    }
}
$counts = array_count_values(array_map(fn($r) => $r[0], $results));
$withoutIsbn = (int) q_val('SELECT count(*) FROM fn_list_books(NULL, NULL, false, true, 500, 0) WHERE isbn IS NULL');
printf("\n%d ISBNs checked: %d verified, %d mismatched, %d not found, %d with a bad check digit. %d titles have no ISBN.\n",
    count($results), $counts['OK'] ?? 0, $counts['MISMATCH'] ?? 0, $counts['NOT FOUND'] ?? 0, $counts['BAD CHECK DIGIT'] ?? 0, $withoutIsbn);
exit($failed ? 1 : 0);
