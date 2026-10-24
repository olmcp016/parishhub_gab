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
      padding: 40px;
      background: #f9f9f9;
    }
    .toolbar {
      max-width: 760px;
      margin: 0 auto 20px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: center;
    }
    .mode-btn {
      padding: 10px 16px;
      background: #fff;
      color: #555;
      border: 2px solid #ddd;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
    }
    .mode-btn.active {
      background: #fff8ec;
      border-color: #caa15a;
      color: #8a6416;
    }
    .print-btn {
      padding: 10px 20px;
      background: #caa15a;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
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
    <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
      <button type="button" class="mode-btn" data-mode="thermal">🧾 80mm Thermal Printer</button>
      <button type="button" class="mode-btn" data-mode="bond">📄 Bond Paper (3-in-1)</button>
      <button type="button" class="mode-btn" data-mode="pad">📋 Receipt Pad (9×13.5cm)</button>
    </div>
    <div style="display:flex; gap:6px; align-items:center;">
      <label style="font-size:12px; font-weight:600; color:#555;">Copies:</label>
      <button type="button" class="mode-btn copy-btn" data-copies="1">1 Copy</button>
      <button type="button" class="mode-btn copy-btn" data-copies="3">3 Copies (Triplicate)</button>
      <button type="button" class="print-btn" onclick="window.print()">🖨️ Print Receipt</button>
    </div>
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
      var modeButtons = document.querySelectorAll('.mode-btn:not(.copy-btn)');
      var copyButtons = document.querySelectorAll('.copy-btn');

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
        modeButtons.forEach(function (b) {
          b.classList.toggle('active', b.dataset.mode === mode);
        });
        try { localStorage.setItem('receiptPrintMode', mode); } catch (e) {}
      }

      function setCopies(count) {
        count = String(count) === '1' ? '1' : '3';
        body.classList.remove('copies-1', 'copies-3');
        body.classList.add('copies-' + count);
        copyButtons.forEach(function (b) {
          b.classList.toggle('active', b.dataset.copies === count);
        });
        try { localStorage.setItem('receiptPrintCopies', count); } catch (e) {}
      }

      modeButtons.forEach(function (b) {
        b.addEventListener('click', function () { setMode(b.dataset.mode); });
      });

      copyButtons.forEach(function (b) {
        b.addEventListener('click', function () { setCopies(b.dataset.copies); });
      });

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
