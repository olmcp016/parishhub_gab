<?php
/**
 * Official Parish Certificate generator — stamps data onto the 4 scanned
 * parish certificate templates (public/img/certificates/) with FPDF.
 *
 * Coordinates below are fractions (0–1) of the template image's own width/
 * height, read off each scanned form by eye. They land the printed text just
 * above each blank line but, being eyeballed rather than measured pixel-for-
 * pixel, may need a small manual nudge after the first printed sample.
 */
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (is_file(__DIR__ . '/../fpdf.php')) {
    require_once __DIR__ . '/../fpdf.php';
}
require_once __DIR__ . '/validation.php';

const CERTIFICATE_TYPES = ['Baptism', 'Marriage', 'Confirmation', 'Death'];

/** Pixel dimensions of each scanned template (from getimagesize()), and its file name. */
const CERTIFICATE_TEMPLATES = [
    'Baptism'      => ['file' => 'Certificate of Baptism.jpg', 'w' => 1370, 'h' => 1860],
    'Marriage'     => ['file' => 'Certificate of Marriage.jpg', 'w' => 1444, 'h' => 1912],
    'Confirmation' => ['file' => 'Certification of Confirmation.jpg', 'w' => 1245, 'h' => 1937],
    'Death'        => ['file' => 'Cetficate of Death.jpg', 'w' => 1302, 'h' => 2048],
];

function certificateTemplatePath(string $type): string
{
    if (!isset(CERTIFICATE_TEMPLATES[$type])) throw new InvalidArgumentException('Unknown certificate type.');
    return dirname(__DIR__) . '/public/img/certificates/' . CERTIFICATE_TEMPLATES[$type]['file'];
}

/**
 * The fields the Secretary types/reviews for each certificate type, in display
 * order. Canonical registry fields (Book/Page/Line/Serial No., registry date,
 * purpose) are stored as their own issued_certificates columns, not here.
 */
function certificateFormDefinition(string $type): array
{
    return match ($type) {
        'Baptism' => ['title' => 'Certificate of Baptism', 'fields' => [
            'parish_of' => 'Parish Of',
            'child_name' => "Child's Full Name",
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'birth_date' => 'Date of Birth',
            'birth_place' => 'Place of Birth',
            'baptism_date' => 'Date of Baptism',
            'baptism_place' => 'Place of Baptism',
            'godparent_1' => 'Godparent 1',
            'godparent_2' => 'Godparent 2',
            'officiating_priest' => 'Officiating Priest',
            'parish_priest' => 'Parish Priest',
        ]],
        'Marriage' => ['title' => 'Certificate of Marriage', 'fields' => [
            'church_of' => 'Church Of',
            'place' => 'Place',
            'province' => 'Province',
            'groom_name' => 'Groom Full Name',
            'bride_name' => 'Bride Full Name',
            'groom_age' => 'Groom Age',
            'bride_age' => 'Bride Age',
            'groom_nationality' => 'Groom Nationality',
            'bride_nationality' => 'Bride Nationality',
            'groom_residence' => 'Groom Residence',
            'bride_residence' => 'Bride Residence',
            'groom_father' => "Groom's Father",
            'bride_father' => "Bride's Father",
            'groom_mother' => "Groom's Mother",
            'bride_mother' => "Bride's Mother",
            'marriage_date' => 'Date of Marriage',
            'officiating_priest' => 'Officiating Priest (Rev.)',
            'witness_name' => 'Principal Sponsor / Witness',
            'witness_residence' => 'Witness Residence',
            'marriage_license_no' => 'Marriage License No.',
            'license_issued_at' => 'License Issued At',
            'license_issued_date' => 'License Issued Date',
            'certificate_dated' => 'Dated',
        ]],
        'Confirmation' => ['title' => 'Certificate of Confirmation', 'fields' => [
            'parish_of' => 'Parish Of',
            'confirmed_name' => 'Confirmed Person',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'confirmation_date' => 'Date of Confirmation',
            'officiating_bishop' => 'Officiating Bishop/Priest (Most Rev. Msgr.)',
            'sponsor_1' => 'Sponsor 1',
            'sponsor_2' => 'Sponsor 2',
            'given_date' => 'Given at the Parish Rectory (Date)',
        ]],
        'Death' => ['title' => 'Certificate of Death', 'fields' => [
            'deceased_name' => 'Full Name of the Deceased',
            'barangay' => 'Resident of Barangay',
            'municipality' => 'Municipality',
            'province' => 'Province',
            'civil_status' => 'Civil Status',
            'relation_names' => 'Parents (if single) / Spouse (if married)',
            'death_date' => 'Date of Death',
            'death_age' => 'Age at Death',
            'burial_type' => 'Burial',
            'municipal_cemetery_name' => 'Municipal Cemetery Name (if not parish cemetery)',
            'burial_date' => 'Date of Burial',
            'cause_of_death' => 'Cause of Death',
            'sacraments_received' => 'Sacraments Received Before Death',
            'parish_name' => 'Parish (seal)',
            'certificate_dated' => 'Dated',
        ]],
    };
}

