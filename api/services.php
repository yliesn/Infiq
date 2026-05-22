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
    'DELETE' => handle_delete($pdo, $id),
    default  => json_response(['error' => 'Méthode non autorisée'], 405),
};

function handle_get(PDO $pdo, ?int $id): void {
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
        $stmt->execute([$id]);
        $s = $stmt->fetch();
        if (!$s) json_response(['error' => 'Service introuvable'], 404);
        json_response($s);
    }

    $active_only = ($_GET['active'] ?? '1') !== '0';
    $sql  = 'SELECT * FROM services' . ($active_only ? ' WHERE is_active = 1' : '') . ' ORDER BY label ASC';
    $stmt = $pdo->query($sql);
    json_response($stmt->fetchAll());
}

function handle_post(PDO $pdo): void {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    validate_service($b);

    $stmt = $pdo->prepare('
        INSERT INTO services (label, description, unit_price, vat_rate, unit)
        VALUES (:label, :description, :unit_price, :vat_rate, :unit)
    ');
    $stmt->execute(map_service($b));
    $new_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([$new_id]);
    json_response($stmt->fetch(), 201);
}

function handle_put(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    validate_service($b);

    $params       = map_service($b);
    $params[':id'] = $id;

    $stmt = $pdo->prepare('
        UPDATE services SET label=:label, description=:description,
            unit_price=:unit_price, vat_rate=:vat_rate, unit=:unit
        WHERE id = :id
    ');
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT id FROM services WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) json_response(['error' => 'Service introuvable'], 404);
    }

    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([$id]);
    json_response($stmt->fetch());
}

function handle_delete(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM document_lines WHERE service_id = ?');
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $pdo->prepare('UPDATE services SET is_active = 0 WHERE id = ?')->execute([$id]);
        json_response(['message' => 'Service désactivé (utilisé dans des documents)', 'soft' => true]);
        return;
    }

    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$id]);
    json_response(['message' => 'Service supprimé']);
}

function validate_service(array $b): void {
    if (empty($b['label'])) json_response(['error' => 'Libellé requis'], 422);
    if (!isset($b['unit_price']) || !is_numeric($b['unit_price'])) json_response(['error' => 'Prix unitaire requis (centimes)'], 422);
    if (!isset($b['vat_rate'])   || !is_numeric($b['vat_rate']))   json_response(['error' => 'Taux TVA requis (points de base)'], 422);
    if (!in_array($b['unit'] ?? '', ['heure', 'jour', 'forfait'], true)) json_response(['error' => 'Unité invalide (heure|jour|forfait)'], 422);
}

function map_service(array $b): array {
    return [
        ':label'       => trim($b['label']),
        ':description' => $b['description'] ?? null,
        ':unit_price'  => (int)$b['unit_price'],
        ':vat_rate'    => (int)$b['vat_rate'],
        ':unit'        => $b['unit'],
    ];
}
