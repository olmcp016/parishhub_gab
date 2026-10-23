<?php
// preview-forms.php - Public Preview of All Generated Forms & Certificates
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/wedding-forms.php';
require_once __DIR__ . '/includes/baptism-forms.php';
require_once __DIR__ . '/includes/funeral-forms.php';

// Sample data for all forms (all uppercase names/places per parish rules)
$weddingSample = [
    'date_applied' => '2026-10-23',
    'groom_name' => 'JUAN DELA CRUZ',
    'groom_birth_date' => '1998-05-12',
    'groom_father' => 'PEDRO DELA CRUZ',
    'groom_mother' => 'MARIA SANTOS',
    'groom_mother_maiden_name' => 'MARIA SANTOS',
    'groom_address' => 'POBLACION, BALILIHAN, BOHOL',
    'groom_cell' => '09123456789',
    'bride_name' => 'ANA REYES',
    'bride_birth_date' => '1999-08-20',
    'bride_father' => 'JOSE REYES',
    'bride_mother' => 'ELENA GOMEZ',
    'bride_mother_maiden_name' => 'ELENA GOMEZ',
    'bride_address' => 'DEL CARMEN WESTE, BALILIHAN, BOHOL',
    'bride_cell' => '09987654321',
    'wedding_date' => '2026-12-15',
    'wedding_time' => '10:00',
    'kaslonon_name' => 'JUAN DELA CRUZ',
    'kaslonon_birth_date' => '1998-05-12',
    'kaslonon_status' => 'SINGLE',
    'kaslonon_religion' => 'ROMAN CATHOLIC',
    'father_name' => 'PEDRO DELA CRUZ',
    'father_religion' => 'ROMAN CATHOLIC',
    'mother_name' => 'MARIA SANTOS',
    'mother_religion' => 'ROMAN CATHOLIC',
    'sponsor_1' => 'HON. MARIO LOPEZ',
    'sponsor_2' => 'DRA. CARMEN TORRES',
    'parent_marriage' => 'Simbahan',
    'marriage_place' => 'OUR LADY OF MT. CARMEL PARISH, BALILIHAN',
    'marriage_date' => '1995-01-20',
    'address' => 'POBLACION, BALILIHAN, BOHOL',
    'chapel' => 'SAN ROQUE CHAPEL',
    'cluster_name' => 'SAN ISIDRO CLUSTER',
    'spouse_name' => 'ANA REYES',
    'spouse_birth_date' => '1999-08-20',
    'spouse_status' => 'SINGLE',
    'spouse_religion' => 'ROMAN CATHOLIC',
    'spouse_address' => 'DEL CARMEN WESTE, BALILIHAN, BOHOL',
    'recipient_name' => 'HON. MARIO LOPEZ',
    'cluster_number' => '4',
    'service_date' => '2026-12-15',
    'service_requested' => 'Kasal'
];

$baptismSample = [
    'child_name' => 'GABRIEL REYES DELA CRUZ',
    'birth_date' => '2026-06-10',
    'birth_place' => 'TAGBILARAN CITY, BOHOL',
    'father_name' => 'JUAN DELA CRUZ',
    'father_religion' => 'ROMAN CATHOLIC',
    'mother_name' => 'ANA REYES',
    'mother_religion' => 'ROMAN CATHOLIC',
    'parent_marriage' => 'Simbahan',
    'marriage_place' => 'BALILIHAN CHURCH',
    'sponsor_1' => 'RAMON MAGSAYSAY',
    'sponsor_2' => 'TERESA AQUINO',
    'chapel' => 'SAN ROQUE CHAPEL',
    'cluster_name' => 'CLUSTER 3',
    'barangay' => 'POBLACION',
    'cellphone' => '09123456789',
    'sponsor_name' => 'RAMON MAGSAYSAY',
    'address' => 'POBLACION, BALILIHAN, BOHOL',
    'mother_maiden_name' => 'ANA REYES',
    'service_date' => '2026-11-20',
    'cluster_number' => '3'
];

$funeralSample = [
    'ngalan_sa_ilubong' => 'PEDRO DELA CRUZ SR.',
    'edad' => '78',
    'pinuy_anan' => 'BOCTOL, BALILIHAN, BOHOL',
    'relihiyon' => 'ROMAN CATHOLIC',
    'sakop_sa_kapilya' => 'KAPILYA SAN ISIDRO',
    'ngalan_sa_cluster' => 'CLUSTER 1',
    'sakramento_hilog' => true,
    'sakramento_kumpisal' => true,
    'sakramento_wala' => false,
    'kanus_a_namatay' => '2026-10-20',
    'unsay_namatyan' => 'CARDIOPULMONARY ARREST',
    'kanus_a_ilubong' => '2026-10-25',
    'oras_sa_lubong' => '14:00',
    'responde' => 'JUAN DELA CRUZ',
    'ginikanan_anak' => 'JUAN DELA CRUZ (ANAK)',
    'ginikanan_anak_cell' => '09123456789',
    'asawa_bana' => 'MARIA SANTOS DELA CRUZ',
    'asawa_bana_cell' => '09198765432',
    'kasal' => 'Simbahan',
    'petsa_sa_kasal' => '1970-06-15',
    'diin_kasal' => 'BALILIHAN PARISH'
];

