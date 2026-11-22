<?php
declare(strict_types=1);

/*
 * CSRF protection. Every form includes csrf_field(); JavaScript sends the token in the X-CSRF-Token header.
 * bootstrap.php calls csrf_check() for every POST, so no page can forget it.
 */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = csrf_token();
    return is_string($sent) && $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
}

/** Refuse any POST without a valid token. */
function csrf_check(): void
{
    if (!is_post() || csrf_valid()) {
        return;
    }
    if (is_api_request()) {
        json_out(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
    }
    http_response_code(400);
    render_message_page(
        'This form expired',
        'For your safety, forms stop working after you sign out or wait too long. Go back, reload the page and try again.',
        ['href' => current_url(), 'label' => 'Reload the page']
    );
}

/** Rotate the token, for example after signing in. */
function csrf_rotate(): void
{
    unset($_SESSION['csrf']);
}
