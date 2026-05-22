<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/helpers.php';

send_cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Méthode non autorisée'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);

$email    = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if (!$email || !$password) {
    json_response(['error' => 'Champs manquants'], 400);
}

$pdo = getDB();

$stmt = $pdo->prepare('
    SELECT id, email, password_hash
    FROM users
    WHERE email = ?
    LIMIT 1
');

$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response(['error' => 'Identifiants invalides'], 401);
}

$token = jwt_create([
    'sub'   => $user['id'],
    'email' => $user['email']
]);

json_response([
    'token' => $token,
    'user'  => [
        'id'    => $user['id'],
        'email' => $user['email']
    ]
]);