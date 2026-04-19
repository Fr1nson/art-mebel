<?php

declare(strict_types=1);

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $padding = 4 - (strlen($data) % 4);
    if ($padding < 4) {
        $data .= str_repeat('=', $padding);
    }
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

function jwt_sign(array $payload, string $secret, int $ttlSeconds = 28800): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $now = time();
    $payload['iat'] = $now;
    $payload['exp'] = $now + $ttlSeconds;

    $h = base64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = base64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = base64url_encode(hash_hmac('sha256', $h . '.' . $p, $secret, true));

    return $h . '.' . $p . '.' . $sig;
}

function jwt_verify(string $jwt, string $secret): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $s] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', $h . '.' . $p, $secret, true));
    if (!hash_equals($expected, $s)) {
        return null;
    }
    $payload = json_decode(base64url_decode($p), true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
        return null;
    }
    return $payload;
}
