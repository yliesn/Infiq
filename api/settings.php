<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

send_cors();
require_auth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM company_settings WHERE id = 1');
    json_response($stmt->fetch());
    return;
}

if ($method === 'PUT') {
    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $fields = [
        'name', 'address_line1', 'address_line2', 'city', 'postal_code', 'country',
        'siret', 'vat_number', 'vat_regime', 'iban', 'payment_terms', 'legal_footer',
        'quote_prefix', 'invoice_prefix',
    ];

    $sets   = [];
    $params = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $b)) {
            $sets[]        = "`$f` = :$f";
            $params[":$f"] = $b[$f];
        }
    }

    if (empty($sets)) json_response(['error' => 'Aucun champ à mettre à jour'], 400);

    $params[':id'] = 1;
    $pdo->prepare('UPDATE company_settings SET ' . implode(', ', $sets) . ' WHERE id = :id')
        ->execute($params);

    $stmt = $pdo->query('SELECT * FROM company_settings WHERE id = 1');
    json_response($stmt->fetch());
    return;
}

json_response(['error' => 'Méthode non autorisée'], 405);
