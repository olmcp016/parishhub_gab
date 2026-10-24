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

/** Church-style date, e.g. "October 22, 2026". */
function certificatePdfHumanDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) return $date;
    return $parsed->format('F j, Y');
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

    // Template dimensions & scaling (A4 portrait)
    $pageW = 210.0;
    $pageH = 297.0;
    $imgW = 1370;
    $imgH = 1860;
    $scale = min($pageW / $imgW, $pageH / $imgH);
    $drawW = $imgW * $scale;
    $drawH = $imgH * $scale;
    $offsetX = ($pageW - $drawW) / 2;
    $offsetY = ($pageH - $drawH) / 2;

    $pdf->AddPage('P', 'A4');
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);

    // 1. Stamp the pristine template containing the original green clover borders and Diocese header
    $templatePath = dirname(__DIR__) . '/public/img/certificates/Certificate of Baptism Template.jpg';
    if (!file_exists($templatePath)) {
        $templatePath = certificateTemplatePath('Baptism');
    }
    $pdf->Image($templatePath, $offsetX, $offsetY, $drawW, $drawH, 'JPG');

    // Helper to draw a crisp vector underline with centered value above it and label below it
    $drawField = function(string $label, string $value, float $x, float $y, float $w, float $fs = 11.5, string $style = 'B', string $align = 'C') use ($pdf) {
        $value = certificatePdfText($value);

        // Auto-shrink font if value is too long so it stays on one line
        if ($value !== '') {
            $currentFs = $fs;
            $pdf->SetFont('Times', $style, $currentFs);
            while ($currentFs > 6.5 && $pdf->GetStringWidth($value) > ($w - 2)) {
                $currentFs -= 0.5;
                $pdf->SetFont('Times', $style, $currentFs);
            }
            // Value sits neatly above the underline with breathing room
            $pdf->SetXY($x, $y - 5.2);
            $pdf->Cell($w, 5.0, $value, 0, 0, $align);
        }

        // Draw crisp horizontal line
        $pdf->SetDrawColor(30, 30, 30);
        $pdf->SetLineWidth(0.35);
        $pdf->Line($x, $y, $x + $w, $y);

        // Draw label below line
        if ($label !== '') {
            $pdf->SetFont('Times', '', 9.5);
            $pdf->SetTextColor(70, 70, 70);
            $pdf->SetXY($x, $y + 0.8);
            $pdf->Cell($w, 4.5, certificatePdfText($label), 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }
    };

    // 2. PARISH OF line & value
    $drawField('', $v('parish_of'), 64.0, 58.0, 110.0, 12.0, 'B', 'C');

    // 3. Child's Full Name (Centered line under "of")
    $drawField('', $v('child_name'), 35.0, 114.0, 140.0, 14.5, 'B', 'C');

    // 4. Parents (Two clean columns)
    $leftX = 22.0;
    $rightX = 110.0;
    $colW = 78.0;

    $drawField('Father', $v('father_name'), $leftX, 131.0, $colW, 11.5, 'B');
    $drawField('Mother', $v('mother_name'), $rightX, 131.0, $colW, 11.5, 'B');

    // 5. Birth Details
    $drawField('Date of Birth', certificatePdfHumanDate($v('birth_date')), $leftX, 148.5, $colW, 11.0, 'B');
    $drawField('Place of Birth', $v('birth_place'), $rightX, 148.5, $colW, 11.0, 'B');

    // 6. Baptism Details
    $drawField('Date of Baptism', certificatePdfHumanDate($v('baptism_date')), $leftX, 166.0, $colW, 11.0, 'B');
    $drawField('Place of Baptism', $v('baptism_place'), $rightX, 166.0, $colW, 11.0, 'B');

    // 7. Godparents Header
    $pdf->SetFont('Times', 'B', 11);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->SetXY(0, 175.5);
    $pdf->Cell(210, 5, 'Godparents', 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    // Godparents Lines
    $drawField('', $v('godparent_1'), $leftX, 189.0, $colW, 11.0, 'B');
    $drawField('', $v('godparent_2'), $rightX, 189.0, $colW, 11.0, 'B');

    // Extra blank godparent lines (for canonical format)
    $pdf->SetLineWidth(0.35);
    $pdf->Line($leftX, 201.0, $leftX + $colW, 201.0);
    $pdf->Line($rightX, 201.0, $rightX + $colW, 201.0);

    // 8. Priests (Centered solemnization lines)
    $centerLineW = 86.0;
    $centerX = ($pageW - $centerLineW) / 2;

    $drawField('Officiating Priest', $v('officiating_priest'), $centerX, 222.0, $centerLineW, 11.5, 'B');
    $drawField('Parish Priest', $v('parish_priest'), $centerX, 243.0, $centerLineW, 11.5, 'B');

    // 9. Canonical Registry (Bottom-Left)
    $regX = 22.0;
    $regLabelW = 20.0;
    $regLineX = $regX + $regLabelW;
    $regLineW = 26.0;

    $drawReg = function(string $lbl, string $val, float $y) use ($pdf, $regX, $regLabelW, $regLineX, $regLineW) {
        $pdf->SetFont('Times', '', 9.5);
        $pdf->SetXY($regX, $y - 3.5);
        $pdf->Cell($regLabelW, 4.5, $lbl, 0, 0, 'L');

        $val = certificatePdfText(trim($val));
        if ($val !== '') {
            $pdf->SetFont('Times', 'B', 10);
            $pdf->SetXY($regLineX, $y - 4.2);
            $pdf->Cell($regLineW, 4.0, $val, 0, 0, 'C');
        }

        $pdf->SetDrawColor(30, 30, 30);
        $pdf->SetLineWidth(0.35);
        $pdf->Line($regLineX, $y, $regLineX + $regLineW, $y);
    };

    $drawReg('Book No.', $v('book_no'), 257.0);
    $drawReg('Page No.', $v('page_no'), 263.5);
    $drawReg('Serial No.', $v('line_no'), 270.0);

    return $pdf->Output('S');
}

/* ------------------------------------------------------------------ */
/* Confirmation / Death / Marriage Recreated Hybrid Vector Renderers   */
/* ------------------------------------------------------------------ */

if (!defined('CERTX_TEMPLATE_DIR')) {
    $baseDir = is_dir(__DIR__ . '/../public') ? dirname(__DIR__) : __DIR__;
    define('CERTX_TEMPLATE_DIR', $baseDir . '/public/img/certificates/');
}
if (!defined('CERTX_LIFT'))   { define('CERTX_LIFT', 5.3); }
if (!defined('CERTX_CELL_H')) { define('CERTX_CELL_H', 5.0); }

/** First non-empty value among $keys of $d (arrays of keys = aliases). Scalars only. */
function certx_get(array $d, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && !is_array($d[$k]) && trim((string) $d[$k]) !== '') {
            return is_object($d[$k]) ? $d[$k] : trim((string) $d[$k]);
        }
    }
    return $default;
}

