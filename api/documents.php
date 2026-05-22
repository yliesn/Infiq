<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

send_cors();
require_auth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? null;

match (true) {
    $method === 'GET'                             => handle_get($pdo, $id),
    $method === 'POST' && $action === 'convert'   => handle_convert($pdo, $id),
    $method === 'POST'                            => handle_post($pdo),
    $method === 'PUT'   && $id !== null           => handle_put($pdo, $id),
    $method === 'PATCH' && $id !== null           => handle_patch($pdo, $id),
    $method === 'DELETE' && $id !== null          => handle_delete($pdo, $id),
    default                                       => json_response(['error' => 'Requête invalide'], 400),
};

// ─── GET ──────────────────────────────────────────────────────────────────────

function handle_get(PDO $pdo, ?int $id): void {
    if ($id) {
        $doc = get_document($pdo, $id);
        if (!$doc) json_response(['error' => 'Document introuvable'], 404);
        json_response($doc);
    }

    $where  = [];
    $params = [];

    if (!empty($_GET['type'])) {
        $where[]  = 'd.type = ?';
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['status'])) {
        $where[]  = 'd.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['client_id'])) {
        $where[]  = 'd.client_id = ?';
        $params[] = (int)$_GET['client_id'];
    }

    $sql = '
        SELECT d.id, d.type, d.number, d.status, d.issue_date, d.expiry_date, d.due_date,
               d.subtotal_ht, d.vat_amount, d.total_ttc, d.created_at, d.locked_at,
               d.parent_quote_id,
               c.company_name, c.first_name, c.last_name
        FROM documents d
        JOIN clients c ON c.id = d.client_id
        ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
        ORDER BY d.created_at DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

// ─── POST (create) ─────────────────────────────────────────────────────────────

