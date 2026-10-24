<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('Treasurer', 'Admin');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT p.*, r.receipt_number, r.issue_date, 
           u.firstname, u.lastname, u.email,
           a.guest_name, a.guest_phone, a.guest_reference,
           s.service_name, s.category,
           pm.method_name
    FROM payments p
    LEFT JOIN official_receipts r ON p.payment_id = r.payment_id
    JOIN appointments a ON p.appointment_id = a.appointment_id
    JOIN services s ON a.service_id = s.service_id
    JOIN payment_methods pm ON p.method_id = pm.method_id
    LEFT JOIN parishioners par ON a.parishioner_id = par.parishioner_id
    LEFT JOIN users u ON par.user_id = u.user_id
    WHERE p.payment_id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment || !$payment['receipt_number']) {
    die("Receipt not found or payment not verified.");
}

// Parish identity block, matching the official sacrament form headers.
$parishSettings = [];
foreach (db()->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('parish_name','parish_address','contact_number','contact_email')")->fetchAll() as $row) {
    $parishSettings[$row['setting_key']] = trim((string) $row['setting_value']);
}
$parishName    = $parishSettings['parish_name'] ?: 'Our Lady of Mt. Carmel Parish';
$parishAddress = $parishSettings['parish_address'] ?: '6342 Balilihan, Bohol, Philippines';
$parishContact = array_filter([
    $parishSettings['contact_number'] ?? '',
    $parishSettings['contact_email'] ?? '',
]);

// Donations: where the gift went (general purpose or one ongoing project).
$donationAllocation = null;
if ($payment['category'] === 'Donation') {
    $stmt = db()->prepare('SELECT d.purpose, d.project_id, pr.project_name FROM donations d LEFT JOIN projects pr ON pr.project_id = d.project_id WHERE d.appointment_id = ?');
    $stmt->execute([$payment['appointment_id']]);
    $donationRow = $stmt->fetch();
    if ($donationRow) $donationAllocation = donationAllocationLabel($donationRow);
}

/**
 * The receipt's content, identical on every copy — only the copy label
 * (Customer / Parish Office / Accounting) differs between the three prints
 * on a bond-paper sheet. Kept as one function so the three copies can never
 * drift out of sync with each other.
 */
function renderReceiptBody(string $parishName, string $parishAddress, array $parishContact, array $payment, ?string $donationAllocation): void
{
    ?>
    <div class="header" style="display: flex; align-items: center; justify-content: center; gap: 16px; text-align: left; color: #145c12; font-family: 'Times New Roman', Times, serif;">
      <img class="header-logo" src="<?= url('public/img/logo.png') ?>" alt="<?= e($parishName) ?> Logo" style="width: 64px; height: auto;">
      <div style="text-align: center;">
        <h1 style="margin: 0 0 2px 0; font-size: 20px; color: #145c12;"><?= e($parishName) ?></h1>
        <p class="header-address" style="margin: 0; font-weight: bold; font-size: 11px; color: #333; text-transform: uppercase;"><?= e($parishAddress) ?></p>
        <?php if ($parishContact): ?>
        <p class="header-address" style="margin: 0; font-size: 11px; color: #333;"><?= e(implode(' | ', $parishContact)) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="or-block" style="text-align: center; margin-bottom: 20px;">
      <div class="or-title" style="font-family: 'Times New Roman', Times, serif; font-size: 16px; font-weight: bold; letter-spacing: 1px; color: #333;">OFFICIAL RECEIPT</div>
      <div class="or-number" style="font-size: 13px; font-weight: bold; color: #333; margin-top: 4px;">OR #: <?= e($payment['receipt_number']) ?></div>
    </div>

    <div class="receipt-details">
      <div class="row">
        <span class="label">Date Issued</span>
        <span class="value"><?= formatDateTime($payment['issue_date']) ?></span>
      </div>
      <div class="row">
        <span class="label">Billed To</span>
        <span class="value">
          <?= $payment['guest_name'] ? e($payment['guest_name']) . '<br><small>(Guest)</small>' : e($payment['firstname'] . ' ' . $payment['lastname']) ?>
        </span>
      </div>
      <div class="row">
        <span class="label">Payment Method</span>
        <span class="value"><?= e($payment['method_name']) ?></span>
      </div>
      <?php if ($payment['reference_number']): ?>
      <div class="row">
        <span class="label">Reference No.</span>
        <span class="value"><?= e($payment['reference_number']) ?></span>
      </div>
      <?php endif; ?>
      <div class="row" style="margin-top: 24px;">
        <span class="label">Description</span>
        <span class="value"><?= e($payment['service_name']) ?></span>
      </div>
      <?php if ($donationAllocation): ?>
      <div class="row">
        <span class="label">Allocation</span>
        <span class="value"><?= e($donationAllocation) ?></span>
      </div>
      <?php endif; ?>

      <div class="row total">
        <span>Total Amount</span>
        <span><?= money((float)$payment['amount']) ?></span>
      </div>
    </div>

    <div class="footer">
      Thank you for your generous support!<br>
      Parish Service Portal
    </div>
    <?php
}

