<?php
require_once __DIR__ . '/document-storage.php';
require_once __DIR__ . '/validation.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (is_file(__DIR__ . '/../fpdf.php')) {
    require_once __DIR__ . '/../fpdf.php';
}
// Suppress utf8_encode deprecation warnings from FPDF (PHP 8.2+) which break output headers
error_reporting(E_ALL & ~E_DEPRECATED);

const FUNERAL_FORM_TYPES = ['katin_awan_paglubong'];

function funeralFormDefinition(string $type): array
{
    return ['title' => 'Katin-awan sa Paglubong', 'file_prefix' => 'Funeral_KatinAwan'];
}

function processFuneralGeneratedForm(int $appointmentId, string $type, array $data, ?int $existingDocumentId = null): void
{
    if (!in_array($type, FUNERAL_FORM_TYPES, true)) throw new RuntimeException('Invalid funeral form type.');
    $data = funeralKatinAwanNormalizeData($data);
    $errors = funeralKatinAwanValidationErrors($data);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));

    $pdo = db();
    $stmt = $pdo->prepare('SELECT appointment_id FROM appointments WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Appointment not found.');

    $definition = funeralFormDefinition($type);
    $fileName = $definition['file_prefix'] . '_' . $appointmentId . '_' . time() . '.pdf';
    $stored = null;
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        if ($existingDocumentId) {
            $reviewQuery = $pdo->prepare('SELECT review_status FROM uploaded_documents WHERE document_id = ? AND appointment_id = ? FOR UPDATE');
            $reviewQuery->execute([$existingDocumentId, $appointmentId]);
            if ($reviewQuery->fetchColumn() === 'approved') throw new RuntimeException('Approved forms require Secretary review before they can be changed.');
        }
        $previousDocumentQuery = $pdo->prepare("SELECT document_id FROM uploaded_documents WHERE appointment_id = ? AND document_source = 'generated' AND generated_form_type = ? AND superseded_by IS NULL ORDER BY document_id DESC LIMIT 1 FOR UPDATE");
        $previousDocumentQuery->execute([$appointmentId, $type]);
        $previousDocumentId = (int) ($previousDocumentQuery->fetchColumn() ?: 0);
        $stored = documentStorageWriteBytes(funeralKatinAwanPdf($data));
        $insert = $pdo->prepare("INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type) VALUES (?, ?, ?, 'application/pdf', ?, 'pending', FALSE, 'generated', ?)");
        $insert->execute([$appointmentId, $fileName, $stored['key'], $definition['title'], $type]);
        $documentId = (int) $pdo->lastInsertId();
        if ($previousDocumentId) {
            $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND appointment_id = ? AND superseded_by IS NULL')
                ->execute([$documentId, $previousDocumentId, $appointmentId]);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('SELECT generated_form_id FROM generated_funeral_forms WHERE appointment_id = ? AND form_type = ?');
        $stmt->execute([$appointmentId, $type]);
        if ($stmt->fetchColumn()) {
            $pdo->prepare("UPDATE generated_funeral_forms SET form_data = ?, document_id = ?, status = 'generated', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ? AND form_type = ?")
                ->execute([$json, $documentId, $appointmentId, $type]);
        } else {
            $pdo->prepare("INSERT INTO generated_funeral_forms (appointment_id, form_type, form_data, document_id, status) VALUES (?, ?, ?, ?, 'generated')")
                ->execute([$appointmentId, $type, $json, $documentId]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if ($stored && !empty($stored['key'])) {
            try { documentStorageDelete($stored['key']); } catch (Throwable $ignored) { error_log('Funeral generated-document cleanup failed.'); }
        }
        throw $e;
    }
}

function funeralKatinAwanNormalizeData(array $data): array
{
    $sacrament = trim((string) ($data['katapusan_nga_sakramento'] ?? ''));
    $legacyMarriage = trim((string) ($data['kasal'] ?? ''));
    return [
        'ngalan_sa_ilubong' => trim((string) ($data['ngalan_sa_ilubong'] ?? $data['deceased_name'] ?? '')),
        'edad' => trim((string) ($data['edad'] ?? $data['deceased_age'] ?? '')),
        'pinuy_anan' => trim((string) ($data['pinuy_anan'] ?? $data['place_of_wake'] ?? '')),
        'relihiyon' => trim((string) ($data['relihiyon'] ?? '')),
        'sakop_sa_kapilya' => trim((string) ($data['sakop_sa_kapilya'] ?? '')),
        'ngalan_sa_cluster' => trim((string) ($data['ngalan_sa_cluster'] ?? '')),
        'sakramento_hilog' => !empty($data['sakramento_hilog']) || $sacrament === 'Hilog/Hulog',
        'sakramento_kumpisal' => !empty($data['sakramento_kumpisal']) || $sacrament === 'Kumpisal',
        'sakramento_wala' => !empty($data['sakramento_wala']) || $sacrament === 'Wala',
        'kanus_a_namatay' => trim((string) ($data['kanus_a_namatay'] ?? '')),
        'unsay_namatyan' => trim((string) ($data['unsay_namatyan'] ?? $data['cause_of_death'] ?? '')),
        'kanus_a_ilubong' => trim((string) ($data['kanus_a_ilubong'] ?? $data['burial_date'] ?? '')),
        'oras_sa_lubong' => substr(trim((string) ($data['oras_sa_lubong'] ?? $data['burial_time'] ?? '')), 0, 5),
        'responde' => trim((string) ($data['responde'] ?? $data['informant_name'] ?? '')),
        'ginikanan_anak' => trim((string) ($data['ginikanan_anak'] ?? '')),
        'ginikanan_anak_cell' => trim((string) ($data['ginikanan_anak_cell'] ?? $data['informant_phone'] ?? '')),
        'asawa_bana' => trim((string) ($data['asawa_bana'] ?? $data['spouse_name'] ?? '')),
        'asawa_bana_cell' => trim((string) ($data['asawa_bana_cell'] ?? '')),
        'kasal' => $legacyMarriage !== '' ? $legacyMarriage : (!empty($data['kasal_simbahan']) ? 'Simbahan' : (!empty($data['kasal_sibil']) ? 'Sibil' : (!empty($data['kasal_wala']) ? 'Wala' : ''))),
        'petsa_sa_kasal' => trim((string) ($data['petsa_sa_kasal'] ?? '')),
        'diin_kasal' => trim((string) ($data['diin_kasal'] ?? '')),
    ];
}

/** @return string[] */
function funeralKatinAwanValidationErrors(array $data, bool $requireComplete = true): array
{
    $data = funeralKatinAwanNormalizeData($data);
    $errors = [];
    $required = [
        'ngalan_sa_ilubong' => 'Ngalan sa Ilubong', 'edad' => 'Edad', 'pinuy_anan' => 'Pinuy-anan',
        'kanus_a_namatay' => 'Kanus-a Namatay', 'unsay_namatyan' => 'Unsay Namatyan',
        'kanus_a_ilubong' => 'Kanus-a Ilubong', 'oras_sa_lubong' => 'Oras', 'responde' => 'Responde', 'kasal' => 'Unsang Kasala ang Nadawat',
    ];
    if ($requireComplete) {
        foreach ($required as $key => $label) if ($data[$key] === '') $errors[] = $label . ' is required.';
    }

    if ($data['ngalan_sa_ilubong'] !== '' && validateName($data['ngalan_sa_ilubong']) === false) $errors[] = 'Name of the deceased must contain letters and may only use common name punctuation.';
    if ($data['edad'] !== '' && validateAge($data['edad']) === false) $errors[] = 'Enter a valid whole-number age from 0 to 120.';
    if ($data['pinuy_anan'] !== '' && validateAddress($data['pinuy_anan']) === false) $errors[] = 'Home address contains invalid characters.';
    foreach (['kanus_a_namatay' => 'Select a valid date of death.', 'kanus_a_ilubong' => 'Select a valid burial date.'] as $key => $message) {
        if ($data[$key] !== '' && validateCalendarDate($data[$key]) === false) $errors[] = $message;
    }
    if ($data['oras_sa_lubong'] !== '' && validateTimeValue($data['oras_sa_lubong']) === false) $errors[] = 'Select a valid burial time.';
    if (validateCalendarDate($data['kanus_a_namatay']) !== false && validateCalendarDate($data['kanus_a_ilubong']) !== false && $data['kanus_a_ilubong'] < $data['kanus_a_namatay']) $errors[] = 'Burial date cannot be earlier than the date of death.';
    foreach (['responde' => 'Respondent name', 'ginikanan_anak' => 'Parent/Child name', 'asawa_bana' => 'Spouse name'] as $key => $labelName) {
        if ($data[$key] !== '' && validateName($data[$key]) === false) $errors[] = $labelName . ' must contain letters and may only use common name punctuation.';
    }
    foreach (['ginikanan_anak_cell', 'asawa_bana_cell'] as $key) {
        if ($data[$key] !== '' && validatePhilippineMobile($data[$key]) === false) $errors[] = 'Enter a valid 11-digit mobile number starting with 09.';
    }

    $sacramentCount = (int) $data['sakramento_hilog'] + (int) $data['sakramento_kumpisal'] + (int) $data['sakramento_wala'];
    if ($sacramentCount === 0) $errors[] = 'Select the sacrament received, or select Wala.';
    if ($data['sakramento_wala'] && $sacramentCount > 1) $errors[] = 'Wala cannot be selected with Hilog or Kumpisal.';
    if ($data['kasal'] !== '' && validateEnum($data['kasal'], ['Simbahan', 'Sibil', 'Wala']) === false) $errors[] = 'Select one valid marriage status.';
    if (in_array($data['kasal'], ['Simbahan', 'Sibil'], true)) {
        if ($data['petsa_sa_kasal'] === '') $errors[] = 'Petsa sa Kasal is required when married.';
        elseif (validateCalendarDate($data['petsa_sa_kasal']) === false) $errors[] = 'Select a valid marriage date.';
        if ($data['diin_kasal'] === '') $errors[] = 'Diin is required when married.';
    } elseif ($data['petsa_sa_kasal'] !== '' && validateCalendarDate($data['petsa_sa_kasal']) === false) {
        $errors[] = 'Select a valid marriage date.';
    }
    if ($data['diin_kasal'] !== '' && validateAddress($data['diin_kasal']) === false) $errors[] = 'Diin contains invalid characters.';
    foreach (['ngalan_sa_ilubong'=>150,'edad'=>3,'pinuy_anan'=>255,'relihiyon'=>80,'sakop_sa_kapilya'=>150,'ngalan_sa_cluster'=>150,'unsay_namatyan'=>255,'responde'=>150,'ginikanan_anak'=>150,'ginikanan_anak_cell'=>11,'asawa_bana'=>150,'asawa_bana_cell'=>11,'diin_kasal'=>150] as $key => $limit) {
        if (mb_strlen((string) ($data[$key] ?? '')) > $limit) $errors[] = ucwords(str_replace('_', ' ', $key)) . " must not exceed {$limit} characters.";
    }
    return array_values(array_unique($errors));
}

function funeralPdfText(string $value): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $converted === false ? '?' : $converted;
}

function funeralPdfDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date ? $parsed->format('F j, Y') : $date;
}

function funeralPdfTime(string $time): string
{
    $parsed = DateTimeImmutable::createFromFormat('!H:i', $time);
    return $parsed && $parsed->format('H:i') === $time ? $parsed->format('g:i A') : $time;
}

function funeralPdfField(FPDF $pdf, string $label, string $value, float $x, float $y, float $width, float $labelWidth, float $size = 9): void
{
    $pdf->SetFont('Times', 'B', $size); $pdf->SetXY($x, $y); $pdf->Cell($labelWidth, 6, funeralPdfText($label), 0, 0);
    $value = funeralPdfText(trim($value));
    for ($fontSize = $size; $fontSize >= 6.5; $fontSize -= 0.5) {
        $pdf->SetFont('Times', '', $fontSize);
        if ($pdf->GetStringWidth($value) <= $width - $labelWidth - 2) break;
    }
    while ($value !== '' && $pdf->GetStringWidth($value . '...') > $width - $labelWidth - 2) $value = substr($value, 0, -1);
    $pdf->Cell($width - $labelWidth, 6, $value, 'B', 0);
}

function funeralPdfChoice(FPDF $pdf, string $label, bool $selected, float $x, float $y, float $width = 43): void
{
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY($x, $y); $pdf->Cell(24, 5, funeralPdfText($label . ':'), 0, 0);
    $pdf->Cell($width - 24, 5, $selected ? 'X' : '', 'B', 0, 'C');
}