/** UTF-8 -> Windows-1252 for FPDF core fonts (keeps n-tilde, accents, etc.). */
function certx_txt($s): string
{
    $s = trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    if ($s === '') { return ''; }
    $o = function_exists('iconv') ? @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) : false;
    if ($o === false || $o === '') {
        $o = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : $s;
    }
    return $o;
}

function certx_date($v): ?DateTimeImmutable
{
    if ($v instanceof DateTimeInterface) {
        return (new DateTimeImmutable())->setTimestamp($v->getTimestamp());
    }
    $s = trim((string) $v);
    if ($s === '' || strpos($s, '0000-00-00') === 0) { return null; }
    $ts = strtotime($s);
    return $ts === false ? null : (new DateTimeImmutable())->setTimestamp($ts);
}

function certx_ordinal(int $n): string
{
    $m100 = $n % 100;
    if ($m100 >= 11 && $m100 <= 13) { return $n . 'th'; }
    switch ($n % 10) {
        case 1:  return $n . 'st';
        case 2:  return $n . 'nd';
        case 3:  return $n . 'rd';
        default: return $n . 'th';
    }
}

/** "October 22, 2026"; unparseable text is passed through untouched. */
function certx_dateLong($v): string
{
    $dt = certx_date($v);
    if ($dt) { return $dt->format('F j, Y'); }
    return is_scalar($v) ? trim((string) $v) : '';
}

