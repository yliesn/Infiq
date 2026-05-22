<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../lib/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

send_cors();
require_auth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
if (!$id) json_response(['error' => 'id requis'], 400);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_response(['error' => 'GET uniquement'], 405);

$pdo = getDB();

// Charger le document avec les infos client
$stmt = $pdo->prepare('
    SELECT d.*,
           c.type AS client_type, c.company_name, c.first_name, c.last_name,
           c.email AS client_email, c.phone AS client_phone,
           c.address_line1 AS client_addr1, c.address_line2 AS client_addr2,
           c.city AS client_city, c.postal_code AS client_postal,
           c.country AS client_country,
           c.siret AS client_siret, c.vat_number AS client_vat
    FROM documents d
    JOIN clients c ON c.id = d.client_id
    WHERE d.id = ?
');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) json_response(['error' => 'Document introuvable'], 404);

// Lignes
$lines = $pdo->prepare('SELECT * FROM document_lines WHERE document_id = ? ORDER BY sort_order, id');
$lines->execute([$id]);
$doc['lines'] = $lines->fetchAll();

// Paramètres société
$company = $pdo->query('SELECT * FROM company_settings WHERE id = 1')->fetch();

// ─── Génération HTML ─────────────────────────────────────────────────────────

$is_invoice = $doc['type'] === 'invoice';
$type_label = $is_invoice ? 'FACTURE' : 'DEVIS';
$color      = $is_invoice ? '#1a56db' : '#08b29e';

function fmt_money(int $cents): string {
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}
function fmt_date(?string $s): string {
    if (!$s) return '—';
    [$y, $m, $d] = explode('-', $s);
    return "$d/$m/$y";
}
function h(mixed $s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function fmt_vat(int $bp): string {
    return number_format($bp / 100, 2, ',', ' ') . ' %';
}

$client_name = $doc['client_type'] === 'pro' && $doc['company_name']
    ? $doc['company_name']
    : trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));

