<?php

declare(strict_types=1);

function db_connect(array $config): PDO
{
    $timeout = isset($config['timeout']) ? max(2, (int) $config['timeout']) : 5;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['name'],
        $config['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => $timeout,
    ];

    if (!empty($config['ssl_ca'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'];
    }

    return new PDO($dsn, $config['user'], $config['pass'], $options);
}
