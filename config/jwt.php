<?php

define('JWT_SECRET', 'CHANGE_THIS_SECRET_KEY_MIN_32_CHARS!!');
define('JWT_EXPIRY', 3600); // 1 heure

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_create(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time();
    $payload = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    return "$header.$payload.$signature";
}

function jwt_verify(string $token): array|false {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;

    [$header, $payload, $signature] = $parts;

    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $signature)) return false;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data || $data['exp'] < time()) return false;

    return $data;
}

function get_bearer_token(): string|false {
    // Priorité au header Authorization
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) return $m[1];

    // Fallback : query string ?token= (pour les liens directs PDF)
    if (!empty($_GET['token'])) return $_GET['token'];

    return false;
}
