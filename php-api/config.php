<?php

declare(strict_types=1);

/**
 * Lightweight .env loader for XAMPP/shared hosting.
 * OS environment variables always have priority over .env values.
 */
function load_env_file(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $vars = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }

    return $vars;
}

function env_value(string $key, $default = null)
{
    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }
    global $fileEnv;
    if (isset($fileEnv[$key]) && $fileEnv[$key] !== '') {
        return $fileEnv[$key];
    }
    return $default;
}

$fileEnv = load_env_file(__DIR__ . '/.env');

return [
    'app_env' => (string) env_value('APP_ENV', 'development'),
    'jwt_secret' => (string) env_value('JWT_SECRET', 'change-this-jwt-secret-in-production'),
    'admin_mfa_code' => (string) env_value('ADMIN_MFA_CODE', '123456'),
    'admin_require_mfa' => (bool) ((int) env_value('ADMIN_REQUIRE_MFA', 0)),
    'cors_origins' => array_filter(array_map('trim', explode(',', (string) env_value(
        'CORS_ORIGINS',
        'http://localhost,http://127.0.0.1,http://localhost:5173,http://localhost:5174,http://127.0.0.1:5173,http://127.0.0.1:5174'
    )))),
    'db' => [
        'host' => (string) env_value('DB_HOST', '127.0.0.1'),
        'port' => (string) env_value('DB_PORT', '3306'),
        'name' => (string) env_value('DB_DATABASE', 'premium_furniture'),
        'user' => (string) env_value('DB_USERNAME', 'root'),
        'pass' => (string) env_value('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'timeout' => (int) env_value('DB_TIMEOUT', 5),
        'ssl_ca' => (string) env_value('DB_SSL_CA', ''),
    ],
];
