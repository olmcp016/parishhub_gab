<?php
const WEDDING_FORM_TYPES = ['matrimony_application', 'cluster_clearance', 'wedding_sponsor_clearance'];

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
    $def = weddingFormDefinition($type);
    $lines = ['Our Lady of Mt. Carmel Parish', '6342 Balilihan, Bohol, Philippines', '', $def['title'], str_repeat('=', 72), ''];
    foreach ($def['fields'] as $key => $label) {
        $value = is_array($data[$key] ?? null) ? implode(', ', $data[$key]) : (string) ($data[$key] ?? '');
        $lines[] = $label . ': ' . $value;
    }
    if ($type === 'cluster_clearance') {
        $lines[] = 'Parent marriage received:  [ ] Simbahan   [ ] Sibil   [ ] Wala';
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
        $lines[] = 'SERVICE REQUESTED (Please check):';
        $lines[] = '[ ] Bunyag   [ ] Confirmation   [X] Kasal   [ ] Ninong/Ninang';
        $lines[] = '[ ] Others, please specify: ' . (string) ($data['other_service'] ?? '');
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