// Check if user requested a direct PDF stream
$requestedPdf = $_GET['pdf'] ?? '';
if ($requestedPdf !== '') {
    $pdfContent = null;
    $filename = 'Document.pdf';

    switch ($requestedPdf) {
        case 'matrimony_application':
            $pdfContent = weddingFormPdf('matrimony_application', $weddingSample);
            $filename = 'Marriage_Requirements_and_Application_Form.pdf';
            break;
        case 'cluster_clearance':
            $pdfContent = weddingFormPdf('cluster_clearance', $weddingSample);
            $filename = 'Katin-awan_sa_Kasal.pdf';
            break;
        case 'wedding_sponsor_clearance':
            $pdfContent = weddingFormPdf('wedding_sponsor_clearance', $weddingSample);
            $filename = 'Cluster_Clearance_Wedding_Sponsor.pdf';
            break;
        case 'katin_awan_bunyag':
            $pdfContent = baptismFormPdf('katin_awan_bunyag', $baptismSample);
            $filename = 'Katin-awan_sa_Bunyag.pdf';
            break;
        case 'cluster_clearance_baptism_sponsor':
            $pdfContent = baptismFormPdf('cluster_clearance_baptism_sponsor', $baptismSample);
            $filename = 'Cluster_Clearance_Baptism_Sponsor.pdf';
            break;
        case 'katin_awan_paglubong':
            $pdfContent = funeralKatinAwanPdf($funeralSample);
            $filename = 'Katin-awan_sa_Paglubong.pdf';
            break;
    }

    if ($pdfContent !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        echo $pdfContent;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview Generated Forms & Certificates - ParishHub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #1a4d2e;
            --green-dark: #123520;
            --cream: #f5efe6;
            --cream-light: #faf7f2;
            --gold: #c5a059;
            --brown: #3e2723;
            --card-border: #e2d9cc;
            --text-dark: #2c2523;
            --text-muted: #665c54;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #fbf9f5;
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }
        header {
            background: linear-gradient(135deg, var(--green-dark) 0%, var(--green) 100%);
            color: #fff;
            padding: 36px 20px 30px;
            text-align: center;
            border-bottom: 4px solid var(--gold);
        }
        header h1 {
            font-family: 'Cinzel', serif;
            font-size: 2rem;
            margin: 0 0 8px;
            letter-spacing: 0.05em;
        }
        header p {
            margin: 0 auto;
            max-width: 650px;
            font-size: 1rem;
            color: #e0ece4;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }
        .intro-banner {
            background: #fff;
            border: 1px solid var(--card-border);
            border-left: 5px solid var(--gold);
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 32px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .intro-banner h3 {
            margin: 0 0 6px;
            color: var(--green);
            font-size: 1.1rem;
        }
        .intro-banner p {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        .section-title {
            font-family: 'Cinzel', serif;
            color: var(--green);
            font-size: 1.4rem;
            margin: 32px 0 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 22px;
            margin-bottom: 24px;
        }
        .card {
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.08);
        }
        .card-header {
            padding: 14px 18px;
            background: var(--cream);
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 20px;
            letter-spacing: 0.05em;
        }
        .badge-wedding { background: #fee2e2; color: #991b1b; }
        .badge-baptism { background: #e0f2fe; color: #075985; }
        .badge-funeral { background: #f3f4f6; color: #374151; }
        .badge-cert { background: #fef3c7; color: #92400e; }
        .card-body {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .card-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0 0 8px;
            color: var(--brown);
        }
        .card-desc {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin: 0 0 16px;
            flex: 1;
        }
        .meta-list {
            background: var(--cream-light);
            border: 1px dashed var(--card-border);
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 0.85rem;
        }
        .meta-list div {
            margin-bottom: 4px;
        }
        .meta-list div:last-child { margin-bottom: 0; }
        .meta-list strong { color: var(--green-dark); }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--green);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--green-dark);
            color: #fff;
        }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--green);
            color: var(--green);
        }
        .btn-outline:hover {
            background: var(--green);
            color: #fff;
        }
        .btn-group {
            display: flex;
            gap: 10px;
        }
        .btn-group .btn { flex: 1; }
        .img-preview {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-bottom: 1px solid var(--card-border);
            background: #eee;
        }
        footer {
            text-align: center;
            padding: 24px;
            font-size: 0.85rem;
            color: var(--text-muted);
            border-top: 1px solid var(--card-border);
            background: #fff;
        }
    </style>
</head>
<body>

<header>
    <h1>Our Lady of Mt. Carmel Parish</h1>
    <p>Balilihan, Bohol · Public Documentary Forms & Certificates Live Preview</p>
</header>

<div class="container">
    <div class="intro-banner">
        <h3>📄 Live Generated Forms Preview System</h3>
        <p>I-click ang <strong>"Open / Print Generated PDF"</strong> sa kahit aling form sa ibaba upang makita ang mismong opisyal na PDF na nag-ge-generate nang awtomatiko kapag nag-fill up ang parishioners o guests. Lahat ng pangalan, magulang, at lugar ay naka-auto UPPERCASE at naka-autofill mula sa Step 1.</p>
    </div>

    <!-- 1. KASAL / WEDDING -->
    <h2 class="section-title">💒 1. Kasal (Wedding Requirements)</h2>
    <div class="grid">
        <!-- Marriage Application Form -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-wedding">Wedding · Step 1</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">PDF Generated</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Marriage Requirements &amp; Application Form</h3>
                <p class="card-desc">Ang pangunahing form kung saan inilalagay ang impormasyon ng Groom &amp; Bride, mga magulang, petsa ng kasal, at ang official requirements checklist.</p>
                <div class="meta-list">
                    <div><strong>Groom:</strong> JUAN DELA CRUZ (28 yrs old)</div>
                    <div><strong>Bride:</strong> ANA REYES (27 yrs old)</div>
                    <div><strong>Wedding Date:</strong> DECEMBER 15, 2026 @ 10:00 AM</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=matrimony_application" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Katin-awan sa Kasal -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-wedding">Wedding · Step 2</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Auto-filled</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">KATIN-AWAN SA KASAL</h3>
                <p class="card-desc">Cluster Clearance form para sa kasal. Awtomatikong kinopya ang pangalan ng kaslonon, spouse, magulang, at tirahan mula sa unang application form.</p>
                <div class="meta-list">
                    <div><strong>Kaslonon:</strong> JUAN DELA CRUZ</div>
                    <div><strong>Pamanhunon/Pangasaw-onon:</strong> ANA REYES</div>
                    <div><strong>Cluster:</strong> SAN ISIDRO CLUSTER · Kapilya San Roque</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=cluster_clearance" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Wedding Sponsor Clearance -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-wedding">Wedding · Sponsor</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Auto-filled</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Cluster Clearance for Wedding Sponsors</h3>
                <p class="card-desc">Official parish clearance para sa mga Ninong at Ninang. Awtomatikong nakalagay ang pangalan ng Groom, Bride, at Wedding Date.</p>
                <div class="meta-list">
                    <div><strong>Recipient (Ninong):</strong> HON. MARIO LOPEZ</div>
                    <div><strong>Groom &amp; Bride:</strong> JUAN DELA CRUZ &amp; ANA REYES</div>
                    <div><strong>Date of Service:</strong> DECEMBER 15, 2026</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=wedding_sponsor_clearance" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. BUNYAG / BAPTISM -->
    <h2 class="section-title">🕊️ 2. Bunyag (Baptism Requirements)</h2>
    <div class="grid">
        <!-- Katin-awan sa Bunyag -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-baptism">Baptism · Main Form</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">PDF Generated</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">KATIN-AWAN SA BUNYAG</h3>
                <p class="card-desc">Dokumento para sa bunyag ng bata na naglalaman ng mga detalye ng kapanganakan, kasal ng magulang, mga sponsor, kapilya, at cluster.</p>
                <div class="meta-list">
                    <div><strong>Ngalan sa Bata:</strong> GABRIEL REYES DELA CRUZ</div>
                    <div><strong>Amahan &amp; Inahan:</strong> JUAN DELA CRUZ &amp; ANA REYES</div>
                    <div><strong>Sponsors:</strong> RAMON MAGSAYSAY &amp; TERESA AQUINO</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=katin_awan_bunyag" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>

        <!-- Cluster Clearance for Baptism Sponsor -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-baptism">Baptism · Sponsor</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Auto-filled</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Cluster Clearance for Baptism Sponsor</h3>
                <p class="card-desc">Clearance para sa mga ninong/ninang sa bunyag. Awtomatikong nilalagay ang pangalan ng bata, ama, at maiden name ng ina mula sa Katin-awan.</p>
                <div class="meta-list">
                    <div><strong>Name of Sponsor:</strong> RAMON MAGSAYSAY</div>
                    <div><strong>Child &amp; Parents:</strong> GABRIEL (JUAN &amp; ANA REYES)</div>
                    <div><strong>Service Date:</strong> NOVEMBER 20, 2026</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=cluster_clearance_baptism_sponsor" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. PAGLUBONG / FUNERAL -->
    <h2 class="section-title">✝️ 3. Paglubong (Funeral Requirements)</h2>
    <div class="grid">
        <!-- Katin-awan sa Paglubong -->
        <div class="card">
            <div class="card-header">
                <span class="badge badge-funeral">Funeral · Main Form</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">PDF Generated</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">KATIN-AWAN SA PAGLUBONG</h3>
                <p class="card-desc">Official burial clearance na may kumpletong record ng namatay, sakramentong natanggap, sanhi ng pagkamatay, at iskedyul ng libing.</p>
                <div class="meta-list">
                    <div><strong>Ngalan sa Ilubong:</strong> PEDRO DELA CRUZ SR. (78 yrs old)</div>
                    <div><strong>Kanus-a Ilubong:</strong> OCTOBER 25, 2026 @ 2:00 PM</div>
                    <div><strong>Responde / Contact:</strong> JUAN DELA CRUZ (09123456789)</div>
                </div>
                <div class="btn-group">
                    <a href="preview-forms.php?pdf=katin_awan_paglubong" target="_blank" class="btn btn-primary">
                        🖨️ Open / Print PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. OFFICIAL CHURCH CERTIFICATES -->
    <h2 class="section-title">📜 4. Official Parish Certificates (Templates)</h2>
    <div class="grid">
        <!-- Certificate of Baptism -->
        <div class="card">
            <img src="public/img/certificates/Certificate of Baptism.jpg" alt="Certificate of Baptism" class="img-preview">
            <div class="card-header">
                <span class="badge badge-cert">Diocese of Tagbilaran</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Green Border</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Certificate of Baptism</h3>
                <p class="card-desc">May berdeng clover/ivy floral corner design. Naglalaman ng Child name, Parents, Birth &amp; Baptism dates, Place of Baptism, Godparents, at Book/Page/Serial No.</p>
                <div class="btn-group">
                    <a href="public/img/certificates/Certificate of Baptism.jpg" target="_blank" class="btn btn-outline">
                        🔍 View Template Fullscreen
                    </a>
                </div>
            </div>
        </div>

        <!-- Certificate of Marriage -->
        <div class="card">
            <img src="public/img/certificates/Certificate of Marriage.jpg" alt="Certificate of Marriage" class="img-preview">
            <div class="card-header">
                <span class="badge badge-cert">Holy Matrimony</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Red Filigree</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Certificate of Marriage</h3>
                <p class="card-desc">May pulang ornate vintage filigree scroll border. Naglalaman ng detalye ng mag-asawa, edad, nasyonalidad, tirahan, magulang, saksi, at Marriage License.</p>
                <div class="btn-group">
                    <a href="public/img/certificates/Certificate of Marriage.jpg" target="_blank" class="btn btn-outline">
                        🔍 View Template Fullscreen
                    </a>
                </div>
            </div>
        </div>

        <!-- Certificate of Confirmation -->
        <div class="card">
            <img src="public/img/certificates/Certification of Confirmation.jpg" alt="Certificate of Confirmation" class="img-preview">
            <div class="card-header">
                <span class="badge badge-cert">Confirmation</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Red Floral</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Certificate of Confirmation</h3>
                <p class="card-desc">May pulang snowflake &amp; floral scroll corners. Naglalaman ng pangalan ng kinumpilan, magulang, officiating Msgr., mga sponsors, at Confirmation Register No.</p>
                <div class="btn-group">
                    <a href="public/img/certificates/Certification of Confirmation.jpg" target="_blank" class="btn btn-outline">
                        🔍 View Template Fullscreen
                    </a>
                </div>
            </div>
        </div>

        <!-- Certificate of Death -->
        <div class="card">
            <img src="public/img/certificates/Cetficate of Death.jpg" alt="Certificate of Death" class="img-preview">
            <div class="card-header">
                <span class="badge badge-cert">Death &amp; Burial</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">Black Gothic</span>
            </div>
            <div class="card-body">
                <h3 class="card-title">Certificate of Death</h3>
                <p class="card-desc">May itim na Gothic filigree corners. Naglalaman ng pangalan ng yumao, tirahan, edad, petsa at sanhi ng kamatayan, sementeryo, at Register of Dead Book/Folio No.</p>
                <div class="btn-group">
                    <a href="public/img/certificates/Cetficate of Death.jpg" target="_blank" class="btn btn-outline">
                        🔍 View Template Fullscreen
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<footer>
    <p>Our Lady of Mt. Carmel Parish · 6342 Balilihan, Bohol, Philippines</p>
    <p style="margin-top:4px; font-size:0.8rem; color:#8c827a;">ParishHub Management System · Automated Documentary Requirements &amp; Certificate Generator</p>
</footer>

</body>
</html>