$copyLabels = ['Customer Copy', 'Parish Office Copy', 'Accounting / Audit Copy'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Official Receipt - <?= e($payment['receipt_number']) ?></title>
  <style>
    body {
      font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      color: #333;
      margin: 0;
      padding: 14px 10px;
      background: #f9f9f9;
    }
    .toolbar {
      max-width: 650px;
      margin: 0 auto 14px;
      padding: 8px 12px;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05);
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }
    .toolbar-group {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .toolbar-group label {
      font-size: 12px;
      font-weight: 600;
      color: #475569;
      white-space: nowrap;
    }
    .toolbar-select {
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 500;
      color: #1e293b;
      background: #f8fafc;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      cursor: pointer;
      outline: none;
      transition: border-color 0.15s;
    }
    .toolbar-select:focus {
      border-color: #caa15a;
      background: #fff;
    }
    .print-btn {
      padding: 6px 14px;
      background: #caa15a;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s;
      white-space: nowrap;
    }
    .print-btn:hover {
      background: #b58d47;
    }
    .sheet {
      display: flex;
      flex-direction: row;
      align-items: flex-start;
      justify-content: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .receipt-copy {
      position: relative;
      width: 90mm;
      min-height: 135mm;
      box-sizing: border-box;
      background: #fff;
      padding: 7mm 6mm 5mm;
      border: 1px solid #ddd;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .copy-label {
      text-align: center;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 1px;
      text-transform: uppercase;
      color: #999;
      margin-bottom: 6px;
    }
    .cut-tag {
      display: none;
    }
    .header {
      text-align: center;
      margin-bottom: 14px;
      border-bottom: 2px dashed #ddd;
      padding-bottom: 10px;
    }
    .header h1 {
      margin: 0 0 5px 0;
      font-size: 15px;
      color: #2c3e50;
    }
    .header-address {
      margin: 0;
      color: #777;
      font-size: 8px;
    }
    .or-block {
      margin-bottom: 10px;
    }
    .or-title {
      font-size: 13px !important;
    }
    .or-number {
      font-size: 10px !important;
    }
    .receipt-details {
      margin-bottom: 10px;
    }
    .row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 6px;
      font-size: 10px;
    }
    .row.total {
      font-size: 13px;
      font-weight: bold;
      border-top: 2px dashed #ddd;
      padding-top: 8px;
      margin-top: 8px;
    }
    .label {
      color: #666;
    }
    .value {
      font-weight: 500;
      text-align: right;
    }
    .footer {
      text-align: center;
      font-size: 8px;
      color: #999;
      margin-top: 14px;
    }

    /* ---- Bond-paper mode (screen preview + print): 3 copies side by side,
       landscape, dashed cut guide around each copy. ---- */
    body.mode-bond .receipt-copy {
      border: 1px dashed #999;
      box-shadow: none;
    }
    body.mode-bond .copy-label {
      display: block;
    }
    body.mode-bond .cut-tag {
      display: block;
      position: absolute;
      top: -2.6mm;
      left: 50%;
      transform: translateX(-50%);
      background: #fff;
      padding: 0 2mm;
      font-size: 7px;
      color: #999;
      white-space: nowrap;
    }

    /* ---- Receipt-pad mode (screen preview + print): a single, borderless,
       full-bleed copy sized exactly to the pad. ---- */
    body.mode-pad .sheet {
      justify-content: flex-start;
      gap: 0;
    }
    body.mode-pad .receipt-copy:nth-child(n+2) {
      display: none;
    }
    body.mode-pad .receipt-copy:first-child {
      border: none;
      box-shadow: none;
      height: 135mm;
    }
    body.mode-pad .copy-label {
      display: none;
    }

    /* ---- 80mm POS Thermal Printer mode ---- */
    body.mode-thermal .sheet {
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    body.mode-thermal .receipt-copy {
      width: 76mm;
      min-height: auto;
      padding: 4mm 3mm;
      border: 1px dashed #bbb;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    body.mode-thermal .cut-tag {
      display: none;
    }
    body.mode-thermal .copy-label {
      display: block;
      font-size: 8px;
      color: #666;
      border-bottom: 1px dashed #ccc;
      padding-bottom: 2px;
      margin-bottom: 5px;
    }
    body.copies-1 .receipt-copy:nth-child(n+2) {
      display: none !important;
    }

    @media print {
      html, body {
        background: #fff;
        margin: 0;
        padding: 0;
      }
      .no-print {
        display: none !important;
      }
      .sheet {
        gap: 0;
      }
      .receipt-copy {
        box-shadow: none !important;
      }
      body.mode-bond .sheet {
        justify-content: center;
      }
      body.mode-pad .receipt-copy:first-child {
        width: 90mm;
        min-height: 135mm;
        margin: 0;
        padding: 5mm 6mm;
      }
      body.mode-thermal .receipt-copy {
        width: 74mm !important;
        min-height: auto !important;
        margin: 0 auto !important;
        padding: 2mm 1mm !important;
        border: none !important;
        page-break-after: always;
      }
      body.mode-thermal .receipt-copy:last-child {
        page-break-after: avoid;
      }
      body.copies-1 .receipt-copy:nth-child(n+2) {
        display: none !important;
      }
    }
  </style>
</head>
<body>
  <div class="toolbar no-print">
    <div class="toolbar-group">
      <label for="destSelect">Destination:</label>
      <select id="destSelect" class="toolbar-select">
        <option value="thermal">80mm Thermal Printer</option>
        <option value="bond">Bond Paper (3-in-1)</option>
        <option value="pad">Receipt Pad (9×13.5cm)</option>
      </select>
    </div>
    <div class="toolbar-group">
      <label for="copiesSelect">Copies:</label>
      <select id="copiesSelect" class="toolbar-select">
        <option value="1">1</option>
        <option value="3">3</option>
      </select>
    </div>
    <button type="button" class="print-btn" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Receipt
    </button>
  </div>

  <div class="sheet" id="receiptSheet">
    <?php foreach ($copyLabels as $copyLabel): ?>
    <div class="receipt-copy">
      <div class="cut-tag">✂ Cut here</div>
      <div class="copy-label"><?= e($copyLabel) ?></div>
      <?php renderReceiptBody($parishName, $parishAddress, $parishContact, $payment, $donationAllocation); ?>
    </div>
    <?php endforeach; ?>
  </div>

  <style id="pageSizeStyle"></style>
  <script>
    (function () {
      var body = document.body;
      var pageStyle = document.getElementById('pageSizeStyle');
      var destSelect = document.getElementById('destSelect');
      var copiesSelect = document.getElementById('copiesSelect');

      var PAGE_RULES = {
        thermal: '@page { size: 80mm auto; margin: 0; }',
        bond: '@page { size: landscape; margin: 3mm; }',
        pad: '@page { size: 90mm 135mm; margin: 0; }'
      };

      function setMode(mode) {
        if (!PAGE_RULES[mode]) mode = 'thermal';
        body.classList.remove('mode-bond', 'mode-pad', 'mode-thermal');
        body.classList.add('mode-' + mode);
        pageStyle.textContent = PAGE_RULES[mode];
        if (destSelect && destSelect.value !== mode) {
          destSelect.value = mode;
        }
        try { localStorage.setItem('receiptPrintMode', mode); } catch (e) {}
      }

      function setCopies(count) {
        count = String(count) === '1' ? '1' : '3';
        body.classList.remove('copies-1', 'copies-3');
        body.classList.add('copies-' + count);
        if (copiesSelect && copiesSelect.value !== count) {
          copiesSelect.value = count;
        }
        try { localStorage.setItem('receiptPrintCopies', count); } catch (e) {}
      }

      if (destSelect) {
        destSelect.addEventListener('change', function () {
          setMode(destSelect.value);
        });
      }

      if (copiesSelect) {
        copiesSelect.addEventListener('change', function () {
          setCopies(copiesSelect.value);
        });
      }

      var savedMode = 'thermal';
      try { savedMode = localStorage.getItem('receiptPrintMode') || 'thermal'; } catch (e) {}
      setMode(savedMode);

      var savedCopies = '3';
      try { savedCopies = localStorage.getItem('receiptPrintCopies') || '3'; } catch (e) {}
      setCopies(savedCopies);
    })();
  </script>
</body>
</html>
