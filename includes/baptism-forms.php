<?php
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (is_file(__DIR__ . '/../fpdf.php')) {
    require_once __DIR__ . '/../fpdf.php';
}
// Suppress utf8_encode deprecation warnings from FPDF (PHP 8.2+) which break output headers
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/validation.php';

const BAPTISM_FORM_TYPES = ['katin_awan_bunyag', 'cluster_clearance_baptism_sponsor'];

function baptismFormDefinition(string $type): array
{
    return $type === 'katin_awan_bunyag'
        ? ['title' => 'KATIN-AWAN SA BUNYAG', 'fields' => ['child_name'=>'Ngalan sa Bunyagan','birth_date'=>'Petsa Natawo','birth_place'=>'Diin Natawo','father_name'=>'Amahan','father_religion'=>"Father's Religion",'mother_name'=>'Inahan','mother_religion'=>"Mother's Religion",'parent_marriage'=>'Unsang Kasala ang Nadawat sa Ginikanan?','marriage_place'=>'Diin','sponsor_1'=>'Sponsor 1','sponsor_2'=>'Sponsor 2','chapel'=>'Sakop sa Kapilya sa','cluster_name'=>'Ngalan sa Cluster','barangay'=>'Ngalan sa Barangay','cellphone'=>'Cellphone Number']]
        : ['title' => 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR', 'fields' => ['sponsor_name'=>'Name of Sponsor','address'=>'Pinuy-anan','child_name'=>'Name of the Child','father_name'=>'Father','mother_maiden_name'=>'Mother Maiden Name','service_date'=>'Date of Service / Adlaw sa Serbisyo','cluster_number'=>'Member of Cluster No.','cluster_name'=>'Cluster Name']];
}

function baptismFormRequiredFields(string $type): array
{
    return $type === 'katin_awan_bunyag'
        ? ['child_name','birth_date','birth_place','father_name','father_religion','mother_name','mother_religion','parent_marriage','sponsor_1','sponsor_2','chapel','cluster_name','barangay','cellphone']
        : ['sponsor_name','address','child_name','father_name','mother_maiden_name','service_date','cluster_number','cluster_name'];
}

function baptismNormalizeData(array $data): array
{
    if (empty($data['parent_marriage'])) {
        foreach (['simbahan' => 'Simbahan', 'sibil' => 'Sibil', 'wala' => 'Wala'] as $legacyKey => $value) {
            if (!empty($data['parent_marriage_' . $legacyKey])) {
                $data['parent_marriage'] = $value;
                break;
            }
        }
    }
    $textFields = [
        'child_name', 'birth_place', 'father_name', 'father_religion',
        'mother_name', 'mother_religion', 'marriage_place', 'sponsor_1',
        'sponsor_2', 'chapel', 'cluster_name', 'barangay',
        'sponsor_name', 'address', 'mother_maiden_name'
    ];
    foreach ($textFields as $tf) {
        if (isset($data[$tf]) && is_string($data[$tf])) {
            $data[$tf] = mb_strtoupper(trim($data[$tf]), 'UTF-8');
        }
    }
    return $data;
}

/** @return string[] */
function baptismFormValidationErrors(string $type, array $data, bool $requireComplete = true): array
{
    $data = baptismNormalizeData($data);
    $definition = baptismFormDefinition($type);
    $errors = [];
    if ($requireComplete) {
        foreach (baptismFormRequiredFields($type) as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') $errors[] = $definition['fields'][$key] . ' is required.';
        }
    }

    $nameKeys = $type === 'katin_awan_bunyag'
        ? ['child_name', 'father_name', 'mother_name', 'sponsor_1', 'sponsor_2']
        : ['sponsor_name', 'child_name', 'father_name', 'mother_maiden_name'];
    foreach ($nameKeys as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '' && validateName($value) === false) $errors[] = $definition['fields'][$key] . ' contains invalid characters.';
    }

    $placeKeys = $type === 'katin_awan_bunyag'
        ? ['birth_place', 'chapel', 'cluster_name', 'barangay']
        : ['address', 'cluster_name'];
    if ($type === 'katin_awan_bunyag' && ($data['parent_marriage'] ?? '') !== 'Wala') $placeKeys[] = 'marriage_place';
    foreach ($placeKeys as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '' && validateAddress($value) === false) $errors[] = $definition['fields'][$key] . ' contains invalid characters.';
    }

    if ($type === 'katin_awan_bunyag') {
        $birthDate = trim((string) ($data['birth_date'] ?? ''));
        if ($birthDate !== '' && validateCalendarDate($birthDate) === false) $errors[] = 'Petsa Natawo must be a valid birth date.';
        $marriage = (string) ($data['parent_marriage'] ?? '');
        if ($marriage !== '' && validateEnum($marriage, ['Simbahan', 'Sibil', 'Wala']) === false) $errors[] = 'Select one valid marriage status for the parents.';
        if ($requireComplete && in_array($marriage, ['Simbahan', 'Sibil'], true) && trim((string) ($data['marriage_place'] ?? '')) === '') {
            $errors[] = 'Diin is required when the parents were married.';
        }
        $phone = trim((string) ($data['cellphone'] ?? ''));
        if ($phone !== '' && validatePhilippineMobile($phone) === false) $errors[] = 'Cellphone Number must be 11 digits starting with 09.';
    } else {
        $serviceDate = trim((string) ($data['service_date'] ?? ''));
        if ($serviceDate !== '' && validateCalendarDate($serviceDate) === false) $errors[] = 'Date of Service must be a valid date.';
        $clusterNumber = trim((string) ($data['cluster_number'] ?? ''));
        if ($clusterNumber !== '' && validatePositiveInteger($clusterNumber, 9999) === false) $errors[] = 'Member of Cluster No. must be a positive whole number.';
    }
    $limits = $type === 'katin_awan_bunyag'
        ? ['child_name'=>150,'birth_place'=>150,'father_name'=>150,'father_religion'=>50,'mother_name'=>150,'mother_religion'=>50,'marriage_place'=>150,'sponsor_1'=>150,'sponsor_2'=>150,'chapel'=>150,'cluster_name'=>150,'barangay'=>150]
        : ['sponsor_name'=>150,'address'=>255,'child_name'=>150,'father_name'=>150,'mother_maiden_name'=>150,'cluster_name'=>150];
    $lengthErrors = [];
    foreach ($limits as $key => $limit) {
        if (mb_strlen((string) ($data[$key] ?? '')) > $limit) $lengthErrors[] = $definition['fields'][$key] . " must not exceed {$limit} characters.";
    }
    // Save Draft keeps partial work as typed: only the length limits apply.
    return array_values(array_unique($requireComplete ? array_merge($errors, $lengthErrors) : $lengthErrors));
}

function baptismPdfDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $formatted = $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('F j, Y') : $date;
    return mb_strtoupper($formatted, 'UTF-8');
}

function baptismPdfText(string $value): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $converted === false ? '?' : $converted;
}

function baptismPdfFit(FPDF $pdf, string $value, float $width, float $size = 9): string
{
    $value = baptismPdfText(trim($value));
    for ($fontSize = $size; $fontSize >= 7; $fontSize -= 0.5) {
        $pdf->SetFont('Times', '', $fontSize);
        if ($pdf->GetStringWidth($value) <= $width) return $value;
    }
    $pdf->SetFont('Times', '', 7);
    while ($value !== '' && $pdf->GetStringWidth($value . '...') > $width) $value = substr($value, 0, -1);
    return rtrim($value) . '...';
}

function baptismPdfHeader(FPDF $pdf, string $title): void
{
    $pdf->SetMargins(16, 10, 16);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage('P', 'A4');
    $logo = dirname(__DIR__) . '/public/img/logo.png';
    if (is_file($logo)) $pdf->Image($logo, 18, 10, 28, 28, 'PNG');
    $pdf->SetTextColor(20, 92, 18);
    $pdf->SetFont('Times', 'B', 18); $pdf->SetXY(48, 14); $pdf->Cell(145, 8, 'Our Lady of Mt. Carmel Parish', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 10); $pdf->SetX(48); $pdf->Cell(145, 5, '6342 BALILIHAN, BOHOL PHILIPPINES', 0, 1, 'C');
    $pdf->SetFont('Times', '', 9); $pdf->SetX(48); $pdf->Cell(145, 5, 'Email address: mountcarmelbalilihan@gmail.com', 0, 1, 'C');
    $pdf->SetDrawColor(20, 92, 18); $pdf->SetLineWidth(0.8); $pdf->Line(15, 42, 195, 42);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Times', 'B', 15); $pdf->SetXY(15, 47); $pdf->Cell(180, 8, baptismPdfText($title), 0, 1, 'C');
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.3);
}

