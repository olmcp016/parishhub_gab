<?php
const WEDDING_FORM_TYPES = ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance'];

if (is_file(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';

function weddingFormDefinition(string $type): array
{
    return match ($type) {
        'matrimony_application' => ['title' => 'MATRIMONY / MARRIAGE APPLICATION FORM', 'fields' => [
            'date_applied' => 'Date Applied', 'groom_name' => 'Groom Name', 'groom_age' => 'Groom Age', 'groom_birth_date' => 'Groom Date of Birth', 'groom_father' => "Groom Father's Name", 'groom_mother' => "Groom Mother's Maiden Name", 'groom_address' => 'Groom Address', 'groom_cell' => 'Groom Cell No.', 'bride_name' => 'Bride Name', 'bride_age' => 'Bride Age', 'bride_birth_date' => 'Bride Date of Birth', 'bride_father' => "Bride Father's Name", 'bride_mother' => "Bride Mother's Maiden Name", 'bride_address' => 'Bride Address', 'bride_cell' => 'Bride Cell No.'
        ]],
        'cluster_clearance' => ['title' => 'KATIN-AWAN SA KASAL', 'fields' => [
            'kaslonon_name' => 'Ngalan sa Kaslonon', 'kaslonon_age' => 'Edad', 'kaslonon_status' => 'Estado', 'kaslonon_birth_date' => 'Petsa Natawo', 'kaslonon_religion' => 'Relihiyon', 'father_name' => 'Amahan', 'father_religion' => "Father's Religion", 'mother_name' => 'Inahan', 'mother_religion' => "Mother's Religion", 'sponsor_1' => 'Sponsor 1', 'sponsor_2' => 'Sponsor 2', 'parent_marriage' => 'Unsang Kasala ang Nadawat sa Ginikanan?', 'marriage_place' => 'Diin', 'marriage_date' => 'Kanus-a', 'address' => 'Pinuy-anan', 'chapel' => 'Sakop sa Kapilya sa', 'cluster_name' => 'Ngalan sa Cluster', 'spouse_name' => 'Ngalan sa Pamanhunon/Pangasaw-onon', 'spouse_age' => 'Edad', 'spouse_status' => 'Estado', 'spouse_religion' => 'Relihiyon', 'spouse_address' => 'Pinuy-anan'
        ]],
        default => ['title' => 'CLUSTER CLEARANCE FOR WEDDING SPONSORS', 'fields' => [
            'recipient_name' => 'Name of Recipient', 'address' => 'Pinuy-anan', 'groom_name' => 'Name of the Groom', 'bride_name' => 'Name of the Bride', 'service_requested' => 'Service Requested', 'other_service' => 'Others, please specify', 'service_date' => 'Date of Service / Adlaw sa Serbisyo', 'active_status' => 'Active / Inactive', 'cluster_number' => 'Member of Cluster No.', 'cluster_name' => 'Cluster Name'
        ]]
    };
}

function weddingFormRequiredFields(string $type): array
{
    return match ($type) {
        'matrimony_application' => ['date_applied', 'groom_name', 'groom_age', 'groom_birth_date', 'groom_father', 'groom_mother', 'groom_address', 'groom_cell', 'bride_name', 'bride_age', 'bride_birth_date', 'bride_father', 'bride_mother', 'bride_address', 'bride_cell'],
        'cluster_clearance' => ['kaslonon_name', 'kaslonon_age', 'kaslonon_status', 'kaslonon_birth_date', 'kaslonon_religion', 'father_name', 'father_religion', 'mother_name', 'mother_religion', 'sponsor_1', 'sponsor_2', 'parent_marriage', 'marriage_place', 'marriage_date', 'address', 'chapel', 'cluster_name', 'spouse_name', 'spouse_age', 'spouse_status', 'spouse_religion', 'spouse_address'],
        default => ['recipient_name', 'address', 'groom_name', 'bride_name', 'service_date', 'active_status', 'cluster_number', 'cluster_name'],
    };
}

function weddingFormStatusLabel(?string $status): string
{
    return match ($status) {
        'pending_review', 'generated' => 'Pending Review',
        'approved' => 'Approved',
        'rejected' => 'Needs Revision',
        'draft' => 'Draft',
        default => 'Not Started',
    };
}

function weddingFormPdf(string $type, array $data): string
{
    if (class_exists('FPDF')) {
        if ($type === 'cluster_clearance') return weddingKatinPdf($data);
        if ($type === 'wedding_sponsor_clearance') return weddingSponsorPdf($data);
    }
    $def = weddingFormDefinition($type);
    $lines = ['Our Lady of Mt. Carmel Parish', '6342 Balilihan, Bohol, Philippines', '', $def['title'], str_repeat('=', 72), ''];
    foreach ($def['fields'] as $key => $label) {
        if ($type === 'cluster_clearance' && in_array($key, ['parent_marriage'], true)) continue;
        if ($type === 'wedding_sponsor_clearance' && $key === 'service_requested') continue;
        $value = is_array($data[$key] ?? null) ? implode(', ', $data[$key]) : (string) ($data[$key] ?? '');
        $lines[] = $label . ': ' . $value;
    }
    if ($type === 'cluster_clearance') {
        $selectedMarriage = (string) ($data['parent_marriage'] ?? '');
        $lines[] = 'UNSANG KASALA ANG NADAWAT SA GINIKANAN?';
        $lines[] = 'SIMBAHAN: ' . ($selectedMarriage === 'Simbahan' ? '[X]' : '[ ]') . '    SIBIL: ' . ($selectedMarriage === 'Sibil' ? '[X]' : '[ ]') . '    WALA: ' . ($selectedMarriage === 'Wala' ? '[X]' : '[ ]');
        $lines[] = '';
        $lines[] = 'PAHINUDOM: Human mamatud-i kining tanan, kini pagapirmahan sa Cluster Leader, Cluster Treasurer ug Chapel Chairman, Chapel Treasurer ug dad-on sa mga hingtungdan ngadto sa simbahan (apil na ang mga papeles nga gikinahanglan alang sa kasal ug mga sponsors) ug ihatag ngadto sa Parish Clerk.';
        $lines[] = '';
        $lines[] = 'Cluster Family and Life: ______________________________';
        $lines[] = 'Chapel Family and Life: ______________________________';
        $lines[] = 'Cluster Leader: ______________________________________';
        $lines[] = 'Chapel Chairman: ____________________________________';
        $lines[] = 'Cluster Treasurer: ___________________________________';
        $lines[] = 'Chapel Treasurer: ____________________________________';
        $lines[] = 'Parish Priest: _______________________________________';
    } elseif ($type === 'wedding_sponsor_clearance') {
        $selectedService = (string) ($data['service_requested'] ?? 'Kasal');
        $serviceOptions = ['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang'];
        $lines[] = 'SERVICE REQUESTED: (Please check)';
        $lines[] = implode('   ', array_map(static fn(string $option): string => '[' . ($selectedService === $option ? 'X' : ' ') . '] ' . $option, $serviceOptions));
        $lines[] = '[ ' . ($selectedService === 'Others' ? 'X' : ' ') . ' ] Others, please specify: ' . (string) ($data['other_service'] ?? '');
        $lines[] = 'Pls. Check:  [ ] Active   [ ] Inactive';
        $lines[] = '';
        $lines[] = 'VERIFIED BY:';
        $lines[] = 'Ngalan ug pirma sa Cluster Treasurer: __________________________';
        $lines[] = 'Ngalan ug pirma sa Cluster Leader: ____________________________';
        $lines[] = 'Ngalan ug pirma sa Chapel Treasurer: __________________________';
        $lines[] = 'Ngalan ug pirma sa Chapel Chairman: ___________________________';
    }
    return minimalTextPdf($lines);
}

function weddingPdfHeader(FPDF $pdf, string $title): void
{
    $pdf->SetMargins(16, 10, 16);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage('P', 'A4');
    $logo = dirname(__DIR__) . '/public/img/logo.png';
    if (is_file($logo)) $pdf->Image($logo, 18, 10, 28, 28, 'PNG');
    $pdf->SetTextColor(20, 92, 18);
    $pdf->SetFont('Times', 'B', 18);
    $pdf->SetXY(48, 14); $pdf->Cell(145, 8, 'Our Lady of Mt. Carmel Parish', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 10); $pdf->SetX(48); $pdf->Cell(145, 5, '6342 BALILIHAN, BOHOL PHILIPPINES', 0, 1, 'C');
    $pdf->SetFont('Times', '', 9); $pdf->SetX(48); $pdf->Cell(145, 5, 'Email address: mountcarmelbalilihan@gmail.com', 0, 1, 'C');
    $pdf->SetDrawColor(20, 92, 18); $pdf->SetLineWidth(0.8); $pdf->Line(15, 42, 195, 42);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Times', 'B', 15); $pdf->SetXY(15, 47); $pdf->Cell(180, 8, $title, 0, 1, 'C');
}

function weddingPdfField(FPDF $pdf, string $label, string $value, float $x, float $y, float $width, float $labelWidth = 38, float $height = 6): void
{
    $value = weddingPdfFit($pdf, $value, $width - $labelWidth - 2, 9);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY($x, $y); $pdf->Cell($labelWidth, $height, weddingPdfText($label), 0, 0);
    $pdf->SetFont('Times', '', 9); $pdf->Cell($width - $labelWidth, $height, $value, 'B', 0);
}

function weddingPdfText(string $value): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $converted === false ? '?' : $converted;
}

function weddingPdfFit(FPDF $pdf, string $value, float $maxWidth, float $fontSize): string
{
    $value = weddingPdfText(trim($value));
    $pdf->SetFont('Times', '', $fontSize);
    if ($pdf->GetStringWidth($value) <= $maxWidth) return $value;
    $ellipsis = '...';
    while ($value !== '' && $pdf->GetStringWidth($value . $ellipsis) > $maxWidth) $value = substr($value, 0, -1);
    return rtrim($value) . $ellipsis;
}

function weddingChoice(FPDF $pdf, string $label, bool $selected, float $x, float $y): void
{
    $pdf->SetFont('Times', '', 9); $pdf->SetXY($x, $y); $pdf->Cell(6, 5, $selected ? 'X' : '', 1, 0, 'C'); $pdf->Cell(25, 5, $label, 0, 0);
}

function weddingKatinPdf(array $data): string
{
    $pdf = new FPDF('P', 'mm', 'A4'); weddingPdfHeader($pdf, 'KATIN-AWAN SA KASAL');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    // Keep the EDAD column protected: the name underline ends at x=138,
    // leaving a fixed gap before the EDAD field begins at x=143.
    weddingPdfField($pdf, 'NGALAN SA KASLONON:', $v('kaslonon_name'), 18, 61, 120, 48);
    weddingPdfField($pdf, 'EDAD:', $v('kaslonon_age'), 143, 61, 49, 17);
    weddingPdfField($pdf, 'ESTADO:', $v('kaslonon_status'), 18, 70, 52, 24);
    weddingPdfField($pdf, 'PETSA NATAWO:', $v('kaslonon_birth_date'), 74, 70, 73, 34);
    weddingPdfField($pdf, 'RELIHIYON:', $v('kaslonon_religion'), 149, 70, 43, 25);
    weddingPdfField($pdf, 'AMAHAN:', $v('father_name'), 18, 79, 132, 25);
    weddingPdfField($pdf, 'RELIHIYON:', $v('father_religion'), 153, 79, 39, 25);
    weddingPdfField($pdf, 'INAHAN:', $v('mother_name'), 18, 88, 132, 25);
    weddingPdfField($pdf, 'RELIHIYON:', $v('mother_religion'), 153, 88, 39, 25);
    weddingPdfField($pdf, 'SPONSORS: 1.', $v('sponsor_1'), 18, 97, 88, 30);
    weddingPdfField($pdf, '2.', $v('sponsor_2'), 108, 97, 84, 10);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 107); $pdf->Cell(174, 5, 'UNSANG KASALA ANG NADAWAT SA GINIKANAN?', 0, 1);
    weddingChoice($pdf, 'SIMBAHAN', $v('parent_marriage') === 'Simbahan', 18, 114);
    weddingChoice($pdf, 'SIBIL', $v('parent_marriage') === 'Sibil', 75, 114);
    weddingChoice($pdf, 'WALA', $v('parent_marriage') === 'Wala', 125, 114);
    weddingPdfField($pdf, 'DIIN:', $v('marriage_place'), 18, 123, 90, 20); weddingPdfField($pdf, 'KANUS-A:', $v('marriage_date'), 111, 123, 81, 27);
    weddingPdfField($pdf, 'PINUY-ANAN:', $v('address'), 18, 132, 174, 30);
    weddingPdfField($pdf, 'SAKOP SA KAPILYA SA:', $v('chapel'), 18, 141, 174, 47);
    weddingPdfField($pdf, 'NGALAN SA CLUSTER:', $v('cluster_name'), 18, 150, 174, 38);
    weddingPdfField($pdf, 'NGALAN SA PAMANHUNON/PANGASAW-ONON:', $v('spouse_name'), 18, 159, 174, 78);
    weddingPdfField($pdf, 'EDAD:', $v('spouse_age'), 18, 168, 48, 17); weddingPdfField($pdf, 'ESTADO:', $v('spouse_status'), 69, 168, 61, 25); weddingPdfField($pdf, 'RELIHIYON:', $v('spouse_religion'), 133, 168, 59, 25);
    weddingPdfField($pdf, 'PINUY-ANAN:', $v('spouse_address'), 18, 177, 174, 30);
    $pdf->SetFont('Times', 'B', 8); $pdf->SetXY(18, 186); $pdf->MultiCell(174, 4, 'PAHINUDOM: Human mamatud-i kining tanan, kini pagapirmahan sa Cluster Leader, Cluster Treasurer ug Chapel Chairman, Chapel Treasurer ug dad-on sa mga hingtungdan ngadto sa simbahan (apil na ang mga papeles nga gikinahanglan alang sa kasal ug mga sponsors) ug ihatag ngadto sa Parish Clerk.');
    $pdf->SetFont('Times', '', 8); $pdf->SetXY(18, 207); $pdf->Cell(80, 5, '__________________________', 0, 0); $pdf->Cell(80, 5, '__________________________', 0, 1);
    $pdf->Cell(80, 5, 'Cluster Family and Life', 0, 0, 'C'); $pdf->Cell(80, 5, 'Chapel Family and Life', 0, 1, 'C');
    $pdf->SetXY(18, 220); $pdf->Cell(80, 5, '__________________________', 0, 0); $pdf->Cell(80, 5, '__________________________', 0, 1);
    $pdf->Cell(80, 5, 'Cluster Leader', 0, 0, 'C'); $pdf->Cell(80, 5, 'Chapel Chairman', 0, 1, 'C');
    $pdf->SetXY(18, 233); $pdf->Cell(80, 5, '__________________________', 0, 0); $pdf->Cell(80, 5, '__________________________', 0, 1);
    $pdf->Cell(80, 5, 'Cluster Treasurer', 0, 0, 'C'); $pdf->Cell(80, 5, 'Chapel Treasurer', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 10); $pdf->SetXY(70, 257); $pdf->Cell(70, 5, weddingPdfText('REV. FR. AL JOHN A. MIÑOZA'), 0, 1, 'C'); $pdf->SetFont('Times', '', 9); $pdf->SetX(70); $pdf->Cell(70, 5, 'Parish Priest', 0, 1, 'C');
    return $pdf->Output('S');
}

