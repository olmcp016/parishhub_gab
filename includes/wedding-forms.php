<?php
const WEDDING_FORM_TYPES = ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance'];

if (is_file(__DIR__ . '/../vendor/autoload.php')) require_once __DIR__ . '/../vendor/autoload.php';

function weddingFormDefinition(string $type): array
{
    return match ($type) {
        'matrimony_application' => ['title' => 'Marriage Requirement and Application Form', 'fields' => [
            'date_applied' => 'Date Applied',
            'groom_name' => 'Full Name',
            'groom_birth_date' => 'Date of Birth',
            'groom_father' => 'Father',
            'groom_mother' => 'Mother',
            'groom_mother_maiden_name' => "Mother's Maiden Name",
            'groom_address' => 'Address',
            'groom_cell' => 'Cell Number',
            'bride_name' => 'Full Name',
            'bride_birth_date' => 'Date of Birth',
            'bride_father' => 'Father',
            'bride_mother' => 'Mother',
            'bride_mother_maiden_name' => "Mother's Maiden Name",
            'bride_address' => 'Address',
            'bride_cell' => 'Cell Number',
            'wedding_date' => 'Date of Wedding',
            'wedding_time' => 'Time of Wedding',
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
        'matrimony_application' => ['date_applied', 'groom_name', 'groom_birth_date', 'groom_father', 'groom_mother', 'groom_mother_maiden_name', 'groom_address', 'groom_cell', 'bride_name', 'bride_birth_date', 'bride_father', 'bride_mother', 'bride_mother_maiden_name', 'bride_address', 'bride_cell', 'wedding_date', 'wedding_time'],
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
    if ($type === 'matrimony_application' && !class_exists('FPDF')) {
        throw new RuntimeException('FPDF is required to generate the Marriage Requirement and Application Form.');
    }
    if (class_exists('FPDF')) {
        if ($type === 'matrimony_application') return weddingMarriageApplicationPdf($data);
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

/**
 * Maps data saved by the former Matrimony form into the current field names.
 * The old groom_mother/bride_mother fields explicitly meant "maiden name", so
 * preserve them there rather than silently presenting them as the mother's
 * current/full name.
 */
function weddingMarriageNormalizeData(array $data): array
{
    if (!array_key_exists('groom_mother_maiden_name', $data) && array_key_exists('groom_mother', $data)) {
        $data['groom_mother_maiden_name'] = $data['groom_mother'];
        $data['groom_mother'] = '';
    }
    if (!array_key_exists('bride_mother_maiden_name', $data) && array_key_exists('bride_mother', $data)) {
        $data['bride_mother_maiden_name'] = $data['bride_mother'];
        $data['bride_mother'] = '';
    }
    return $data;
}

function weddingMarriageAge(string $birthDate, string $referenceDate): ?int
{
    $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
    $reference = DateTimeImmutable::createFromFormat('!Y-m-d', $referenceDate);
    if (!$birth || !$reference
        || $birth->format('Y-m-d') !== $birthDate
        || $reference->format('Y-m-d') !== $referenceDate
        || $birth > $reference) {
        return null;
    }
    return $birth->diff($reference)->y;
}

function weddingMarriagePdfDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('F j, Y') : $date;
}

function weddingMarriagePdfTime(string $time): string
{
    $parsed = DateTimeImmutable::createFromFormat('!H:i', $time);
    return $parsed && $parsed->format('H:i') === $time ? $parsed->format('g:i A') : $time;
}

/** @return string[] */
function weddingMarriageValidationErrors(array $data, bool $requireComplete = true): array
{
    $fieldLabels = [
        'date_applied' => 'Date Applied',
        'groom_name' => 'Groom Full Name', 'groom_birth_date' => 'Groom Date of Birth',
        'groom_father' => "Groom's Father", 'groom_mother' => "Groom's Mother",
        'groom_mother_maiden_name' => "Groom's Mother's Maiden Name", 'groom_address' => 'Groom Address', 'groom_cell' => 'Groom Cell Number',
        'bride_name' => 'Bride Full Name', 'bride_birth_date' => 'Bride Date of Birth',
        'bride_father' => "Bride's Father", 'bride_mother' => "Bride's Mother",
        'bride_mother_maiden_name' => "Bride's Mother's Maiden Name", 'bride_address' => 'Bride Address', 'bride_cell' => 'Bride Cell Number',
        'wedding_date' => 'Date of Wedding', 'wedding_time' => 'Time of Wedding',
    ];
    $errors = [];
    if ($requireComplete) {
        foreach (weddingFormRequiredFields('matrimony_application') as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') {
                $errors[] = $fieldLabels[$key] . ' is required.';
            }
        }
    }

    $limits = [
        'groom_name' => 150, 'groom_father' => 150, 'groom_mother' => 150,
        'groom_mother_maiden_name' => 150, 'groom_address' => 255, 'groom_cell' => 30,
        'bride_name' => 150, 'bride_father' => 150, 'bride_mother' => 150,
        'bride_mother_maiden_name' => 150, 'bride_address' => 255, 'bride_cell' => 30,
    ];
    foreach ($limits as $key => $limit) {
        if (mb_strlen((string) ($data[$key] ?? '')) > $limit) {
            $errors[] = $fieldLabels[$key] . " must not exceed {$limit} characters.";
        }
    }

    foreach (['date_applied', 'groom_birth_date', 'bride_birth_date', 'wedding_date'] as $key) {
        $value = (string) ($data[$key] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($value !== '' && (!$parsed || $parsed->format('Y-m-d') !== $value)) {
            $errors[] = $fieldLabels[$key] . ' must be a valid date.';
        }
    }
    $weddingDate = (string) ($data['wedding_date'] ?? '');
    foreach (['groom_birth_date', 'bride_birth_date'] as $key) {
        $birthDate = (string) ($data[$key] ?? '');
        $age = weddingMarriageAge($birthDate, $weddingDate);
        if ($birthDate !== '' && $weddingDate !== '' && ($age === null || $age > 120)) {
            $errors[] = $fieldLabels[$key] . ' must be before the wedding date.';
        }
    }
    if (($data['wedding_time'] ?? '') !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $data['wedding_time'])) {
        $errors[] = 'Time of Wedding must be a valid time.';
    }
    foreach (['groom_cell', 'bride_cell'] as $key) {
        $value = (string) ($data[$key] ?? '');
        if ($value !== '' && !preg_match('/^[0-9+().\- ]{7,30}$/', $value)) {
            $errors[] = ($key === 'groom_cell' ? "Groom's" : "Bride's") . ' cell number contains invalid characters.';
        }
    }
    return array_values(array_unique($errors));
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

function weddingMarriagePdfValue(FPDF $pdf, string $label, string $value, float $x, float $y, float $width, float $labelWidth): void
{
    $pdf->SetFont('Times', '', 9);
    $pdf->SetXY($x, $y);
    $pdf->Cell($labelWidth, 6, weddingPdfText($label), 0, 0);
    $value = weddingPdfText(trim($value));
    $availableWidth = $width - $labelWidth - 2;
    for ($fontSize = 9.0; $fontSize >= 6.0; $fontSize -= 0.5) {
        $pdf->SetFont('Times', 'B', $fontSize);
        if ($pdf->GetStringWidth($value) <= $availableWidth) break;
    }
    if ($pdf->GetStringWidth($value) > $availableWidth) {
        while ($value !== '' && $pdf->GetStringWidth($value . '...') > $availableWidth) $value = substr($value, 0, -1);
        $value = rtrim($value) . '...';
    }
    $pdf->Cell($width - $labelWidth, 6, $value, 'B', 0);
}

function weddingMarriageOfficeLine(FPDF $pdf, string $label, float $x, float $y, float $width): void
{
    $pdf->SetFont('Times', '', 8.5);
    $pdf->SetXY($x, $y);
    $pdf->Cell($width - 22, 5, weddingPdfText($label), 0, 0);
    $pdf->Cell(22, 5, '', 'B', 0);
}

function weddingMarriageApplicationPdf(array $data): string
{
    $data = weddingMarriageNormalizeData($data);
    $value = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $referenceDate = $value('wedding_date') ?: $value('date_applied');
    $groomAge = weddingMarriageAge($value('groom_birth_date'), $referenceDate);
    $brideAge = weddingMarriageAge($value('bride_birth_date'), $referenceDate);

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(14, 12, 14);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage('P', 'A4');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);

    $pdf->SetFont('Times', 'B', 15);
    $pdf->SetXY(14, 14);
    $pdf->Cell(182, 7, 'MT. CARMEL PARISH', 0, 1, 'C');
    $pdf->SetFont('Times', '', 11);
    $pdf->SetX(14);
    $pdf->Cell(182, 6, 'Balilihan, Bohol', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 13);
    $pdf->SetX(14);
    $pdf->Cell(182, 8, 'MARRIAGE REQUIREMENTS & APPLICATION FORM', 0, 1, 'C');
    $pdf->SetLineWidth(0.5);
    $pdf->Line(20, 38, 190, 38);

    weddingMarriagePdfValue($pdf, 'Date Applied:', weddingMarriagePdfDate($value('date_applied')), 116, 41, 74, 25);

    $leftX = 18.0;
    $rightX = 108.0;
    $columnWidth = 84.0;
    $pdf->SetFillColor(235, 235, 235);
    $pdf->SetFont('Times', 'B', 11);
    $pdf->SetXY($leftX, 52);
    $pdf->Cell($columnWidth, 7, 'MALE / GROOM', 1, 0, 'C', true);
    $pdf->SetXY($rightX, 52);
    $pdf->Cell($columnWidth, 7, 'FEMALE / BRIDE', 1, 0, 'C', true);

    $rows = [
        ['Name:', 'groom_name', 'bride_name', 18.0],
        ['Age:', null, null, 11.0],
        ['Date of Birth:', 'groom_birth_date', 'bride_birth_date', 18.0],
        ['Father:', 'groom_father', 'bride_father', 16.0],
        ['Mother:', 'groom_mother', 'bride_mother', 16.0],
        ["Mother's Maiden Name:", 'groom_mother_maiden_name', 'bride_mother_maiden_name', 34.0],
        ['Address:', 'groom_address', 'bride_address', 18.0],
        ['Cell No.:', 'groom_cell', 'bride_cell', 18.0],
    ];
    $y = 63.0;
    foreach ($rows as [$label, $groomKey, $brideKey, $labelWidth]) {
        $groomValue = $groomKey ? $value($groomKey) : ($groomAge === null ? '' : (string) $groomAge);
        $brideValue = $brideKey ? $value($brideKey) : ($brideAge === null ? '' : (string) $brideAge);
        if ($groomKey === 'groom_birth_date') $groomValue = weddingMarriagePdfDate($groomValue);
        if ($brideKey === 'bride_birth_date') $brideValue = weddingMarriagePdfDate($brideValue);
        weddingMarriagePdfValue($pdf, $label, $groomValue, $leftX, $y, $columnWidth, $labelWidth);
        weddingMarriagePdfValue($pdf, $label, $brideValue, $rightX, $y, $columnWidth, $labelWidth);
        $y += 9;
    }

    $pdf->SetFont('Times', 'B', 11);
    $pdf->SetXY(18, 140);
    $pdf->Cell(174, 7, 'MARRIAGE REQUIREMENTS / OFFICE CHECKLIST', 1, 1, 'C', true);
    $leftChecklist = [
        'Pre-Nuptial Canonical Interview',
        'Clearance / Katin-awan sa Cluster',
        "Groom's Baptismal Certificate",
        "Bride's Baptismal Certificate",
        "Groom's Confirmation Certificate",
        "Bride's Confirmation Certificate",
        'Marriage License',
    ];
    $rightChecklist = [
        'Pre-Cana Seminar Certificate',
        'Proof of Banns',
        'Choir',
        'FLA Coordinator',
        'Sponsors',
        'Special Fee',
    ];
    $y = 151.0;
    foreach ($leftChecklist as $index => $label) {
        weddingMarriageOfficeLine($pdf, $label, 18, $y, 84);
        if (isset($rightChecklist[$index])) {
            weddingMarriageOfficeLine($pdf, $rightChecklist[$index], 108, $y, 84);
        }
        $y += 8;
    }

    $pdf->SetLineWidth(0.4);
    $pdf->Rect(18, 145, 174, 66);
    $pdf->Line(105, 145, 105, 211);

    weddingMarriagePdfValue($pdf, 'Date of Wedding:', weddingMarriagePdfDate($value('wedding_date')), 18, 220, 86, 32);
    weddingMarriagePdfValue($pdf, 'Time of Wedding:', weddingMarriagePdfTime($value('wedding_time')), 108, 220, 84, 33);
    weddingMarriagePdfValue($pdf, 'TOTAL:', '', 108, 233, 84, 18);

    $pdf->SetFont('Times', 'I', 8);
    $pdf->SetXY(18, 247);
    $pdf->MultiCell(174, 4, 'For parish office use: checklist completion, special fee, and total remain blank until verified by authorized parish personnel.', 0, 'L');
    $pdf->SetFont('Times', '', 9);
    $pdf->SetXY(18, 266);
    $pdf->Cell(78, 5, 'Prepared by: ______________________________', 0, 0);
    $pdf->SetXY(114, 266);
    $pdf->Cell(78, 5, 'Verified by: ______________________________', 0, 0);

    return $pdf->Output('S');
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