function baptismPdfField(FPDF $pdf, string $label, string $value, float $x, float $y, float $width, float $labelWidth, float $size = 9, float $valueSize = 0): void
{
    if ($valueSize <= 0) $valueSize = $size + 2; // answers render slightly larger than labels
    $pdf->SetFont('Times', 'B', $size); $pdf->SetXY($x, $y); $pdf->Cell($labelWidth, 6, baptismPdfText($label), 0, 0);
    $pdf->SetFont('Times', '', $valueSize); $pdf->Cell($width - $labelWidth, 6, baptismPdfFit($pdf, $value, $width - $labelWidth - 2, $valueSize), 'B', 0);
}

function baptismPdfChoice(FPDF $pdf, string $label, bool $selected, float $x, float $y): void
{
    $pdf->SetFont('Times', '', 9); $pdf->SetXY($x, $y); $pdf->Cell(6, 5, $selected ? 'X' : '', 1, 0, 'C'); $pdf->Cell(30, 5, baptismPdfText($label), 0, 0);
}

function baptismPdfSignature(FPDF $pdf, string $name, string $roleTitle, float $x, float $y, float $width): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($x, $y, $x + $width, $y);
    if ($name !== '') {
        $pdf->SetFont('Times', 'B', 9.5);
        $pdf->SetXY($x, $y - 5.5);
        $pdf->Cell($width, 5, baptismPdfText($name), 0, 0, 'C');
    }
    if ($roleTitle !== '') {
        $pdf->SetFont('Times', '', 8.5);
        $lines = explode("\n", $roleTitle);
        $offsetY = 1.2;
        foreach ($lines as $line) {
            $pdf->SetXY($x, $y + $offsetY);
            $pdf->Cell($width, 4, baptismPdfText($line), 0, 0, 'C');
            $offsetY += 4.0;
        }
    }
}