/** [day => '22nd', month => 'October', year => '2026'] for forms with "__ day of ____, ____". */
function certx_dateParts($v): array
{
    $dt = certx_date($v);
    if ($dt) {
        return ['day' => certx_ordinal((int) $dt->format('j')), 'month' => $dt->format('F'), 'year' => $dt->format('Y')];
    }
    $raw = is_scalar($v) ? trim((string) $v) : '';
    return ['day' => '', 'month' => $raw, 'year' => ''];
}

/** Join witnesses/sponsors given as a string or array: "A", "A and B", "A, B and C". */
function certx_list($v): string
{
    if (is_array($v)) {
        $v = array_values(array_filter(array_map('trim', array_map('strval', $v)), 'strlen'));
        $n = count($v);
        if ($n === 0) { return ''; }
        if ($n === 1) { return $v[0]; }
        return implode(', ', array_slice($v, 0, $n - 1)) . ' and ' . $v[$n - 1];
    }
    return trim((string) $v);
}

/** Open the page, stamp the clean template full-bleed, return [pdf, mm-per-pixel]. */
function certx_begin(string $templateFile, float $pageW, int $imgW, int $imgH, string $title)
{
    if (!class_exists('FPDF')) {
        throw new RuntimeException('FPDF is not loaded. Require fpdf.php before generating certificates.');
    }
    $path = CERTX_TEMPLATE_DIR . $templateFile;
    if (!is_file($path)) {
        throw new RuntimeException("Certificate template not found: $templateFile.");
    }
    $pageH = round($pageW * $imgH / $imgW, 2);
    $pdf = new FPDF('P', 'mm', [$pageW, $pageH]);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle($title);
    $pdf->AddPage();
    $pdf->Image($path, 0, 0, $pageW, $pageH);
    return [$pdf, $pageW / $imgW];
}

/**
 * Shrink font until $text fits $width using Times Bold. Returns the fitted text.
 */
function certx_fit($pdf, string $text, float $width, float $maxPt, float $minPt = 6.5): string
{
    $avail = $width - 2.0;
    for ($size = $maxPt; $size >= $minPt; $size -= 0.25) {
        $pdf->SetFont('Times', 'B', $size);
        if ($pdf->GetStringWidth($text) <= $avail) { return $text; }
    }
    $pdf->SetFont('Times', 'B', $minPt);
    while ($text !== '' && $pdf->GetStringWidth($text . '...') > $avail) {
        $text = substr($text, 0, -1);
    }
    return rtrim($text) . '...';
}

/**
 * Draw one crisp vector line and, resting just above it, the centred bold value.
 */
function certx_field($pdf, array $style, float $k, array $box, $text, float $maxPt = 11.0): void
{
    [$x1, $x2, $y] = [$box[0] * $k, $box[1] * $k, $box[2] * $k];
    $pdf->SetDrawColor($style['line'][0], $style['line'][1], $style['line'][2]);
    $pdf->SetLineWidth($style['lw']);
    $pdf->Line($x1, $y, $x2, $y);

    $text = certx_txt($text);
    if ($text === '') { return; }
    $text = certx_fit($pdf, $text, $x2 - $x1, $maxPt);
    $pdf->SetTextColor($style['text'][0], $style['text'][1], $style['text'][2]);
    $pdf->SetXY($x1, $y - CERTX_LIFT);
    $pdf->Cell($x2 - $x1, CERTX_CELL_H, $text, 0, 0, 'C');
}

/** Vector "[   ]" / "[ X ]" box. */
function certx_checkbox($pdf, array $style, float $k, array $box, bool $checked): void
{
    [$xl, $xr, $yt, $yb] = [$box[0] * $k, $box[1] * $k, $box[2] * $k, $box[3] * $k];
    $tick = 1.0;
    $pdf->SetDrawColor($style['line'][0], $style['line'][1], $style['line'][2]);
    $pdf->SetLineWidth(0.3);
    $pdf->Line($xl, $yt, $xl, $yb);  $pdf->Line($xl, $yt, $xl + $tick, $yt);  $pdf->Line($xl, $yb, $xl + $tick, $yb);
    $pdf->Line($xr, $yt, $xr, $yb);  $pdf->Line($xr, $yt, $xr - $tick, $yt);  $pdf->Line($xr, $yb, $xr - $tick, $yb);
    if ($checked) {
        $cx = ($xl + $xr) / 2; $cy = ($yt + $yb) / 2; $r = 1.25;
        $pdf->SetDrawColor($style['text'][0], $style['text'][1], $style['text'][2]);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($cx - $r, $cy - $r, $cx + $r, $cy + $r);
        $pdf->Line($cx - $r, $cy + $r, $cx + $r, $cy - $r);
    }
}