function weddingSponsorPdf(array $data): string
{
    $pdf = new FPDF('P', 'mm', 'A4'); weddingPdfHeader($pdf, 'CLUSTER CLEARANCE FOR WEDDING SPONSORS');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    weddingPdfField($pdf, 'NAME OF RECIPIENT:', $v('recipient_name'), 18, 64, 174, 43);
    weddingPdfField($pdf, 'PINUY-ANAN:', $v('address'), 18, 75, 174, 30);
    weddingPdfField($pdf, 'NAME OF THE GROOM:', $v('groom_name'), 18, 88, 174, 45);
    weddingPdfField($pdf, 'NAME OF THE BRIDE:', $v('bride_name'), 18, 99, 174, 45);
    $pdf->SetFont('Times', 'B', 10); $pdf->SetXY(18, 113); $pdf->Cell(174, 5, 'SERVICE REQUESTED: (Please check)', 0, 1);
    $options = ['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others']; $selected = $v('service_requested') ?: 'Kasal';
    $x = 20; foreach ($options as $option) { weddingChoice($pdf, $option, $selected === $option, $x, 121); $x += $option === 'Ninong/Ninang' ? 43 : 31; }
    weddingPdfField($pdf, 'Others, please specify:', $v('other_service'), 18, 130, 174, 48);
    weddingPdfField($pdf, 'DATE OF SERVICE/ADLAW SA SERBISYO:', $v('service_date'), 18, 141, 108, 76);
    weddingChoice($pdf, 'Active', $v('active_status') === 'Active', 130, 141); weddingChoice($pdf, 'Inactive', $v('active_status') === 'Inactive', 163, 141);
    weddingPdfField($pdf, 'Member of Cluster No.:', $v('cluster_number'), 18, 151, 58, 43); weddingPdfField($pdf, 'Cluster Name:', $v('cluster_name'), 82, 151, 110, 30);
    $pdf->SetFont('Times', 'B', 10); $pdf->SetXY(18, 171); $pdf->Cell(174, 5, 'VERIFIED BY:', 0, 1);
    $pdf->SetFont('Times', '', 9); $y = 184; foreach (['Ngalan ug pirma sa Cluster Treasurer', 'Ngalan ug pirma sa Cluster Leader', 'Ngalan ug pirma sa Chapel Treasurer', 'Ngalan ug pirma sa Chapel Chairman'] as $label) { $pdf->SetXY(18, $y); $pdf->Cell(174, 5, $label . ' ________________________________', 0, 1); $y += 14; }
    return $pdf->Output('S');
}

