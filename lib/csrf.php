<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Sesi tidak valid (CSRF check gagal). Silakan kembali dan coba lagi.');
    }
}
