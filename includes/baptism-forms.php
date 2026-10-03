<?php
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

const BAPTISM_FORM_TYPES = ['katin_awan_bunyag', 'cluster_clearance_baptism_sponsor'];

function baptismFormDefinition(string $type): array
{
    return $type === 'katin_awan_bunyag'
        ? ['title' => 'KATIN-AWAN SA BUNYAG', 'fields' => ['child_name'=>'Ngalan sa Bunyagan','birth_date'=>'Petsa Natawo','birth_place'=>'Diin Natawo','father_name'=>'Amahan','father_religion'=>"Father's Religion",'mother_name'=>'Inahan','mother_religion'=>"Mother's Religion",'parent_marriage'=>'Unsang Kasala ang Nadawat sa Ginikanan?','marriage_place'=>'Diin','sponsor_1'=>'Sponsor 1','sponsor_2'=>'Sponsor 2','chapel'=>'Sakop sa Kapilya sa','cluster_name'=>'Ngalan sa Cluster','barangay'=>'Ngalan sa Barangay','cellphone'=>'Cellphone Number']]
        : ['title' => 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR', 'fields' => ['sponsor_name'=>'Name of Sponsor','address'=>'Pinuy-anan','child_name'=>'Name of the Child','father_name'=>'Father','mother_maiden_name'=>'Mother Maiden Name','service_requested'=>'Service Requested','other_service'=>'Others, please specify','service_date'=>'Date of Service / Adlaw sa Serbisyo','active_status'=>'Active / Inactive','cluster_number'=>'Member of Cluster No.','cluster_name'=>'Cluster Name']];
}

function baptismFormRequiredFields(string $type): array
{
    return $type === 'katin_awan_bunyag'
        ? ['child_name','birth_date','birth_place','father_name','father_religion','mother_name','mother_religion','parent_marriage','marriage_place','sponsor_1','sponsor_2','chapel','cluster_name','barangay','cellphone']
        : ['sponsor_name','address','child_name','father_name','mother_maiden_name','service_date','active_status','cluster_number','cluster_name'];
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
}

function baptismPdfField(FPDF $pdf, string $label, string $value, float $x, float $y, float $width, float $labelWidth, float $size = 9): void
{
    $pdf->SetFont('Times', 'B', $size); $pdf->SetXY($x, $y); $pdf->Cell($labelWidth, 6, baptismPdfText($label), 0, 0);
    $pdf->SetFont('Times', '', $size); $pdf->Cell($width - $labelWidth, 6, baptismPdfFit($pdf, $value, $width - $labelWidth - 2, $size), 'B', 0);
}

function baptismPdfChoice(FPDF $pdf, string $label, bool $selected, float $x, float $y): void
{
    $pdf->SetFont('Times', '', 9); $pdf->SetXY($x, $y); $pdf->Cell(6, 5, $selected ? 'X' : '', 1, 0, 'C'); $pdf->Cell(30, 5, baptismPdfText($label), 0, 0);
}

function baptismKatinPdf(array $data): string
{
    $pdf = new FPDF('P', 'mm', 'A4'); baptismPdfHeader($pdf, 'KATIN-AWAN SA BUNYAG');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    baptismPdfField($pdf, 'NGALAN SA BUNYAGAN:', $v('child_name'), 18, 62, 174, 46);
    baptismPdfField($pdf, 'PETSA NATAWO:', $v('birth_date'), 18, 72, 84, 34);
    baptismPdfField($pdf, 'DIIN NATAWO:', $v('birth_place'), 105, 72, 87, 31, 8.5);
    baptismPdfField($pdf, 'AMAHAN:', $v('father_name'), 18, 82, 108, 25);
    baptismPdfField($pdf, 'RELIHIYON:', $v('father_religion'), 130, 82, 62, 25);
    baptismPdfField($pdf, 'INAHAN:', $v('mother_name'), 18, 92, 108, 25);
    baptismPdfField($pdf, 'RELIHIYON:', $v('mother_religion'), 130, 92, 62, 25);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 102); $pdf->Cell(174, 5, 'UNSANG KASALA ANG NADAWAT SA GINIKANAN?', 0, 1);
    baptismPdfChoice($pdf, 'SIMBAHAN', $v('parent_marriage') === 'Simbahan', 18, 109);
    baptismPdfChoice($pdf, 'SIBIL', $v('parent_marriage') === 'Sibil', 78, 109);
    baptismPdfChoice($pdf, 'WALA', $v('parent_marriage') === 'Wala', 132, 109);
    baptismPdfField($pdf, 'DIIN:', $v('marriage_place'), 18, 119, 174, 20);
    baptismPdfField($pdf, 'SPONSORS: 1.', $v('sponsor_1'), 18, 129, 174, 31);
    baptismPdfField($pdf, '2.', $v('sponsor_2'), 18, 139, 174, 10);
    baptismPdfField($pdf, 'SAKOP SA KAPILYA SA:', $v('chapel'), 18, 149, 174, 47);
    baptismPdfField($pdf, 'NGALAN SA CLUSTER:', $v('cluster_name'), 18, 159, 174, 40);
    baptismPdfField($pdf, 'NGALAN SA BARANGAY:', $v('barangay'), 18, 169, 174, 46);
    $pdf->SetFont('Times', '', 8); $y = 195;
    foreach ([['Cluster Service', 'Chapel Service'], ['Cluster Leader', 'Chapel Chairman'], ['Cluster Treasurer', 'Chapel Treasurer']] as $row) {
        $pdf->SetXY(18, $y); $pdf->Cell(80, 5, '__________________________', 0, 0); $pdf->Cell(80, 5, '__________________________', 0, 1);
        $pdf->Cell(80, 5, $row[0], 0, 0, 'C'); $pdf->Cell(80, 5, $row[1], 0, 1, 'C'); $y += 13;
    }
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(54, 240); $pdf->Cell(90, 5, baptismPdfText('REV. FR. AL JOHN A. MINOZA, SThL'), 0, 1, 'C');
    $pdf->SetFont('Times', '', 9); $pdf->SetX(54); $pdf->Cell(90, 5, 'Parish Priest', 0, 1, 'C');

    $pdf->SetFont('Times', 'B', 8); $pdf->SetXY(18, 255); $pdf->Cell(135, 5, baptismPdfText('PAHINUMDOM: Isukip niining maong katin-awan ang Certificate of Live Birth sa bata- original og usa ka Xerox copy .'), 0, 0);
    $pdf->SetFont('Times', 'B', 9); $pdf->Cell(28, 5, baptismPdfText('Cellphone Number:'), 0, 0);
    $pdf->SetFont('Times', '', 9); $pdf->Cell(0, 5, baptismPdfFit($pdf, $v('cellphone'), 35, 9), 'B', 1);
    return $pdf->Output('S');
}