function certx_finish($pdf, $dest, string $filename)
{
    if ($dest === null || $dest === '' || $dest === 'S') { return $pdf->Output('S'); }
    return $pdf->Output($dest, $filename);
}

/* ------------------------------------------------------------------ */
/* CERTIFICATE OF CONFIRMATION  (red snowflake border)                 */
/* ------------------------------------------------------------------ */

function certificateConfirmationPdf(array $data, string $dest = 'S')
{
    $style = ['line' => [185, 25, 25], 'text' => [25, 25, 25], 'lw' => 0.25];
    [$pdf, $k] = certx_begin('Certification of Confirmation Template.jpg', 139.7, 1245, 1937, 'Certificate of Confirmation');

    $F = [
        'parish'   => [273, 974, 510.3],  'place'    => [273, 973, 579.8],
        'name'     => [257, 1076, 733.2], 'father'   => [315, 1075, 800.5],
        'mother'   => [243, 851, 866.4],
        'cDay'     => [498, 593, 933.5],  'cMonth'   => [696, 923, 935.2], 'cYear' => [936, 1072, 937.0],
        'minister' => [484, 1071, 1202.0], 'sponsor1' => [483, 1071, 1269.2], 'sponsor2' => [241, 830, 1335.7],
        'register' => [702, 835, 1403.8],
        'gDay'     => [783, 973, 1540.0], 'gMonth'   => [167, 528, 1605.2], 'gYear' => [540, 658, 1606.5],
        'priest'   => [598, 1061, 1727.7],
    ];

    $conf  = certx_dateParts(certx_get($data, ['confirmation_date', 'date_confirmed', 'date', 'confirmed_on']));
    $given = certx_dateParts(certx_get($data, ['given_date', 'issued_date', 'date_issued'], date('Y-m-d')));

    certx_field($pdf, $style, $k, $F['parish'],   certx_get($data, ['parish_of', 'parish_name', 'parish']), 12);
    certx_field($pdf, $style, $k, $F['place'],    certx_get($data, ['parish_place', 'parish_address', 'parish_location']), 11);
    certx_field($pdf, $style, $k, $F['name'],     certx_get($data, ['confirmed_name', 'confirmand_name', 'full_name', 'name']), 13);
    certx_field($pdf, $style, $k, $F['father'],   certx_get($data, ['father_name', 'father']), 11);
    certx_field($pdf, $style, $k, $F['mother'],   certx_get($data, ['mother_name', 'mother']), 11);
    certx_field($pdf, $style, $k, $F['cDay'],     $conf['day'], 11);
    certx_field($pdf, $style, $k, $F['cMonth'],   $conf['month'], 11);
    certx_field($pdf, $style, $k, $F['cYear'],    $conf['year'], 11);
    certx_field($pdf, $style, $k, $F['minister'], certx_get($data, ['officiating_bishop', 'minister', 'confirming_prelate', 'bishop', 'confirmed_by']), 11);
    certx_field($pdf, $style, $k, $F['sponsor1'], certx_get($data, ['sponsor_1', 'sponsor1', 'sponsor_one']), 11);
    certx_field($pdf, $style, $k, $F['sponsor2'], certx_get($data, ['sponsor_2', 'sponsor2', 'sponsor_two']), 11);
    certx_field($pdf, $style, $k, $F['register'], certx_get($data, ['register_no', 'book_no', 'line_no', 'reg_no']), 11);
    certx_field($pdf, $style, $k, $F['gDay'],     $given['day'], 11);
    certx_field($pdf, $style, $k, $F['gMonth'],   $given['month'], 11);
    certx_field($pdf, $style, $k, $F['gYear'],    $given['year'], 11);
    certx_field($pdf, $style, $k, $F['priest'],   certx_get($data, ['priest', 'parish_priest', 'priest_name']), 11);

    return certx_finish($pdf, $dest, 'Certificate of Confirmation.pdf');
}

/* ------------------------------------------------------------------ */
/* CERTIFICATE OF DEATH  (black filigree corners)                      */
/* ------------------------------------------------------------------ */

