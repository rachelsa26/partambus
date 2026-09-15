<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/auth/login.php');
    }
    return $user;
}

/** @param string[] $roles */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('Akses ditolak. Halaman ini khusus untuk role: ' . e(implode(', ', $roles)) . '.');
    }
    return $user;
}

function is_owner(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'owner';
}

function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, username, password_hash, full_name, role, active FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
    ];

    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