// Récap TVA par taux
$vat_breakdown = [];
foreach ($doc['lines'] as $line) {
    $rate      = (int)$line['vat_rate'];
    $total_ht  = (int)$line['total_ht'];
    $vat_cents = (int)round($total_ht * $rate / 10000);
    if (!isset($vat_breakdown[$rate])) $vat_breakdown[$rate] = ['ht' => 0, 'vat' => 0];
    $vat_breakdown[$rate]['ht']  += $total_ht;
    $vat_breakdown[$rate]['vat'] += $vat_cents;
}

ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1a1a1a; }
  .page { padding: 14mm 14mm 10mm 14mm; }

  /* En-tête */
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10mm; }
  .company-block { font-size: 8pt; color: #555; line-height: 1.5; }
  .company-name  { font-size: 14pt; font-weight: bold; color: #111; margin-bottom: 2mm; }
  .doc-type      { font-size: 20pt; font-weight: bold; color: <?= $color ?>; letter-spacing: 1px; }
  .doc-number    { font-size: 10pt; font-weight: bold; color: #333; margin-top: 1mm; }
  .doc-meta      { font-size: 8pt; color: #555; margin-top: 2mm; line-height: 1.7; }
  .doc-block     { text-align: right; }

  /* Séparateur */
  .rule { border: none; border-top: 2px solid <?= $color ?>; margin: 4mm 0; }

  /* Blocs adresses */
  .addresses { display: flex; justify-content: space-between; margin-bottom: 8mm; }
  .addr-box  { width: 47%; }
  .addr-label { font-size: 7pt; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 1.5mm; }
  .addr-name  { font-weight: bold; font-size: 9.5pt; margin-bottom: 1mm; }
  .addr-detail { font-size: 8pt; color: #555; line-height: 1.6; }

  /* Tableau lignes */
  table.lines { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
  table.lines thead th {
    background: <?= $color ?>; color: #fff; padding: 2mm 3mm;
    font-size: 8pt; text-align: left; font-weight: bold;
  }
  table.lines thead th.right { text-align: right; }
  table.lines tbody td { padding: 2mm 3mm; font-size: 8.5pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  table.lines tbody td.right { text-align: right; }
  table.lines tbody tr:nth-child(even) td { background: #f9fafb; }
  .line-desc { font-size: 7.5pt; color: #666; margin-top: .5mm; }

  /* Totaux */
  .totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 6mm; }
  table.totals { width: 68mm; border-collapse: collapse; }
  table.totals td { padding: 1.5mm 3mm; font-size: 8.5pt; }
  table.totals td.lbl { color: #555; }
  table.totals td.val { text-align: right; font-weight: 500; }
  table.totals tr.ttc td { font-size: 10pt; font-weight: bold; border-top: 2px solid <?= $color ?>; color: <?= $color ?>; }
  table.totals tr.vat-sep td { border-top: 1px solid #e5e7eb; }

  /* Notes & pied */
  .notes-block { margin-bottom: 4mm; }
  .notes-title { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5px; color: #888; margin-bottom: 1mm; }
  .notes-text  { font-size: 8pt; color: #444; line-height: 1.5; }
  .footer { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #ddd; font-size: 7pt; color: #888; text-align: center; }
</style>
</head>
<body>
<div class="page">

  <!-- En-tête -->
  <div class="header">
    <div class="company-block">
      <div class="company-name"><?= h($company['name'] ?: 'Votre société') ?></div>
      <?php if ($company['address_line1']): ?><?= h($company['address_line1']) ?><br><?php endif ?>
      <?php if ($company['address_line2']): ?><?= h($company['address_line2']) ?><br><?php endif ?>
      <?php if ($company['postal_code'] || $company['city']): ?><?= h(trim($company['postal_code'] . ' ' . $company['city'])) ?><br><?php endif ?>
      <?php if ($company['siret']): ?>SIRET : <?= h($company['siret']) ?><br><?php endif ?>
      <?php if ($company['vat_number']): ?>TVA : <?= h($company['vat_number']) ?><?php endif ?>
    </div>
    <div class="doc-block">
      <div class="doc-type"><?= $type_label ?></div>
      <div class="doc-number"><?= h($doc['number']) ?></div>
      <div class="doc-meta">
        Émis le : <?= fmt_date($doc['issue_date']) ?><br>
        <?php if ($doc['expiry_date']): ?>Valable jusqu'au : <?= fmt_date($doc['expiry_date']) ?><br><?php endif ?>
        <?php if ($doc['due_date']): ?>Échéance : <?= fmt_date($doc['due_date']) ?><?php endif ?>
      </div>
    </div>
  </div>

  <hr class="rule">

  <!-- Adresses -->
  <table style="width:100%; margin-bottom:8mm;">
      <tr>
          <td style="width:50%; vertical-align:top;">
              <div class="addr-label">Émetteur</div>
              <div class="addr-name"><?= h($company['name'] ?: 'Votre société') ?></div>
              <div class="addr-detail">
                  <?php if ($company['address_line1']): ?><?= h($company['address_line1']) ?><br><?php endif ?>
                  <?php if ($company['postal_code'] || $company['city']): ?>
                      <?= h(trim($company['postal_code'] . ' ' . $company['city'])) ?><br>
                  <?php endif ?>
                  <?php if ($company['iban']): ?>
                      IBAN : <?= h($company['iban']) ?>
                  <?php endif ?>
              </div>
          </td>

          <td style="width:50%; vertical-align:top; text-align:right;">
              <div class="addr-label">Destinataire</div>
              <div class="addr-name"><?= h($client_name) ?></div>
              <div class="addr-detail">
                  <?php if ($doc['client_addr1']): ?><?= h($doc['client_addr1']) ?><br><?php endif ?>
                  <?php if ($doc['client_addr2']): ?><?= h($doc['client_addr2']) ?><br><?php endif ?>
                  <?php if ($doc['client_postal'] || $doc['client_city']): ?>
                      <?= h(trim($doc['client_postal'] . ' ' . $doc['client_city'])) ?><br>
                  <?php endif ?>
                  <?php if ($doc['client_email']): ?>
                      <?= h($doc['client_email']) ?>
                  <?php endif ?>
              </div>
          </td>
      </tr>
  </table>

  <!-- Lignes -->
  <table class="lines">
    <thead>
      <tr>
        <th style="width:40%">Désignation</th>
        <th class="right" style="width:10%">Qté</th>
        <th class="right" style="width:14%">PU HT</th>
        <th class="right" style="width:10%">TVA</th>
        <th class="right" style="width:13%">Total HT</th>
        <th class="right" style="width:13%">Total TTC</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($doc['lines'] as $line):
        $line_vat  = (int)round((int)$line['total_ht'] * (int)$line['vat_rate'] / 10000);
        $line_ttc  = (int)$line['total_ht'] + $line_vat;
        $qty_fmt   = rtrim(rtrim(number_format((float)$line['qty'], 3, ',', ''), '0'), ',');
      ?>
      <tr>
        <td>
          <?= h($line['label']) ?>
          <?php if ($line['description']): ?><div class="line-desc"><?= nl2br(h($line['description'])) ?></div><?php endif ?>
        </td>
        <td class="right"><?= $qty_fmt ?></td>
        <td class="right"><?= fmt_money((int)$line['unit_price']) ?></td>
        <td class="right"><?= fmt_vat((int)$line['vat_rate']) ?></td>
        <td class="right"><?= fmt_money((int)$line['total_ht']) ?></td>
        <td class="right"><?= fmt_money($line_ttc) ?></td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>

  <!-- Totaux -->
  <div class="totals-wrap">
    <table class="totals">
      <tr>
        <td class="lbl">Total HT</td>
        <td class="val"><?= fmt_money((int)$doc['subtotal_ht']) ?></td>
      </tr>
      <?php foreach ($vat_breakdown as $rate => $v): ?>
      <tr class="vat-sep">
        <td class="lbl">TVA <?= fmt_vat($rate) ?></td>
        <td class="val"><?= fmt_money($v['vat']) ?></td>
      </tr>
      <?php endforeach ?>
      <tr class="ttc">
        <td class="lbl">Total TTC</td>
        <td class="val"><?= fmt_money((int)$doc['total_ttc']) ?></td>
      </tr>
    </table>
  </div>

  <?php if ($doc['notes']): ?>
  <div class="notes-block">
    <div class="notes-title">Notes</div>
    <div class="notes-text"><?= nl2br(h($doc['notes'])) ?></div>
  </div>
  <?php endif ?>

  <?php if ($doc['conditions'] || $company['payment_terms']): ?>
  <div class="notes-block">
    <div class="notes-title">Conditions de paiement</div>
    <div class="notes-text"><?= nl2br(h($doc['conditions'] ?: $company['payment_terms'])) ?></div>
  </div>
  <?php endif ?>

  <!-- Pied de page -->
  <div class="footer">
    <?= h($company['legal_footer'] ?: ($company['vat_regime'] === 'franchise' ? 'TVA non applicable, art. 293 B du CGI' : '')) ?>
    <?php if ($company['siret']): ?> — SIRET <?= h($company['siret']) ?><?php endif ?>
  </div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

// ─── Rendu DOMPDF ─────────────────────────────────────────────────────────────

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = h($doc['number']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