function certificateDeathPdf(array $data, string $dest = 'S')
{
    $style = ['line' => [20, 20, 20], 'text' => [0, 0, 0], 'lw' => 0.25];
    [$pdf, $k] = certx_begin('Cetficate of Death Template.jpg', 139.7, 1302, 2048, 'Certificate of Death');

    $F = [
        'name'   => [199, 1105, 464.3],
        'brgy'   => [414, 1232, 565.6],  'muni'  => [286, 783, 616.0],   'prov' => [977, 1241, 615.7],
        'father' => [489, 822, 667.0],   'mother' => [902, 1241, 666.3], 'spouse' => [537, 1241, 717.4],
        'dDay'   => [244, 336, 765.0],   'dMonth' => [443, 682, 767.0],  'dYear' => [700, 818, 767.5], 'age' => [1021, 1135, 767.0],
        'cemetery' => [581, 1153, 867.3],
        'bDay'   => [347, 439, 914.5],   'bMonth' => [545, 873, 916.5],  'bYear' => [895, 1030, 917.2],
        'cause'  => [505, 1241, 1015.5],
        'book'   => [598, 743, 1308.4],  'folio' => [890, 1113, 1312.5],
        'parish' => [199, 1147, 1457.6], 'place' => [199, 1148, 1506.9],
        'sDay'   => [302, 466, 1553.0],  'sMonth' => [580, 1018, 1557.3], 'sYear' => [1038, 1149, 1562.5],
        'priest' => [818, 1150, 1757.5],
    ];
    $B = [
        'single'   => [73, 121, 635, 667],    'married'  => [74, 121, 685, 717],
        'parishCem' => [164, 217, 785, 817],  'muniCem'  => [165, 218, 834, 866],
        'sacr'     => [80, 128, 1032, 1063],  'noSacr'   => [83, 130, 1130, 1162],
    ];

    $father = certx_get($data, ['father_name', 'father']);
    $mother = certx_get($data, ['mother_name', 'mother']);
    $spouse = certx_get($data, ['spouse_name', 'spouse', 'husband_wife']);
    $relationNames = certx_get($data, ['relation_names']);
    $status = strtolower((string) certx_get($data, ['civil_status', 'status']));
    if ($status === '') { $status = ($spouse !== '') ? 'married' : 'single'; }
    $isSingle = ($status === 'single');

    if ($relationNames !== '') {
        if ($isSingle && $father === '') $father = $relationNames;
        if (!$isSingle && $spouse === '') $spouse = $relationNames;
    }

    $cemetery = certx_get($data, ['municipal_cemetery_name', 'municipal_cemetery', 'cemetery']);
    $burial   = strtolower((string) certx_get($data, ['burial_type', 'burial_place', 'burial']));
    if ($burial === '') { $burial = ($cemetery !== '') ? 'municipal' : 'parish'; }
    $isParishCem = (strpos($burial, 'muni') === false);

    $sacr = $data['sacraments_received'] ?? ($data['received_sacraments'] ?? ($data['sacraments'] ?? null));
    if (is_string($sacr)) {
        $sacr = in_array(strtolower(trim($sacr)), ['1', 'yes', 'true', 'y', 'received'], true) ? true : (in_array(strtolower(trim($sacr)), ['0', 'no', 'false', 'n', 'none'], true) ? false : null);
    }

    $died   = certx_dateParts(certx_get($data, ['death_date', 'date_of_death', 'died_on']));
    $buried = certx_dateParts(certx_get($data, ['burial_date', 'date_of_burial', 'buried_on']));
    $sealed = certx_dateParts(certx_get($data, ['certificate_dated', 'issued_date', 'date_issued', 'given_date'], date('Y-m-d')));

    certx_field($pdf, $style, $k, $F['name'],  certx_get($data, ['deceased_name', 'full_name', 'name']), 13);
    certx_field($pdf, $style, $k, $F['brgy'],  certx_get($data, ['barangay', 'brgy']), 11);
    certx_field($pdf, $style, $k, $F['muni'],  certx_get($data, ['municipality', 'town', 'city']), 11);
    certx_field($pdf, $style, $k, $F['prov'],  certx_get($data, ['province']), 11);

    certx_checkbox($pdf, $style, $k, $B['single'],  $isSingle);
    certx_checkbox($pdf, $style, $k, $B['married'], !$isSingle);
    certx_field($pdf, $style, $k, $F['father'], $isSingle ? $father : '', 10.5);
    certx_field($pdf, $style, $k, $F['mother'], $isSingle ? $mother : '', 10.5);
    certx_field($pdf, $style, $k, $F['spouse'], $isSingle ? '' : $spouse, 10.5);

    certx_field($pdf, $style, $k, $F['dDay'],   $died['day'], 10.5);
    certx_field($pdf, $style, $k, $F['dMonth'], $died['month'], 10.5);
    certx_field($pdf, $style, $k, $F['dYear'],  $died['year'], 10.5);
    certx_field($pdf, $style, $k, $F['age'],    certx_get($data, ['death_age', 'age', 'age_at_death']), 10.5);

    certx_checkbox($pdf, $style, $k, $B['parishCem'], $isParishCem);
    certx_checkbox($pdf, $style, $k, $B['muniCem'],  !$isParishCem);
    certx_field($pdf, $style, $k, $F['cemetery'], $isParishCem ? '' : $cemetery, 10.5);
    certx_field($pdf, $style, $k, $F['bDay'],   $buried['day'], 10.5);
    certx_field($pdf, $style, $k, $F['bMonth'], $buried['month'], 10.5);
    certx_field($pdf, $style, $k, $F['bYear'],  $buried['year'], 10.5);

    certx_field($pdf, $style, $k, $F['cause'], certx_get($data, ['cause_of_death', 'cause']), 10.5);
    certx_checkbox($pdf, $style, $k, $B['sacr'],   $sacr === true);
    certx_checkbox($pdf, $style, $k, $B['noSacr'], $sacr === false);

    certx_field($pdf, $style, $k, $F['book'],  certx_get($data, ['book_no', 'book']), 10.5);
    certx_field($pdf, $style, $k, $F['folio'], certx_get($data, ['folio_no', 'line_no', 'folio', 'page_no']), 10.5);

    certx_field($pdf, $style, $k, $F['parish'], certx_get($data, ['parish_name', 'parish']), 11);
    certx_field($pdf, $style, $k, $F['place'],  certx_get($data, ['parish_place', 'parish_address', 'parish_location']), 11);
    certx_field($pdf, $style, $k, $F['sDay'],   $sealed['day'], 10.5);
    certx_field($pdf, $style, $k, $F['sMonth'], $sealed['month'], 10.5);
    certx_field($pdf, $style, $k, $F['sYear'],  $sealed['year'], 10.5);
    certx_field($pdf, $style, $k, $F['priest'], certx_get($data, ['priest', 'parish_priest', 'priest_name']), 10.5);

    return certx_finish($pdf, $dest, 'Certificate of Death.pdf');
}