function certificateRequiredFields(string $type): array
{
    return match ($type) {
        'Baptism' => ['parish_of', 'child_name', 'father_name', 'mother_name', 'birth_date', 'baptism_date', 'officiating_priest'],
        'Marriage' => ['church_of', 'groom_name', 'bride_name', 'marriage_date', 'officiating_priest'],
        'Confirmation' => ['parish_of', 'confirmed_name', 'confirmation_date', 'officiating_bishop'],
        'Death' => ['deceased_name', 'death_date', 'cause_of_death'],
        default => [],
    };
}

/** @return string[] */
function certificateValidationErrors(string $type, array $data, bool $requireComplete = true): array
{
    $definition = certificateFormDefinition($type);
    $errors = [];
    if ($requireComplete) {
        foreach (certificateRequiredFields($type) as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') $errors[] = $definition['fields'][$key] . ' is required.';
        }
    }
    foreach (['birth_date', 'baptism_date', 'marriage_date', 'confirmation_date', 'death_date', 'burial_date', 'given_date', 'license_issued_date', 'certificate_dated'] as $key) {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value !== '' && validateCalendarDate($value) === false) $errors[] = $definition['fields'][$key] . ' must be a valid date.';
    }
    return array_values(array_unique($errors));
}

function certificatePdfText(string $value): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $converted === false ? '?' : $converted;
}

/** Long-form date as printed on a certificate, e.g. "24th day of October, 2026". */
function certificatePdfLongDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) return $date === '' ? '' : $date;
    $day = (int) $parsed->format('j');
    $suffix = (in_array($day % 100, [11, 12, 13], true)) ? 'th' : (['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th');
    return $day . $suffix . ' day of ' . $parsed->format('F') . ', ' . $parsed->format('Y');
}

/** @return array{day:string, month:string, year:string} */
function certificatePdfDateParts(string $date): array
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) return ['day' => '', 'month' => '', 'year' => ''];
    return ['day' => $parsed->format('j'), 'month' => $parsed->format('F'), 'year' => $parsed->format('y')];
}

/**
 * Prints $value just above a pre-printed line on the template, shrinking the
 * font until it fits $maxWidth (same fit-to-width approach as the other
 * generated-form PDFs in this codebase).
 */
function certificateDrawField(FPDF $pdf, string $value, float $x, float $y, float $maxWidth, float $fontSize = 12, string $align = 'C', string $style = '', float $lineHeight = 5.2): void
{
    $value = certificatePdfText(trim($value));
    if ($value === '') return;
    $pdf->SetFont('Times', $style, $fontSize);
    while ($fontSize > 6.5 && $pdf->GetStringWidth($value) > $maxWidth) {
        $fontSize -= 0.5;
        $pdf->SetFont('Times', $style, $fontSize);
    }
    // (x, y) is the pre-printed blank LINE's own position; Cell() draws
    // downward from its top, so shift up by its height to sit the text just
    // above that line instead of straddling/crossing it. $lineHeight should
    // be reduced below the 5.2mm default on a template where rows of blanks
    // are packed closer together than that, so adjacent rows don't bleed
    // into each other.
    $pdf->SetXY($x, $y - $lineHeight);
    $pdf->Cell($maxWidth, $lineHeight, $value, 0, 0, $align);
}

/**
 * Stamps the full-bleed template image onto an A4 page, scaled to fit (letter-
 * boxed, not cropped). Returns a closure mapping a template's own 0–1
 * fractional (fx, fy) coordinate to an absolute mm (x, y) on the page, so
 * every per-type renderer below can place text using the same fractions it
 * was eyeballed in (relative to the template image, not the PDF page).
 */
function certificateStampTemplate(FPDF $pdf, string $type): callable
{
    $template = CERTIFICATE_TEMPLATES[$type];
    $pageW = 210.0;
    $pageH = 297.0;
    $scale = min($pageW / $template['w'], $pageH / $template['h']);
    $drawW = $template['w'] * $scale;
    $drawH = $template['h'] * $scale;
    $offsetX = ($pageW - $drawW) / 2;
    $offsetY = ($pageH - $drawH) / 2;

    $pdf->AddPage('P', 'A4');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->Image(certificateTemplatePath($type), $offsetX, $offsetY, $drawW, $drawH, 'JPG');

    return static fn(float $fx, float $fy): array => [$offsetX + $fx * $drawW, $offsetY + $fy * $drawH];
}