function handle_post(PDO $pdo): void {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];

    $type = $b['type'] ?? '';
    if (!in_array($type, ['quote', 'invoice'], true)) json_response(['error' => 'type invalide (quote|invoice)'], 422);
    if (empty($b['client_id']))  json_response(['error' => 'client_id requis'], 422);
    if (empty($b['issue_date'])) json_response(['error' => 'issue_date requis'], 422);

    $lines = $b['lines'] ?? [];
    if (empty($lines)) json_response(['error' => 'Au moins une ligne requise'], 422);

    $pdo->beginTransaction();
    try {
        $number = next_document_number($pdo, $type);
        [$subtotal_ht, $vat_amount, $total_ttc] = calc_totals($lines);

        $stmt = $pdo->prepare('
            INSERT INTO documents
                (type, number, client_id, status, issue_date, expiry_date, due_date,
                 subtotal_ht, vat_amount, total_ttc, notes, conditions)
            VALUES
                (:type, :number, :client_id, "draft", :issue_date, :expiry_date, :due_date,
                 :subtotal_ht, :vat_amount, :total_ttc, :notes, :conditions)
        ');
        $stmt->execute([
            ':type'        => $type,
            ':number'      => $number,
            ':client_id'   => (int)$b['client_id'],
            ':issue_date'  => $b['issue_date'],
            ':expiry_date' => $b['expiry_date'] ?? null,
            ':due_date'    => $b['due_date']    ?? null,
            ':subtotal_ht' => $subtotal_ht,
            ':vat_amount'  => $vat_amount,
            ':total_ttc'   => $total_ttc,
            ':notes'       => $b['notes']       ?? null,
            ':conditions'  => $b['conditions']  ?? null,
        ]);
        $doc_id = (int)$pdo->lastInsertId();

        insert_lines($pdo, $doc_id, $lines);
        $pdo->commit();

        json_response(get_document($pdo, $doc_id), 201);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ─── PUT (update) ──────────────────────────────────────────────────────────────

function handle_put(PDO $pdo, int $id): void {
    $doc = get_document($pdo, $id);
    if (!$doc) json_response(['error' => 'Document introuvable'], 404);
    if ($doc['locked_at']) json_response(['error' => 'Document verrouillé, modification impossible'], 403);

    $b = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($b['client_id']))  json_response(['error' => 'client_id requis'], 422);
    if (empty($b['issue_date'])) json_response(['error' => 'issue_date requis'], 422);

    $lines = $b['lines'] ?? [];
    if (empty($lines)) json_response(['error' => 'Au moins une ligne requise'], 422);

    $pdo->beginTransaction();
    try {
        [$subtotal_ht, $vat_amount, $total_ttc] = calc_totals($lines);

        $pdo->prepare('
            UPDATE documents SET
                client_id=:client_id, issue_date=:issue_date, expiry_date=:expiry_date,
                due_date=:due_date, subtotal_ht=:subtotal_ht, vat_amount=:vat_amount,
                total_ttc=:total_ttc, notes=:notes, conditions=:conditions
            WHERE id = :id
        ')->execute([
            ':client_id'   => (int)$b['client_id'],
            ':issue_date'  => $b['issue_date'],
            ':expiry_date' => $b['expiry_date'] ?? null,
            ':due_date'    => $b['due_date']    ?? null,
            ':subtotal_ht' => $subtotal_ht,
            ':vat_amount'  => $vat_amount,
            ':total_ttc'   => $total_ttc,
            ':notes'       => $b['notes']       ?? null,
            ':conditions'  => $b['conditions']  ?? null,
            ':id'          => $id,
        ]);

        $pdo->prepare('DELETE FROM document_lines WHERE document_id = ?')->execute([$id]);
        insert_lines($pdo, $id, $lines);

        $pdo->commit();
        json_response(get_document($pdo, $id));
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ─── PATCH (change status) ─────────────────────────────────────────────────────

function handle_patch(PDO $pdo, int $id): void {
    $doc = get_document($pdo, $id);
    if (!$doc) json_response(['error' => 'Document introuvable'], 404);

    $b      = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = $b['status'] ?? '';

    $allowed = $doc['type'] === 'invoice'
        ? ['draft', 'sent', 'paid', 'cancelled']
        : ['draft', 'sent', 'accepted', 'refused', 'cancelled'];

    if (!in_array($status, $allowed, true)) json_response(['error' => 'Statut invalide'], 422);

    // Verrouillage : une facture est inviolable dès qu'elle est envoyée
    if ($doc['locked_at'] && !($doc['type'] === 'invoice' && $status === 'paid')) {
        json_response(['error' => 'Document verrouillé'], 403);
    }

    $locked_at = ($doc['type'] === 'invoice' && $status === 'sent') ? date('Y-m-d H:i:s') : $doc['locked_at'];

    $pdo->prepare('UPDATE documents SET status = ?, locked_at = ? WHERE id = ?')
        ->execute([$status, $locked_at, $id]);

    json_response(get_document($pdo, $id));
}

// ─── DELETE (cancel) ───────────────────────────────────────────────────────────

function handle_delete(PDO $pdo, int $id): void {
    $doc = get_document($pdo, $id);
    if (!$doc) json_response(['error' => 'Document introuvable'], 404);
    if ($doc['locked_at']) json_response(['error' => 'Document verrouillé, suppression impossible'], 403);

    $pdo->prepare('UPDATE documents SET status = "cancelled" WHERE id = ?')->execute([$id]);
    json_response(['message' => 'Document annulé']);
}

// ─── POST ?action=convert (devis → facture) ────────────────────────────────────

function handle_convert(PDO $pdo, ?int $id): void {
    if (!$id) json_response(['error' => 'id requis'], 400);

    $doc = get_document($pdo, $id);
    if (!$doc) json_response(['error' => 'Devis introuvable'], 404);
    if ($doc['type'] !== 'quote') json_response(['error' => 'Seul un devis peut être converti'], 422);
    if (in_array($doc['status'], ['refused', 'cancelled'], true)) {
        json_response(['error' => 'Statut incompatible pour la conversion'], 422);
    }

    $pdo->beginTransaction();
    try {
        $number = next_document_number($pdo, 'invoice');

        $pdo->prepare('
            INSERT INTO documents
                (type, number, client_id, status, issue_date, due_date,
                 subtotal_ht, vat_amount, total_ttc, notes, conditions, parent_quote_id)
            VALUES
                ("invoice", :number, :client_id, "draft", CURDATE(), :due_date,
                 :subtotal_ht, :vat_amount, :total_ttc, :notes, :conditions, :parent_id)
        ')->execute([
            ':number'      => $number,
            ':client_id'   => $doc['client_id'],
            ':due_date'    => $doc['due_date'],
            ':subtotal_ht' => $doc['subtotal_ht'],
            ':vat_amount'  => $doc['vat_amount'],
            ':total_ttc'   => $doc['total_ttc'],
            ':notes'       => $doc['notes'],
            ':conditions'  => $doc['conditions'],
            ':parent_id'   => $id,
        ]);
        $invoice_id = (int)$pdo->lastInsertId();

        $pdo->prepare('
            INSERT INTO document_lines
                (document_id, service_id, label, description, qty, unit_price, vat_rate, total_ht, sort_order)
            SELECT :doc_id, service_id, label, description, qty, unit_price, vat_rate, total_ht, sort_order
            FROM document_lines WHERE document_id = :src_id
        ')->execute([':doc_id' => $invoice_id, ':src_id' => $id]);

        // Marquer le devis comme accepté s'il ne l'est pas encore
        if ($doc['status'] !== 'accepted') {
            $pdo->prepare('UPDATE documents SET status = "accepted" WHERE id = ?')->execute([$id]);
        }

        $pdo->commit();
        json_response(get_document($pdo, $invoice_id), 201);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => $e->getMessage()], 500);
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function next_document_number(PDO $pdo, string $type): string {
    $year       = date('Y');
    $prefix_col = $type === 'invoice' ? 'invoice_prefix' : 'quote_prefix';
    $row        = $pdo->query("SELECT `{$prefix_col}` FROM company_settings WHERE id = 1")->fetch();
    $prefix     = $row[$prefix_col] ?? ($type === 'invoice' ? 'FAC' : 'DEV');

    $stmt = $pdo->prepare('
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(number, "-", -1) AS UNSIGNED)), 0)
        FROM documents
        WHERE type = ? AND number LIKE ?
    ');
    $stmt->execute([$type, "{$prefix}-{$year}-%"]);

    return sprintf('%s-%s-%03d', $prefix, $year, (int)$stmt->fetchColumn() + 1);
}