function minimalTextPdf(array $lines): string
{
    $wrapped = [];
    foreach ($lines as $line) {
        $line = (string) $line;
        if ($line === '') { $wrapped[] = ''; continue; }
        $parts = preg_split('/\s+/', $line);
        $current = '';
        foreach ($parts as $part) {
            if ($current !== '' && strlen($current . ' ' . $part) > 92) {
                $wrapped[] = $current;
                $current = $part;
            } else {
                $current = $current === '' ? $part : $current . ' ' . $part;
            }
        }
        if ($current !== '') $wrapped[] = $current;
    }
    $pages = array_chunk($wrapped, 43);
    if (!$pages) $pages = [[]];
    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $pageRefs = [];
    $fontObject = 3 + count($pages) * 2;
    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($i) => (3 + ($i * 2)) . ' 0 R', array_keys($pages))) . '] /Count ' . count($pages) . ' >>';
    foreach ($pages as $pageIndex => $pageLines) {
        $pageObject = count($objects) + 1;
        $contentObject = $pageObject + 1;
        $pageRefs[] = $pageObject;
        $content = "BT\n/F1 10 Tf\n50 790 Td\n";
        foreach ($pageLines as $line) {
            $safe = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            if ($safe === false) $safe = '?';
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $safe);
            $content .= '(' . $safe . ") Tj\n0 -16 Td\n";
        }
        $content .= "ET\n";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontObject . ' 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
    }
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $i => $object) { $offsets[$i + 1] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}