function certificateBaptismPdf(array $data): string
{
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(certificatePdfText('Certificate of Baptism - ' . $v('child_name')), true);
    $at = certificateStampTemplate($pdf, 'Baptism');

    [$x, $y] = $at(0.315, 0.198); certificateDrawField($pdf, $v('parish_of'), $x, $y, 48, 13, 'L');
    [$x, $y] = $at(0.10, 0.39); certificateDrawField($pdf, $v('child_name'), $x, $y, 73, 14, 'C', 'B');
    [$x, $y] = $at(0.104, 0.4365); certificateDrawField($pdf, $v('father_name'), $x, $y, 37, 12);
    [$x, $y] = $at(0.544, 0.4365); certificateDrawField($pdf, $v('mother_name'), $x, $y, 35, 12);
    [$x, $y] = $at(0.104, 0.4965); certificateDrawField($pdf, certificatePdfText($v('birth_date')), $x, $y, 37, 11);
    [$x, $y] = $at(0.544, 0.4965); certificateDrawField($pdf, $v('birth_place'), $x, $y, 35, 11);
    [$x, $y] = $at(0.104, 0.5585); certificateDrawField($pdf, certificatePdfText($v('baptism_date')), $x, $y, 37, 11);
    [$x, $y] = $at(0.544, 0.5585); certificateDrawField($pdf, $v('baptism_place'), $x, $y, 35, 11);
    [$x, $y] = $at(0.104, 0.649); certificateDrawField($pdf, $v('godparent_1'), $x, $y, 35, 11);
    [$x, $y] = $at(0.544, 0.649); certificateDrawField($pdf, $v('godparent_2'), $x, $y, 35, 11);
    [$x, $y] = $at(0.285, 0.769); certificateDrawField($pdf, $v('officiating_priest'), $x, $y, 37, 11);
    [$x, $y] = $at(0.285, 0.845); certificateDrawField($pdf, $v('parish_priest'), $x, $y, 37, 11);
    [$x, $y] = $at(0.172, 0.884); certificateDrawField($pdf, $v('book_no'), $x, $y, 18, 9, 'L');
    [$x, $y] = $at(0.172, 0.898); certificateDrawField($pdf, $v('page_no'), $x, $y, 18, 9, 'L');
    [$x, $y] = $at(0.178, 0.9115); certificateDrawField($pdf, $v('line_no'), $x, $y, 18, 9, 'L');

    return $pdf->Output('S');
}

