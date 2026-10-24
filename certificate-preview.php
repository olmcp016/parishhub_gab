<?php
/**
 * Public Live Previewer for Official Church Certificates.
 * Accessible locally (localhost) and on Render public deployment.
 */
require_once __DIR__ . '/includes/certificate-generator.php';

$type = $_GET['type'] ?? 'Baptism';
if (!in_array($type, ['Baptism', 'Marriage', 'Confirmation', 'Death'], true)) {
    $type = 'Baptism';
}

$sampleData = match ($type) {
    'Baptism' => [
        'parish_of' => $_GET['parish_of'] ?? 'Our Lady of Mt. Carmel Parish',
        'child_name' => $_GET['child_name'] ?? 'MCNEAL LOUISE SINAJON',
        'father_name' => $_GET['father_name'] ?? 'Juan Dela Cruz',
        'mother_name' => $_GET['mother_name'] ?? 'Maria Santos',
        'birth_date' => $_GET['birth_date'] ?? '2026-10-22',
        'birth_place' => $_GET['birth_place'] ?? 'Guindulman, Bohol',
        'baptism_date' => $_GET['baptism_date'] ?? '2026-11-07',
        'baptism_place' => $_GET['baptism_place'] ?? 'Our Lady of Mt. Carmel Parish',
        'godparent_1' => $_GET['godparent_1'] ?? 'KRIZZIANA',
        'godparent_2' => $_GET['godparent_2'] ?? 'ABEGAIL',
        'officiating_priest' => $_GET['officiating_priest'] ?? 'Rev. Fr. Sabino Rebosura Assisting Resident Priest',
        'parish_priest' => $_GET['parish_priest'] ?? 'Rev. Fr. Sabino Rebosura',
        'book_no' => $_GET['book_no'] ?? '05',
        'page_no' => $_GET['page_no'] ?? '42',
        'line_no' => $_GET['line_no'] ?? '88',
    ],
    'Marriage' => [
        'church_of' => 'Our Lady of Mt. Carmel Parish',
        'place' => 'Guindulman',
        'province' => 'Bohol',
        'groom_name' => 'JUAN DELA CRUZ',
        'bride_name' => 'MARIA CLARA SANTOS',
        'groom_age' => '28',
        'bride_age' => '26',
        'groom_nationality' => 'Filipino',
        'bride_nationality' => 'Filipino',
        'groom_residence' => 'Poblacion, Guindulman, Bohol',
        'bride_residence' => 'Tabajan, Guindulman, Bohol',
        'groom_father' => 'Pedro Dela Cruz',
        'bride_father' => 'Jose Santos',
        'groom_mother' => 'Juana Dela Cruz',
        'bride_mother' => 'Clara Santos',
        'marriage_date' => '2026-11-15',
        'officiating_priest' => 'Rev. Fr. Sabino Rebosura',
        'witness_name' => 'Mr. Antonio Luna & Mrs. Gabriela Silang',
        'witness_residence' => 'Guindulman, Bohol',
        'book_no' => '08',
        'page_no' => '15',
        'line_no' => '04',
        'marriage_license_no' => 'ML-2026-9912',
        'license_issued_at' => 'Guindulman, Bohol',
        'license_issued_date' => '2026-10-10',
        'certificate_dated' => '2026-11-16',
    ],
    'Confirmation' => [
        'confirmed_name' => 'MCNEAL LOUISE SINAJON',
        'father_name' => 'Juan Dela Cruz',
        'mother_name' => 'Maria Santos',
        'parish_of' => 'Our Lady of Mt. Carmel Parish',
        'confirmation_date' => '2026-12-08',
        'officiating_bishop' => 'Most Rev. Alberto Uy, D.D.',
        'sponsor_1' => 'Mark Anthony Villanueva',
        'sponsor_2' => 'Elena Gonzales',
        'book_no' => '04',
        'page_no' => '21',
        'line_no' => '12',
        'given_date' => '2026-12-09',
    ],
    'Death' => [
        'deceased_name' => 'SANTIAGO DELA CRUZ',
        'death_date' => '2026-09-30',
        'death_age' => '78',
        'cause_of_death' => 'Cardiopulmonary Arrest',
        'civil_status' => 'Married',
        'relation_names' => 'Maria Dela Cruz (Spouse)',
        'barangay' => 'Poblacion',
        'municipality' => 'Guindulman',
        'province' => 'Bohol',
        'burial_type' => 'Parish Cemetery',
        'burial_date' => '2026-10-05',
        'sacraments_received' => 'Anointing of the Sick, Viaticum',
        'parish_name' => 'Our Lady of Mt. Carmel Parish',
        'book_no' => '02',
        'line_no' => '45',
        'certificate_dated' => '2026-10-06',
    ]
};