function calc_totals(array $lines): array {
    $subtotal_ht = 0;
    $vat_amount  = 0;

    foreach ($lines as $line) {
        $qty        = (float)($line['qty']        ?? 1);
        $unit_price = (int)($line['unit_price']   ?? 0);
        $vat_rate   = (int)($line['vat_rate']     ?? 2000);
        $total_ht   = (int)round($qty * $unit_price);

        $subtotal_ht += $total_ht;
        $vat_amount  += (int)round($total_ht * $vat_rate / 10000);
    }

    return [$subtotal_ht, $vat_amount, $subtotal_ht + $vat_amount];
}

function insert_lines(PDO $pdo, int $doc_id, array $lines): void {
    $stmt = $pdo->prepare('
        INSERT INTO document_lines
            (document_id, service_id, label, description, qty, unit_price, vat_rate, total_ht, sort_order)
        VALUES
            (:document_id, :service_id, :label, :description, :qty, :unit_price, :vat_rate, :total_ht, :sort_order)
    ');

    foreach ($lines as $i => $line) {
        $qty        = (float)($line['qty']        ?? 1);
        $unit_price = (int)($line['unit_price']   ?? 0);
        $vat_rate   = (int)($line['vat_rate']     ?? 2000);

        $stmt->execute([
            ':document_id' => $doc_id,
            ':service_id'  => isset($line['service_id']) && $line['service_id'] ? (int)$line['service_id'] : null,
            ':label'       => $line['label']       ?? '',
            ':description' => $line['description'] ?? null,
            ':qty'         => $qty,
            ':unit_price'  => $unit_price,
            ':vat_rate'    => $vat_rate,
            ':total_ht'    => (int)round($qty * $unit_price),
            ':sort_order'  => $i,
        ]);
    }
}

function get_document(PDO $pdo, int $id): array|false {
    $stmt = $pdo->prepare('
        SELECT d.*,
               c.company_name, c.first_name, c.last_name, c.email AS client_email,
               c.address_line1 AS client_address1, c.address_line2 AS client_address2,
               c.city AS client_city, c.postal_code AS client_postal, c.country AS client_country,
               c.siret AS client_siret, c.vat_number AS client_vat_number
        FROM documents d
        JOIN clients c ON c.id = d.client_id
        WHERE d.id = ?
    ');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc) return false;

    $lines = $pdo->prepare('SELECT * FROM document_lines WHERE document_id = ? ORDER BY sort_order, id');
    $lines->execute([$id]);
    $doc['lines'] = $lines->fetchAll();

    return $doc;
}