function baptismSponsorPdf(array $data): string
{
    $pdf = new FPDF('P', 'mm', 'A4'); baptismPdfHeader($pdf, 'CLUSTER CLEARANCE FOR BAPTISM SPONSOR');
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    baptismPdfField($pdf, 'NAME OF SPONSOR:', $v('sponsor_name'), 18, 64, 174, 43);
    baptismPdfField($pdf, 'PINUY-ANAN:', $v('address'), 18, 75, 174, 30);
    baptismPdfField($pdf, 'NAME OF THE CHILD:', $v('child_name'), 18, 86, 174, 45);
    baptismPdfField($pdf, 'FATHER:', $v('father_name'), 18, 97, 174, 22);
    baptismPdfField($pdf, 'MOTHER MAIDEN NAME:', $v('mother_maiden_name'), 18, 108, 174, 50);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 119); $pdf->Cell(174, 5, 'SERVICE REQUESTED: (Please check)', 0, 1);
    $selected = $v('service_requested') ?: 'Bunyag'; $x = 20;
    foreach (['Bunyag', 'Confirmation', 'Kasal', 'Ninong/Ninang', 'Others'] as $option) { baptismPdfChoice($pdf, $option, $selected === $option, $x, 127); $x += $option === 'Ninong/Ninang' ? 43 : 31; }
    baptismPdfField($pdf, 'Others, please specify:', $v('other_service'), 18, 137, 174, 48);
    baptismPdfField($pdf, 'DATE OF SERVICE/ADLAW SA SERBISYO', $v('service_date'), 18, 148, 112, 76);
    baptismPdfChoice($pdf, 'Active', $v('active_status') === 'Active', 132, 148); baptismPdfChoice($pdf, 'Inactive', $v('active_status') === 'Inactive', 165, 148);
    baptismPdfField($pdf, 'Member of Cluster No.:', $v('cluster_number'), 18, 159, 76, 43, 8.5);
    baptismPdfField($pdf, 'Cluster Name:', $v('cluster_name'), 98, 159, 94, 30, 8.5);
    $pdf->SetFont('Times', 'B', 10); $pdf->SetXY(18, 174); $pdf->Cell(174, 5, 'VERIFIED BY:', 0, 1);
    $pdf->SetFont('Times', '', 8); $y = 187;
    foreach (['Ngalan ug pirma sa Cluster Treasurer', 'Ngalan ug pirma sa Cluster Leader', 'Ngalan ug pirma sa Chapel Treasurer', 'Ngalan ug pirma sa Chapel Chairman'] as $label) { $pdf->SetXY(18, $y); $pdf->Cell(174, 5, baptismPdfText($label . ' ________________________________'), 0, 1); $y += 12; }
    return $pdf->Output('S');
}

function baptismFormPdf(string $type, array $data): string
{
    if (!class_exists('FPDF')) throw new RuntimeException('FPDF is required to generate Baptism forms.');
    return $type === 'katin_awan_bunyag' ? baptismKatinPdf($data) : baptismSponsorPdf($data);
}