function certificateMarriagePdf(array $data): string
{
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(certificatePdfText('Certificate of Marriage - ' . $v('groom_name') . ' and ' . $v('bride_name')), true);
    $at = certificateStampTemplate($pdf, 'Marriage');

    [$x, $y] = $at(0.291, 0.1663); certificateDrawField($pdf, $v('church_of'), $x, $y, 98, 12, 'L');
    [$x, $y] = $at(0.111, 0.1805); certificateDrawField($pdf, $v('place'), $x, $y, 39, 11, 'L');
    [$x, $y] = $at(0.554, 0.1805); certificateDrawField($pdf, $v('province'), $x, $y, 41, 11, 'L');

    [$x, $y] = $at(0.069, 0.264); certificateDrawField($pdf, $v('groom_name'), $x, $y, 92, 12, 'L', 'B');
    [$x, $y] = $at(0.582, 0.264); certificateDrawField($pdf, $v('bride_name'), $x, $y, 63, 12, 'L', 'B');
    [$x, $y] = $at(0.151, 0.2866); certificateDrawField($pdf, $v('groom_age'), $x, $y, 84, 10, 'L');
    [$x, $y] = $at(0.582, 0.2866); certificateDrawField($pdf, $v('bride_age'), $x, $y, 63, 10, 'L');
    [$x, $y] = $at(0.237, 0.306); certificateDrawField($pdf, $v('groom_nationality'), $x, $y, 72, 10, 'L');
    [$x, $y] = $at(0.582, 0.306); certificateDrawField($pdf, $v('bride_nationality'), $x, $y, 63, 10, 'L');
    [$x, $y] = $at(0.211, 0.3254); certificateDrawField($pdf, $v('groom_residence'), $x, $y, 76, 10, 'L');
    [$x, $y] = $at(0.582, 0.3254); certificateDrawField($pdf, $v('bride_residence'), $x, $y, 63, 10, 'L');
    [$x, $y] = $at(0.155, 0.3452); certificateDrawField($pdf, $v('groom_father'), $x, $y, 84, 10, 'L');
    [$x, $y] = $at(0.582, 0.3452); certificateDrawField($pdf, $v('bride_father'), $x, $y, 63, 10, 'L');
    [$x, $y] = $at(0.165, 0.365); certificateDrawField($pdf, $v('groom_mother'), $x, $y, 82, 10, 'L');
    [$x, $y] = $at(0.582, 0.365); certificateDrawField($pdf, $v('bride_mother'), $x, $y, 63, 10, 'L');

    // This lower block's rows are packed only ~5mm apart on the template, tighter
    // than the default 5.2mm text height — use a shorter line height and smaller
    // font throughout so consecutive rows don't bleed into each other.
    $lh = 3.8; $fs = 9;
    $dateParts = certificatePdfDateParts($v('marriage_date'));
    [$x, $y] = $at(0.107, 0.5571); certificateDrawField($pdf, $dateParts['day'], $x, $y, 26, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.27, 0.5571); certificateDrawField($pdf, $dateParts['month'], $x, $y, 93, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.755, 0.5571); certificateDrawField($pdf, $dateParts['year'], $x, $y, 13, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.298, 0.5943); certificateDrawField($pdf, $v('officiating_priest'), $x, $y, 114, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.187, 0.6137); certificateDrawField($pdf, $v('witness_name'), $x, $y, 137, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.121, 0.632); certificateDrawField($pdf, $v('witness_residence'), $x, $y, 151, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.166, 0.6717); certificateDrawField($pdf, $v('book_no'), $x, $y, 12, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.277, 0.6717); certificateDrawField($pdf, $v('page_no'), $x, $y, 12, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.391, 0.6717); certificateDrawField($pdf, $v('line_no'), $x, $y, 14, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.402, 0.6903); certificateDrawField($pdf, $v('marriage_license_no'), $x, $y, 14, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.547, 0.6903); certificateDrawField($pdf, $v('license_issued_at'), $x, $y, 62, $fs, 'L', '', $lh);
    $licenseParts = certificatePdfDateParts($v('license_issued_date'));
    [$x, $y] = $at(0.09, 0.7083); certificateDrawField($pdf, trim($licenseParts['month'] . ' ' . $licenseParts['day']), $x, $y, 38, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.319, 0.7083); certificateDrawField($pdf, $licenseParts['year'], $x, $y, 7, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.194, 0.7262); certificateDrawField($pdf, certificatePdfText($v('certificate_dated')), $x, $y, 90, $fs, 'L', '', $lh);

    return $pdf->Output('S');
}

function certificateConfirmationPdf(array $data): string
{
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(certificatePdfText('Certificate of Confirmation - ' . $v('confirmed_name')), true);
    $at = certificateStampTemplate($pdf, 'Confirmation');

    // Rows here are packed only ~7-8mm apart on the template — shorter line
    // height and smaller font so consecutive rows don't bleed into each other.
    $lh = 4.2; $fs = 10;
    [$x, $y] = $at(0.205, 0.2495); certificateDrawField($pdf, $v('parish_of'), $x, $y, 59, 12, 'C', '', $lh);
    [$x, $y] = $at(0.161, 0.39); certificateDrawField($pdf, $v('confirmed_name'), $x, $y, 70, 12, 'L', 'B', $lh);
    [$x, $y] = $at(0.181, 0.4215); certificateDrawField($pdf, $v('father_name'), $x, $y, 68, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.181, 0.4535); certificateDrawField($pdf, $v('mother_name'), $x, $y, 52, $fs, 'L', '', $lh);
    $dateParts = certificatePdfDateParts($v('confirmation_date'));
    [$x, $y] = $at(0.201, 0.488); certificateDrawField($pdf, $dateParts['day'], $x, $y, 8, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.317, 0.488); certificateDrawField($pdf, $dateParts['month'], $x, $y, 51, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.723, 0.488); certificateDrawField($pdf, $dateParts['year'], $x, $y, 9, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.313, 0.629); certificateDrawField($pdf, $v('officiating_bishop'), $x, $y, 55, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.249, 0.66); certificateDrawField($pdf, $v('sponsor_1'), $x, $y, 61, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.181, 0.6908); certificateDrawField($pdf, $v('sponsor_2'), $x, $y, 65, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.522, 0.7215); certificateDrawField($pdf, $v('book_no'), $x, $y, 10, $fs, 'L', '', $lh);
    $givenParts = certificatePdfDateParts($v('given_date'));
    [$x, $y] = $at(0.406, 0.793); certificateDrawField($pdf, $givenParts['day'], $x, $y, 16, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.092, 0.821); certificateDrawField($pdf, trim($givenParts['month'] . ', 20' . $givenParts['year']), $x, $y, 40, $fs, 'L', '', $lh);

    return $pdf->Output('S');
}

