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
    .receipt-container {
      max-width: 400px;
      margin: 0 auto;
      background: #fff;
      padding: 30px;
      border: 1px solid #ddd;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .header {
      text-align: center;
      margin-bottom: 30px;
      border-bottom: 2px dashed #ddd;
      padding-bottom: 20px;
    }
    .header h1 {
      margin: 0 0 5px 0;
      font-size: 24px;
      color: #2c3e50;
    }
    .header p {
      margin: 0;
      color: #777;
      font-size: 14px;
    }
    .receipt-details {
      margin-bottom: 30px;
    }
    .row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
      font-size: 14px;
    }
    .row.total {
      font-size: 18px;
      font-weight: bold;
      border-top: 2px dashed #ddd;
      padding-top: 16px;
      margin-top: 16px;
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
      font-size: 12px;
      color: #999;
      margin-top: 40px;
    }
    @media print {
      body {
        background: #fff;
        padding: 0;
      }
      .receipt-container {
        box-shadow: none;
        border: none;
        max-width: 100%;
        padding: 20px;
      }
      .no-print {
        display: none !important;
      }
    }
    .print-btn {
      display: block;
      width: 100%;
      padding: 12px;
      background: #caa15a;
      color: #fff;
      border: none;
      border-radius: 6px;
      font-size: 16px;
      cursor: pointer;
      margin-bottom: 20px;
      text-align: center;
      text-decoration: none;
    }
    .print-btn:hover {
      background: #b58d47;
    }
  </style>
</head>
<body>
  <div class="receipt-container">
    
    <div class="header">
      <img src="<?= url('public/img/logo.png') ?>" alt="ParishHub Logo" style="width: 64px; height: auto; margin-bottom: 10px;">
      <h1>ParishHub</h1>
      <p>Official Receipt</p>
      <p style="margin-top: 10px; font-weight: bold; color: #333;">OR #: <?= e($payment['receipt_number']) ?></p>
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
      
      <div class="row total">
        <span>Total Amount</span>
        <span><?= money((float)$payment['amount']) ?></span>
      </div>
    </div>

    <div class="footer">
      Thank you for your generous support!<br>
      Parish Service Portal
    </div>

    <button class="no-print print-btn" onclick="window.print()" style="margin-top: 30px;">🖨️ Print Receipt</button>
  </div>

</body>
</html>
