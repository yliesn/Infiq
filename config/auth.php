<?php
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/helpers.php';

function require_auth(): array {
    $token = get_bearer_token();
    if (!$token) json_response(['error' => 'Non autorisé'], 401);
    $payload = jwt_verify($token);
    if (!$payload) json_response(['error' => 'Token invalide ou expiré'], 401);
    return $payload;
}