function funeralKatinAwanPdf(array $data): string
{
    if (!class_exists('FPDF')) throw new RuntimeException('FPDF is required to generate the Funeral form.');
    $data = funeralKatinAwanNormalizeData($data);
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $pdf = new FPDF('P', 'mm', 'A4');
    $deceased = $v('ngalan_sa_ilubong');
    $metaTitle = 'Katin-awan sa Paglubong' . ($deceased ? ' - ' . $deceased : '');
    $pdf->SetTitle($metaTitle, true);
    $pdf->SetMargins(15, 10, 15); $pdf->SetAutoPageBreak(false); $pdf->AddPage('P', 'A4');
    $logo = dirname(__DIR__) . '/public/img/logo.png';
    if (is_file($logo)) $pdf->Image($logo, 18, 10, 28, 28, 'PNG');
    $pdf->SetTextColor(20, 92, 18); $pdf->SetFont('Times', 'B', 18); $pdf->SetXY(48, 14); $pdf->Cell(145, 8, 'Our Lady of Mt. Carmel Parish', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 10); $pdf->SetX(48); $pdf->Cell(145, 5, '6342 BALILIHAN, BOHOL PHILIPPINES', 0, 1, 'C');
    $pdf->SetFont('Times', '', 9); $pdf->SetX(48); $pdf->Cell(145, 5, 'Email address: mountcarmelbalilihan@gmail.com', 0, 1, 'C');
    $pdf->SetDrawColor(20, 92, 18); $pdf->SetLineWidth(0.8); $pdf->Line(15, 42, 195, 42);
    $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(0, 0, 0); $pdf->SetFont('Times', 'B', 15); $pdf->SetXY(15, 48); $pdf->Cell(180, 8, 'KATIN-AWAN SA PAGLUBONG', 0, 1, 'C');

    funeralPdfField($pdf, 'NGALAN SA ILUBONG:', $v('ngalan_sa_ilubong'), 18, 64, 132, 47);
    funeralPdfField($pdf, 'EDAD:', $v('edad'), 153, 64, 39, 16);
    funeralPdfField($pdf, 'PINUY-ANAN:', $v('pinuy_anan'), 18, 74, 111, 31);
    funeralPdfField($pdf, 'RELIHIYON:', $v('relihiyon'), 132, 74, 60, 27);
    funeralPdfField($pdf, 'SAKOP SA KAPILYA SA:', $v('sakop_sa_kapilya'), 18, 84, 174, 49);
    funeralPdfField($pdf, 'NGALAN SA CLUSTER:', $v('ngalan_sa_cluster'), 18, 94, 174, 43);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 104); $pdf->Cell(174, 5, 'UNSANG SAKRAMENTOHA ANG NADAWAT?', 0, 1);
    funeralPdfChoice($pdf, 'HILOG', (bool) $data['sakramento_hilog'], 18, 111, 48);
    funeralPdfChoice($pdf, 'KUMPISAL', (bool) $data['sakramento_kumpisal'], 70, 111, 55);
    funeralPdfChoice($pdf, 'WALA', (bool) $data['sakramento_wala'], 130, 111, 62);
    funeralPdfField($pdf, 'KANUS-A NAMATAY:', funeralPdfDate($v('kanus_a_namatay')), 18, 121, 78, 40);
    funeralPdfField($pdf, 'UNSAY NAMATYAN:', $v('unsay_namatyan'), 99, 121, 93, 40);
    funeralPdfField($pdf, 'KANUS-A ILUBONG:', funeralPdfDate($v('kanus_a_ilubong')), 18, 131, 130, 41);
    funeralPdfField($pdf, 'ORAS:', funeralPdfTime($v('oras_sa_lubong')), 151, 131, 41, 15);
    funeralPdfField($pdf, 'RESPONDE:', $v('responde'), 18, 141, 174, 27);
    funeralPdfField($pdf, 'GINIKANAN/ANAK:', $v('ginikanan_anak'), 18, 151, 122, 40);
    funeralPdfField($pdf, 'Cell.#', $v('ginikanan_anak_cell'), 143, 151, 49, 15);
    funeralPdfField($pdf, 'ASAWA/BANA:', $v('asawa_bana'), 18, 161, 122, 32);
    funeralPdfField($pdf, 'Cell.#', $v('asawa_bana_cell'), 143, 161, 49, 15);
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(18, 171); $pdf->Cell(174, 5, 'UNSANG KASALA ANG NADAWAT?', 0, 1);
    funeralPdfChoice($pdf, 'SIMBAHAN', $v('kasal') === 'Simbahan', 18, 178, 55);
    funeralPdfChoice($pdf, 'SIBIL', $v('kasal') === 'Sibil', 78, 178, 50);
    funeralPdfChoice($pdf, 'WALA', $v('kasal') === 'Wala', 133, 178, 59);
    funeralPdfField($pdf, 'PETSA SA KASAL:', funeralPdfDate($v('petsa_sa_kasal')), 18, 188, 82, 37);
    funeralPdfField($pdf, 'DIIN:', $v('diin_kasal'), 103, 188, 89, 16);

    $pdf->SetFont('Times', 'B', 7.8); $pdf->SetXY(18, 199);
    $pdf->MultiCell(174, 4, funeralPdfText('PAHINUMDOM: Human mamatud-i kining tanan, kini paga-pirmahan sa Cluster Leader, Cluster Treasurer, Chapel Chairman, Chapel Treasurer ug Cemetery Commission Chairman ug dad-on sa mga hingtungdan ngadto sa simbahan ug ihatag sa Parish Clerk. Isukip usab dinhi ang photo copy sa death certificate.'));
    $pdf->SetFont('Times', '', 8);
    foreach ([[218, 'Cluster Leader', 'Cluster Treasurer', 'Chapel Treasurer'], [235, "FULGENCIO OÑES\nFederated Dajong Tres.", 'Dajong President', 'Chapel Chairman']] as [$y, $left, $middle, $right]) {
        foreach ([[18, $left], [77, $middle], [136, $right]] as [$x, $label]) {
            $pdf->SetXY($x, $y); $pdf->Cell(52, 5, '________________________', 0, 1, 'C');
            $pdf->SetXY($x, $y + 5); $pdf->MultiCell(52, 4, funeralPdfText($label), 0, 'C');
        }
    }
    $pdf->SetXY(18, 255); $pdf->Cell(52, 5, funeralPdfText('WILLIE PALAÑA'), 0, 1, 'C'); $pdf->SetXY(18, 260); $pdf->Cell(52, 4, 'Cemetery Porter', 0, 1, 'C');
    $pdf->SetFont('Times', 'B', 9); $pdf->SetXY(105, 255); $pdf->Cell(78, 5, funeralPdfText('REV. FR. AL JOHN A. MIÑOZA'), 0, 1, 'C');
    $pdf->SetFont('Times', '', 8); $pdf->SetXY(105, 260); $pdf->Cell(78, 4, 'Parish Priest', 0, 1, 'C');
    return $pdf->Output('S');
}