// If direct raw PDF output requested:
if (isset($_GET['pdf']) && $_GET['pdf'] === '1') {
    $pdfContent = certificatePdf($type, $sampleData);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Sample_' . $type . '_Certificate.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $pdfContent;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Certificate Live Preview — ParishHub</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --gold: #D4AF37;
      --gold-dark: #A67C1E;
      --burgundy: #4A0E17;
      --burgundy-dark: #2B050B;
      --bg: #F7F5F0;
      --card-bg: #FFFFFF;
      --text: #2D241E;
      --text-muted: #7A6F66;
      --border: #E6DFD5;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    header {
      background: var(--burgundy-dark);
      color: #fff;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .header-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .logo-badge {
      background: linear-gradient(135deg, var(--gold), var(--gold-dark));
      color: var(--burgundy-dark);
      font-weight: 700;
      font-size: 20px;
      width: 42px;
      height: 42px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Cinzel', serif;
    }
    .header-title h1 {
      font-family: 'Cinzel', serif;
      font-size: 18px;
      letter-spacing: 0.5px;
      color: #fff;
    }
    .header-title p {
      font-size: 12px;
      color: rgba(255,255,255,0.7);
    }
    .tabs {
      display: flex;
      gap: 8px;
      padding: 16px 24px;
      background: #fff;
      border-bottom: 1px solid var(--border);
      overflow-x: auto;
    }
    .tab-btn {
      padding: 10px 18px;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      color: var(--text-muted);
      border: 1px solid transparent;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .tab-btn:hover {
      background: var(--bg);
      color: var(--burgundy);
    }
    .tab-btn.active {
      background: var(--burgundy);
      color: #fff;
      border-color: var(--burgundy);
      box-shadow: 0 2px 8px rgba(74, 14, 23, 0.25);
    }
    .main-container {
      display: flex;
      flex: 1;
      padding: 20px 24px;
      gap: 20px;
    }
    @media (max-width: 900px) {
      .main-container { flex-direction: column; }
    }
    .preview-card {
      flex: 1;
      background: var(--card-bg);
      border-radius: 12px;
      border: 1px solid var(--border);
      box-shadow: 0 4px 15px rgba(0,0,0,0.05);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      min-height: 750px;
    }
    .preview-card-header {
      padding: 14px 20px;
      background: #faf8f5;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .preview-card-header h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--burgundy);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .action-links {
      display: flex;
      gap: 10px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 14px;
      font-size: 13px;
      font-weight: 600;
      border-radius: 6px;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.2s;
    }
    .btn-primary {
      background: var(--gold-dark);
      color: #fff;
      border: 1px solid var(--gold-dark);
    }
    .btn-primary:hover {
      background: var(--gold);
    }
    .btn-secondary {
      background: #fff;
      color: var(--text);
      border: 1px solid var(--border);
    }
    .btn-secondary:hover {
      background: var(--bg);
    }
    .pdf-viewer-frame {
      width: 100%;
      height: 100%;
      min-height: 750px;
      border: none;
      flex: 1;
      background: #525659;
    }
    .info-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      background: #e6f4ea;
      color: #137333;
    }
  </style>
</head>
<body>

  <header>
    <div class="header-left">
      <div class="logo-badge">PH</div>
      <div class="header-title">
        <h1>ParishHub — Certificate Live Previewer</h1>
        <p>Public &amp; Local Verification Sandbox for Official Sacramental Certificates</p>
      </div>
    </div>
    <div>
      <span class="info-badge">● Live Renderer Connected</span>
    </div>
  </header>

  <nav class="tabs">
    <a href="?type=Baptism" class="tab-btn <?= $type === 'Baptism' ? 'active' : '' ?>">
      👶 Baptism Certificate <?= $type === 'Baptism' ? '★ (Recreated Digital Layout)' : '' ?>
    </a>
    <a href="?type=Marriage" class="tab-btn <?= $type === 'Marriage' ? 'active' : '' ?>">
      💍 Marriage Certificate
    </a>
    <a href="?type=Confirmation" class="tab-btn <?= $type === 'Confirmation' ? 'active' : '' ?>">
      🕊️ Confirmation Certificate
    </a>
    <a href="?type=Death" class="tab-btn <?= $type === 'Death' ? 'active' : '' ?>">
      ✝️ Death Certificate
    </a>
  </nav>

  <main class="main-container">
    <section class="preview-card">
      <div class="preview-card-header">
        <h2>
          <span>📄</span> Official Certificate PDF: <u><?= htmlspecialchars($type) ?></u>
          <?php if ($type === 'Baptism'): ?>
            <span class="info-badge" style="font-size:11px; margin-left:8px;">New Digital Vector Lines &amp; Centered Fonts</span>
          <?php endif; ?>
        </h2>
        <div class="action-links">
          <a href="certificate-preview.php?type=<?= urlencode($type) ?>&pdf=1" target="_blank" class="btn btn-secondary">
            ↗ Open in New Tab
          </a>
          <a href="certificate-preview.php?type=<?= urlencode($type) ?>&pdf=1" download="Sample_<?= htmlspecialchars($type) ?>_Certificate.pdf" class="btn btn-primary">
            ⬇ Download PDF
          </a>
        </div>
      </div>
      <iframe class="pdf-viewer-frame" src="certificate-preview.php?type=<?= urlencode($type) ?>&pdf=1"></iframe>
    </section>
  </main>

</body>
</html>