/* ------------------------------------------------------------------ */
/* CERTIFICATE OF MARRIAGE  (full red scrollwork frame)                */
/* ------------------------------------------------------------------ */

function certificateMarriagePdf(array $data, string $dest = 'S')
{
    $style = ['line' => [150, 20, 25], 'text' => [35, 10, 10], 'lw' => 0.25];
    [$pdf, $k] = certx_begin('Certificate of Marriage Template.jpg', 215.9, 1444, 1912, 'Certificate of Marriage');

    $F = [
        'church' => [531, 1082, 315.5], 'place' => [482, 745, 347.0], 'prov' => [868, 1083, 348.2],
        'gName' => [196, 728, 506.0], 'bName' => [803, 1264, 512.0],
        'gAge'  => [239, 728, 550.0], 'bAge'  => [802, 1264, 556.4],
        'gNat'  => [305, 727, 592.0], 'bNat'  => [802, 1264, 597.5],
        'gRes'  => [308, 727, 629.0], 'bRes'  => [801, 1264, 634.6],
        'gFath' => [259, 727, 669.0], 'bFath' => [801, 1264, 675.8],
        'gMoth' => [267, 726, 706.0], 'bMoth' => [800, 1264, 712.8],
        'mDay'  => [310, 524, 1073.3], 'mMonth' => [607, 1074, 1079.6], 'mYear' => [1090, 1232, 1084.5],
        'rev'   => [609, 1232, 1120.0],
        'wit'   => [389, 1230, 1158.8], 'witRes' => [393, 1138, 1199.8],
        'book'  => [457, 589, 1279.7], 'page' => [664, 795, 1283.0], 'line' => [866, 1013, 1286.0],
        'licNo' => [663, 797, 1319.5], 'licAt' => [906, 1230, 1325.7],
        'licDate' => [327, 610, 1359.5], 'licYear' => [622, 709, 1362.0],
        'dated' => [528, 952, 1407.7],
        'priest' => [566, 1229, 1602.0],
    ];

    $m   = certx_dateParts(certx_get($data, ['marriage_date', 'date_of_marriage', 'date']));
    $lic = certx_dateParts(certx_get($data, ['license_issued_date', 'license_date', 'marriage_license_date']));
    $licMonthDay = trim($lic['month'] . ' ' . ($lic['day'] !== '' ? (string) (int) $lic['day'] : ''));
    $dated = certx_dateLong(certx_get($data, ['certificate_dated', 'dated', 'issued_date', 'date_issued'], date('Y-m-d')));

    certx_field($pdf, $style, $k, $F['church'], certx_get($data, ['church_of', 'church_name', 'church', 'parish_name']), 12);
    certx_field($pdf, $style, $k, $F['place'],  certx_get($data, ['place', 'church_place', 'municipality']), 11);
    certx_field($pdf, $style, $k, $F['prov'],   certx_get($data, ['province']), 11);

    foreach (['g' => 'groom', 'b' => 'bride'] as $p => $who) {
        certx_field($pdf, $style, $k, $F[$p . 'Name'], certx_get($data, [$who . '_name', $who]), 12.5);
        certx_field($pdf, $style, $k, $F[$p . 'Age'],  certx_get($data, [$who . '_age']), 11);
        certx_field($pdf, $style, $k, $F[$p . 'Nat'],  certx_get($data, [$who . '_nationality'], ''), 11);
        certx_field($pdf, $style, $k, $F[$p . 'Res'],  certx_get($data, [$who . '_residence', $who . '_address']), 11);
        certx_field($pdf, $style, $k, $F[$p . 'Fath'], certx_get($data, [$who . '_father', $who . '_father_name']), 11);
        certx_field($pdf, $style, $k, $F[$p . 'Moth'], certx_get($data, [$who . '_mother', $who . '_mother_name']), 11);
    }

    certx_field($pdf, $style, $k, $F['mDay'],   $m['day'], 11);
    certx_field($pdf, $style, $k, $F['mMonth'], $m['month'], 11);
    certx_field($pdf, $style, $k, $F['mYear'],  $m['year'], 11);
    certx_field($pdf, $style, $k, $F['rev'],    certx_get($data, ['officiating_priest', 'officiant', 'solemnized_by', 'minister', 'rev']), 11);
    certx_field($pdf, $style, $k, $F['wit'],    certx_list($data['witness_name'] ?? ($data['witnesses'] ?? certx_get($data, ['witness', 'presence_of']))), 11);
    certx_field($pdf, $style, $k, $F['witRes'], certx_list($data['witness_residence'] ?? ($data['witnesses_residence'] ?? certx_get($data, ['witnesses_address']))), 11);

    certx_field($pdf, $style, $k, $F['book'],   certx_get($data, ['book_no', 'book']), 11);
    certx_field($pdf, $style, $k, $F['page'],   certx_get($data, ['page_no', 'page']), 11);
    certx_field($pdf, $style, $k, $F['line'],   certx_get($data, ['line_no', 'line']), 11);
    certx_field($pdf, $style, $k, $F['licNo'],  certx_get($data, ['marriage_license_no', 'license_no', 'license_number']), 11);
    certx_field($pdf, $style, $k, $F['licAt'],  certx_get($data, ['license_issued_at', 'license_place', 'issued_at']), 11);
    certx_field($pdf, $style, $k, $F['licDate'], $licMonthDay, 11);
    certx_field($pdf, $style, $k, $F['licYear'], $lic['year'], 11);
    certx_field($pdf, $style, $k, $F['dated'],  $dated, 11);
    certx_field($pdf, $style, $k, $F['priest'], certx_get($data, ['priest', 'parish_priest', 'priest_name']), 11.5);

    return certx_finish($pdf, $dest, 'Certificate of Marriage.pdf');
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