function baptismKatinPdf(array $data): string
{
    $data = baptismNormalizeData($data);
    $pdf = new FPDF('P', 'mm', 'A4');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $child = $v('child_name');
    $metaTitle = 'Katin-awan sa Bunyag' . ($child ? ' - ' . $child : '');
    $pdf->SetTitle($metaTitle, true);
    baptismPdfHeader($pdf, 'KATIN-AWAN SA BUNYAG');

    // Uniform label width for full-width rows (48mm) so all lines start at X=66 and end at X=192
    $fullW = 174.0;
    $fullLabelW = 48.0;

    baptismPdfField($pdf, 'NGALAN SA BUNYAGAN:', $v('child_name'), 18, 62, $fullW, $fullLabelW);

    // Two columns for birth / parents with uniform column alignment
    // Left col: X=18, width=90, labelWidth=32 -> line from 50 to 108
    // Right col: X=114, width=78, labelWidth=28 -> line from 142 to 192
    baptismPdfField($pdf, 'PETSA NATAWO:', baptismPdfDate($v('birth_date')), 18, 72, 90, 32);
    baptismPdfField($pdf, 'DIIN NATAWO:', $v('birth_place'), 114, 72, 78, 28, 8.5);

    baptismPdfField($pdf, 'AMAHAN:', $v('father_name'), 18, 82, 90, 32);
    baptismPdfField($pdf, 'RELIHIYON:', $v('father_religion'), 114, 82, 78, 28);

    baptismPdfField($pdf, 'INAHAN:', $v('mother_name'), 18, 92, 90, 32);
    baptismPdfField($pdf, 'RELIHIYON:', $v('mother_religion'), 114, 92, 78, 28);

    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 102); $pdf->Cell(174, 5, 'UNSANG KASALA ANG NADAWAT SA GINIKANAN?', 0, 1);
    baptismPdfChoice($pdf, 'SIMBAHAN', $v('parent_marriage') === 'Simbahan', 18, 109);
    baptismPdfChoice($pdf, 'SIBIL', $v('parent_marriage') === 'Sibil', 78, 109);
    baptismPdfChoice($pdf, 'WALA', $v('parent_marriage') === 'Wala', 132, 109);

    // Full-width fields with uniform $fullLabelW so underlines start at X=66 and end at X=192
    baptismPdfField($pdf, 'DIIN:', $v('marriage_place'), 18, 119, $fullW, $fullLabelW);

    // Sponsors: 1. and 2. aligned vertically with identical underlines from X=48 to X=192
    $pdf->SetFont('Times', 'B', 9);
    $pdf->SetXY(18, 129);
    $pdf->Cell(22, 6, baptismPdfText('SPONSORS:'), 0, 0);
    $pdf->Cell(6, 6, '1.', 0, 0, 'R');
    $pdf->Cell(2, 6, '', 0, 0);
    $pdf->SetFont('Times', '', 11);
    $pdf->Cell(144, 6, baptismPdfFit($pdf, $v('sponsor_1'), 142, 11), 'B', 0);

    $pdf->SetFont('Times', 'B', 9);
    $pdf->SetXY(18, 139);
    $pdf->Cell(22, 6, '', 0, 0);
    $pdf->Cell(6, 6, '2.', 0, 0, 'R');
    $pdf->Cell(2, 6, '', 0, 0);
    $pdf->SetFont('Times', '', 11);
    $pdf->Cell(144, 6, baptismPdfFit($pdf, $v('sponsor_2'), 142, 11), 'B', 0);

    baptismPdfField($pdf, 'SAKOP SA KAPILYA SA:', $v('chapel'), 18, 149, $fullW, $fullLabelW);
    baptismPdfField($pdf, 'NGALAN SA CLUSTER:', $v('cluster_name'), 18, 159, $fullW, $fullLabelW);
    baptismPdfField($pdf, 'NGALAN SA BARANGAY:', $v('barangay'), 18, 169, $fullW, $fullLabelW);

    // Signatures: 2 columns, centered vector lines and text
    $y = 194;
    foreach ([
        ['Cluster Service', 'Chapel Service'],
        ['Cluster Leader', 'Chapel Chairman'],
        ['Cluster Treasurer', 'Chapel Treasurer'],
    ] as $row) {
        baptismPdfSignature($pdf, '', $row[0], 22, $y, 72);
        baptismPdfSignature($pdf, '', $row[1], 116, $y, 72);
        $y += 14;
    }

    // Parish Priest centered
    baptismPdfSignature($pdf, 'REV. FR. AL JOHN A. MIÑOZA, SThL', 'Parish Priest', 60, 244, 90);

    $pdf->SetFont('Times', 'B', 7.5); $pdf->SetXY(18, 255);
    $pdf->MultiCell(116, 4, baptismPdfText('PAHINUMDOM: Isukip niining maong katin-awan ang Certificate of Live Birth sa bata - original og usa ka Xerox copy.'), 0, 'L');
    $pdf->SetFont('Times', 'B', 8.5); $pdf->SetXY(137, 255); $pdf->Cell(31, 5, baptismPdfText('Cellphone Number:'), 0, 0);
    $pdf->SetFont('Times', '', 8.5); $pdf->Cell(24, 5, baptismPdfFit($pdf, $v('cellphone'), 22, 8.5), 'B', 1);
    return $pdf->Output('S');
}

