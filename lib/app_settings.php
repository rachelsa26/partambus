<?php
declare(strict_types=1);

function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

/** @return array<string,string> */
function get_all_settings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings ORDER BY setting_key');
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}
