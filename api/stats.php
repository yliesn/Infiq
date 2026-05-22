<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';

send_cors();
require_auth();

$pdo   = getDB();
$month = date('Y-m');
$year  = (int)date('Y');

$stats = [];

$stats['active_clients'] = (int)$pdo->query(
    'SELECT COUNT(*) FROM clients WHERE is_active = 1'
)->fetchColumn();

$stats['pending_quotes'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM documents WHERE type = 'quote' AND status IN ('draft','sent')"
)->fetchColumn();

$stats['unpaid_invoices'] = (int)$pdo->query(
    "SELECT COUNT(*) FROM documents WHERE type = 'invoice' AND status = 'sent'"
)->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_ttc), 0)
    FROM documents
    WHERE type = 'invoice' AND status IN ('sent','paid')
      AND DATE_FORMAT(issue_date, '%Y-%m') = ?
");
$stmt->execute([$month]);
$stats['revenue_month'] = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_ttc), 0)
    FROM documents
    WHERE type = 'invoice' AND status IN ('sent','paid')
      AND YEAR(issue_date) = ?
");
$stmt->execute([$year]);
$stats['revenue_year'] = (int)$stmt->fetchColumn();

$recent = $pdo->query("
    SELECT d.id, d.type, d.number, d.status, d.issue_date, d.total_ttc,
           c.company_name, c.first_name, c.last_name
    FROM documents d
    JOIN clients c ON c.id = d.client_id
    ORDER BY d.created_at DESC
    LIMIT 8
")->fetchAll();

$stats['recent'] = $recent;

// CA des 12 derniers mois
$rows = $pdo->query("
    SELECT DATE_FORMAT(issue_date, '%Y-%m') AS month,
           COALESCE(SUM(total_ttc), 0) AS total
    FROM documents
    WHERE type = 'invoice' AND status IN ('sent','paid')
      AND issue_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY month
    ORDER BY month
")->fetchAll();
$monthMap = [];
foreach ($rows as $r) $monthMap[$r['month']] = (int)$r['total'];
$revenueMonths = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $revenueMonths[] = ['month' => $key, 'total' => $monthMap[$key] ?? 0];
}
$stats['revenue_by_month'] = $revenueMonths;

// Répartition statuts factures
$statusRows = $pdo->query("
    SELECT status, COUNT(*) AS count
    FROM documents
    WHERE type = 'invoice'
    GROUP BY status
")->fetchAll();
$stats['invoice_status'] = array_column($statusRows, 'count', 'status');

json_response($stats);