function certificateDeathPdf(array $data): string
{
    $v = static fn(string $key): string => trim((string) ($data[$key] ?? ''));
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle(certificatePdfText('Certificate of Death - ' . $v('deceased_name')), true);
    $at = certificateStampTemplate($pdf, 'Death');

    [$x, $y] = $at(0.077, 0.2197); certificateDrawField($pdf, $v('deceased_name'), $x, $y, 76, 13, 'C', 'B');

    // Rows from here down are packed only ~5-6mm apart on the template —
    // shorter line height and smaller font so consecutive rows don't bleed.
    $lh = 4.0; $fs = 9;
    [$x, $y] = $at(0.303, 0.2685); certificateDrawField($pdf, $v('barangay'), $x, $y, 58, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.181, 0.2885); certificateDrawField($pdf, $v('municipality'), $x, $y, 42, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.618, 0.2885); certificateDrawField($pdf, $v('province'), $x, $y, 32, $fs, 'L', '', $lh);

    $isSingle = strtolower($v('civil_status')) === 'single';
    if ($isSingle) {
        [$x, $y] = $at(0.315, 0.331); certificateDrawField($pdf, $v('relation_names'), $x, $y, 42, $fs, 'L', '', $lh);
    } else {
        [$x, $y] = $at(0.348, 0.352); certificateDrawField($pdf, $v('relation_names'), $x, $y, 80, $fs, 'L', '', $lh);
    }

    $deathParts = certificatePdfDateParts($v('death_date'));
    [$x, $y] = $at(0.19, 0.3735); certificateDrawField($pdf, $deathParts['day'], $x, $y, 9, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.2715, 0.3735); certificateDrawField($pdf, $deathParts['month'], $x, $y, 38, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.547, 0.3735); certificateDrawField($pdf, $deathParts['year'], $x, $y, 7, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.7475, 0.3735); certificateDrawField($pdf, $v('death_age'), $x, $y, 14, $fs, 'L', '', $lh);

    $burialParts = certificatePdfDateParts($v('burial_date'));
    if ($v('burial_type') === 'municipal') {
        [$x, $y] = $at(0.22, 0.42); certificateDrawField($pdf, $v('municipal_cemetery_name'), $x, $y, 102, $fs, 'L', '', $lh);
    }
    [$x, $y] = $at(0.181, 0.441); certificateDrawField($pdf, $burialParts['day'], $x, $y, 9, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.283, 0.441); certificateDrawField($pdf, trim($burialParts['month'] . ($burialParts['year'] ? ', 20' . $burialParts['year'] : '')), $x, $y, 79, $fs, 'L', '', $lh);

    [$x, $y] = $at(0.397, 0.48); certificateDrawField($pdf, $v('cause_of_death'), $x, $y, 73, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.413, 0.6335); certificateDrawField($pdf, $v('book_no'), $x, $y, 15, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.586, 0.6335); certificateDrawField($pdf, $v('line_no'), $x, $y, 32, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.0905, 0.701); certificateDrawField($pdf, $v('parish_name'), $x, $y, 96, $fs + 1, 'C', '', $lh);

    $datedParts = certificatePdfDateParts($v('certificate_dated'));
    [$x, $y] = $at(0.2006, 0.726); certificateDrawField($pdf, $datedParts['day'], $x, $y, 14, $fs, 'L', '', $lh);
    [$x, $y] = $at(0.358, 0.726); certificateDrawField($pdf, trim($datedParts['month'] . ($datedParts['year'] ? ', 20' . $datedParts['year'] : '')), $x, $y, 53, $fs, 'L', '', $lh);

    return $pdf->Output('S');
}

function certificatePdf(string $type, array $data): string
{
    if (!class_exists('FPDF')) throw new RuntimeException('FPDF is required to generate an official certificate.');
    return match ($type) {
        'Baptism' => certificateBaptismPdf($data),
        'Marriage' => certificateMarriagePdf($data),
        'Confirmation' => certificateConfirmationPdf($data),
        'Death' => certificateDeathPdf($data),
        default => throw new InvalidArgumentException('Unknown certificate type.'),
    };
}