function baptismSponsorPdf(array $data): string
{
    $pdf = new FPDF('P', 'mm', 'A4');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $sponsor = $v('sponsor_name');
    $metaTitle = 'Cluster Clearance for Baptismal Sponsor' . ($sponsor ? ' - ' . $sponsor : '');
    $pdf->SetTitle($metaTitle, true);
    baptismPdfHeader($pdf, 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR');

    // Standardize top 5 rows with labelWidth = 48: all underlines start at X=66 and end at X=192 (width 174)
    $fullW = 174.0;
    $labelW = 48.0;
    baptismPdfField($pdf, 'NAME OF SPONSOR:', $v('sponsor_name'), 18, 64, $fullW, $labelW);
    baptismPdfField($pdf, 'PINUY-ANAN:', $v('address'), 18, 75, $fullW, $labelW);
    baptismPdfField($pdf, 'NAME OF THE CHILD:', $v('child_name'), 18, 86, $fullW, $labelW);
    baptismPdfField($pdf, 'FATHER:', $v('father_name'), 18, 97, $fullW, $labelW);
    baptismPdfField($pdf, 'MOTHER MAIDEN NAME:', $v('mother_maiden_name'), 18, 108, $fullW, $labelW);

    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 119); $pdf->Cell(174, 5, 'SERVICE REQUESTED: (Please check)', 0, 1);
    $selected = 'Bunyag'; $x = 20;
    foreach (['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others'] as $option) {
        baptismPdfChoice($pdf, $option, $selected === $option, $x, 127);
        $x += $option === 'Ninong/Ninang' ? 43 : 31;
    }
    baptismPdfField($pdf, 'Others, please specify:', '', 18, 137, $fullW, $labelW);

    // DATE OF SERVICE and Pls.Check
    baptismPdfField($pdf, 'DATE OF SERVICE / ADLAW SA SERBISYO:', baptismPdfDate($v('service_date')), 18, 148, 115, 68, 8);
    $pdf->SetFont('Times', 'B', 8.5); $pdf->SetXY(135, 148); $pdf->Cell(19, 6, 'Pls.Check:', 0, 0);
    baptismPdfChoice($pdf, 'Active', false, 154, 149);
    baptismPdfChoice($pdf, 'Inactive', false, 174, 149);

    // Cluster row
    baptismPdfField($pdf, 'Member of Cluster No.:', $v('cluster_number'), 18, 159, 78, 42, 8.5);
    baptismPdfField($pdf, 'Cluster Name:', $v('cluster_name'), 102, 159, 90, 26, 8.5);

    $pdf->SetFont('Times', 'B', 10); $pdf->SetXY(18, 175); $pdf->Cell(174, 5, 'VERIFIED BY:', 0, 1);

    // Vector-drawn signatures with centered names / roles (Image 3 fix)
    $y = 198;
    foreach ([
        ['Ngalan ug pirma sa Cluster Treasurer', 'Ngalan ug pirma sa Cluster Leader'],
        ['Ngalan ug pirma sa Chapel Treasurer', 'Ngalan ug pirma sa Chapel Chairman'],
    ] as $row) {
        baptismPdfSignature($pdf, '', $row[0], 20, $y, 74);
        baptismPdfSignature($pdf, '', $row[1], 116, $y, 74);
        $y += 19;
    }
    return $pdf->Output('S');
}

function baptismFormPdf(string $type, array $data): string
{
    if (!class_exists('FPDF')) throw new RuntimeException('FPDF is required to generate Baptism forms.');
    return $type === 'katin_awan_bunyag' ? baptismKatinPdf($data) : baptismSponsorPdf($data);
}
