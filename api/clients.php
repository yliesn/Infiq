<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

send_cors();
require_auth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

match ($method) {
    'GET'    => handle_get($pdo, $id),
    'POST'   => handle_post($pdo),
    'PUT'    => handle_put($pdo, $id),
    'PATCH'  => handle_patch($pdo, $id),
    'DELETE' => handle_delete($pdo, $id),
    default  => json_response(['error' => 'Méthode non autorisée'], 405),
};

function handle_get(PDO $pdo, ?int $id): void {
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $client = $stmt->fetch();
        if (!$client) json_response(['error' => 'Client introuvable'], 404);
        json_response($client);
    }

    $q           = trim($_GET['q'] ?? '');
    $active_only = ($_GET['active'] ?? '1') !== '0';
    $where       = [];
    $params      = [];

    if ($active_only) $where[] = 'is_active = 1';

    if ($q !== '') {
        $where[] = '(company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)';
        $like    = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql  = 'SELECT * FROM clients' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

function handle_post(PDO $pdo): void {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    validate_client($b);

    $stmt = $pdo->prepare('
        INSERT INTO clients
            (type, company_name, first_name, last_name, email, phone,
             address_line1, address_line2, city, postal_code, country, siret, vat_number, notes)
        VALUES
            (:type, :company_name, :first_name, :last_name, :email, :phone,
             :address_line1, :address_line2, :city, :postal_code, :country, :siret, :vat_number, :notes)
    ');
    $stmt->execute(map_client($b));
    $new_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$new_id]);
    json_response($stmt->fetch(), 201);
}

function handle_put(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    validate_client($b);

    $params       = map_client($b);
    $params[':id'] = $id;

    $stmt = $pdo->prepare('
        UPDATE clients SET
            type=:type, company_name=:company_name, first_name=:first_name, last_name=:last_name,
            email=:email, phone=:phone, address_line1=:address_line1, address_line2=:address_line2,
            city=:city, postal_code=:postal_code, country=:country, siret=:siret,
            vat_number=:vat_number, notes=:notes
        WHERE id = :id
    ');
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT id FROM clients WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) json_response(['error' => 'Client introuvable'], 404);
    }

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    json_response($stmt->fetch());
}

function handle_patch(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!isset($b['is_active'])) json_response(['error' => 'is_active requis'], 422);

    $pdo->prepare('UPDATE clients SET is_active = ? WHERE id = ?')
        ->execute([$b['is_active'] ? 1 : 0, $id]);

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) json_response(['error' => 'Client introuvable'], 404);
    json_response($client);
}

function handle_delete(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE client_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $pdo->prepare('UPDATE clients SET is_active = 0 WHERE id = ?')->execute([$id]);
        json_response(['message' => 'Client désactivé (des documents lui sont liés)', 'soft' => true]);
        return;
    }

    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
    json_response(['message' => 'Client supprimé']);
}

function validate_client(array $b): void {
    if (!in_array($b['type'] ?? '', ['pro', 'particulier'], true)) {
        json_response(['error' => 'Type invalide (pro|particulier)'], 422);
    }
    if (empty($b['first_name']) && empty($b['last_name']) && empty($b['company_name'])) {
        json_response(['error' => 'Nom ou raison sociale requis'], 422);
    }
}

function map_client(array $b): array {
    return [
        ':type'          => $b['type'],
        ':company_name'  => $b['company_name']  ?? null,
        ':first_name'    => $b['first_name']    ?? '',
        ':last_name'     => $b['last_name']     ?? '',
        ':email'         => $b['email']         ?? null,
        ':phone'         => $b['phone']         ?? null,
        ':address_line1' => $b['address_line1'] ?? null,
        ':address_line2' => $b['address_line2'] ?? null,
        ':city'          => $b['city']          ?? null,
        ':postal_code'   => $b['postal_code']   ?? null,
        ':country'       => $b['country']       ?? 'France',
        ':siret'         => $b['siret']         ?? null,
        ':vat_number'    => $b['vat_number']    ?? null,
        ':notes'         => $b['notes']         ?? null,
    ];
}
