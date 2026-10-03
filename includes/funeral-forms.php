<?php
require_once __DIR__ . '/document-storage.php';
require_once __DIR__ . '/fpdf/fpdf.php';

const FUNERAL_FORM_TYPES = [
    'katin_awan_paglubong'
];

function funeralFormDefinition(string $type): array {
    return [
        'title' => 'Katin-awan sa Paglubong',
        'file_prefix' => 'Funeral_KatinAwan',
    ];
}

function processFuneralGeneratedForm(int $appointmentId, string $type, array $data, ?int $existingDocumentId = null): void {
    if (!in_array($type, FUNERAL_FORM_TYPES, true)) {
        throw new Exception('Invalid funeral form type.');
    }

    $def = funeralFormDefinition($type);
    $pdo = db();
    
    $stmt = $pdo->prepare("SELECT a.*, p.user_id FROM appointments a JOIN parishioners p ON p.parishioner_id = a.parishioner_id WHERE a.appointment_id = ?");
    $stmt->execute([$appointmentId]);
    $appointment = $stmt->fetch();
    
    if (!$appointment) {
        throw new Exception('Appointment not found.');
    }

    $fileName = $def['file_prefix'] . '_' . $appointmentId . '_' . time() . '.pdf';
    
    if ($type === 'katin_awan_paglubong') {
        $pdfContent = funeralKatinAwanPdf($data);
    } else {
        throw new Exception('Unknown form type PDF generator.');
    }

    if ($existingDocumentId) {
        $stmt = $pdo->prepare("UPDATE uploaded_documents SET file_data = ?, file_name = ?, review_status = 'pending', verified = 0, uploaded_at = NOW() WHERE document_id = ?");
        $stmt->execute([$pdfContent, $fileName, $existingDocumentId]);
        $documentId = $existingDocumentId;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO uploaded_documents 
            (appointment_id, user_id, requirement_label, file_name, file_data, uploaded_at, review_status, verified)
            VALUES (?, ?, ?, ?, ?, NOW(), 'pending', 0)
        ");
        $stmt->execute([
            $appointmentId,
            $appointment['user_id'] ?? null,
            $def['title'],
            $fileName,
            $pdfContent
        ]);
        $documentId = $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare("SELECT generated_form_id FROM generated_funeral_forms WHERE appointment_id = ? AND form_type = ?");
    $stmt->execute([$appointmentId, $type]);
    if ($stmt->fetchColumn()) {
        $up = $pdo->prepare("UPDATE generated_funeral_forms SET form_data = ?, document_id = ?, status = 'generated', rejection_reason = NULL, updated_at = NOW() WHERE appointment_id = ? AND form_type = ?");
        $up->execute([json_encode($data), $documentId, $appointmentId, $type]);
    } else {
        $ins = $pdo->prepare("INSERT INTO generated_funeral_forms (appointment_id, form_type, form_data, document_id, status) VALUES (?, ?, ?, ?, 'generated')");
        $ins->execute([$appointmentId, $type, json_encode($data), $documentId]);
    }
}

function funeralKatinAwanNormalizeData(array $data): array {
    return [
        'ngalan_sa_ilubong' => trim((string) ($data['ngalan_sa_ilubong'] ?? '')),
        'edad' => trim((string) ($data['edad'] ?? '')),
        'pinuy_anan' => trim((string) ($data['pinuy_anan'] ?? '')),
        'relihiyon' => trim((string) ($data['relihiyon'] ?? '')),
        'sakop_sa_kapilya' => trim((string) ($data['sakop_sa_kapilya'] ?? '')),
        'ngalan_sa_cluster' => trim((string) ($data['ngalan_sa_cluster'] ?? '')),
        
        'katapusan_nga_sakramento' => trim((string) ($data['katapusan_nga_sakramento'] ?? '')), // Hilog, Kumpisal, Wala
        
        'kanus_a_namatay' => trim((string) ($data['kanus_a_namatay'] ?? '')),
        'unsay_namatyan' => trim((string) ($data['unsay_namatyan'] ?? '')),
        
        'kanus_a_ilubong' => trim((string) ($data['kanus_a_ilubong'] ?? '')),
        'oras_sa_lubong' => trim((string) ($data['oras_sa_lubong'] ?? '')),
        
        'responde' => trim((string) ($data['responde'] ?? '')),
        'ginikanan_anak' => trim((string) ($data['ginikanan_anak'] ?? '')),
        'ginikanan_anak_cell' => trim((string) ($data['ginikanan_anak_cell'] ?? '')),
        'asawa_bana' => trim((string) ($data['asawa_bana'] ?? '')),
        'asawa_bana_cell' => trim((string) ($data['asawa_bana_cell'] ?? '')),
        
        'kasal' => trim((string) ($data['kasal'] ?? '')), // Simbahan, Sibil, Wala
        'petsa_sa_kasal' => trim((string) ($data['petsa_sa_kasal'] ?? '')),
        'diin_kasal' => trim((string) ($data['diin_kasal'] ?? '')),
    ];
}

function funeralKatinAwanValidationErrors(array $data): array {
    $errors = [];
    $data = funeralKatinAwanNormalizeData($data);
    $required = [
        'ngalan_sa_ilubong' => 'Ngalan sa Ilubong',
        'edad' => 'Edad',
        'pinuy_anan' => 'Pinuy-anan',
        'kanus_a_namatay' => 'Kanus-a Namatay',
        'unsay_namatyan' => 'Unsay Namatyan',
        'responde' => 'Responde'
    ];
    foreach ($required as $key => $label) {
        if ($data[$key] === '') $errors[] = "Please provide $label.";
    }
    return $errors;
}

function funeralKatinAwanPdf(array $data): string {
    $data = funeralKatinAwanNormalizeData($data);
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // Header
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, 'KATIN-AWAN SA PAGLUBONG', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 5, 'PAROKYA NI SAN GUILLERMO DE AQUITANIA', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Dalaguete, Cebu', 0, 1, 'C');
    $pdf->Ln(10);
    
    $pdf->SetFont('Arial', '', 11);
    
    // Helper for rows
    $row = function($label, $value) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(65, 8, strtoupper($label) . ':', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 11);
        // Add an underline using bottom border
        $pdf->Cell(0, 8, strtoupper($value), 'B', 1, 'L');
        $pdf->Ln(2);
    };
    
    $row('Ngalan sa Ilubong', $data['ngalan_sa_ilubong']);
    $row('Edad', $data['edad']);
    $row('Pinuy-anan', $data['pinuy_anan']);
    $row('Relihiyon', $data['relihiyon']);
    $row('Sakop sa Kapilya', $data['sakop_sa_kapilya']);
    $row('Ngalan sa Cluster', $data['ngalan_sa_cluster']);
    $row('Katapusan nga Sakramento', $data['katapusan_nga_sakramento']);
    
    $row('Kanus-a Namatay', $data['kanus_a_namatay']);
    $row('Unsay Namatyan', $data['unsay_namatyan']);
    
    // Date and Time on same row
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 8, 'PETSA SA LUBONG:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 8, strtoupper($data['kanus_a_ilubong']), 'B', 0, 'L');
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 8, 'ORAS SA LUBONG:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, strtoupper($data['oras_sa_lubong']), 'B', 1, 'L');
    $pdf->Ln(2);
    
    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'RESPONDENTE / BANAY:', 0, 1, 'L');
    
    $row('Responde', $data['responde']);
    $row('Ginikanan / Anak', $data['ginikanan_anak']);
    $row('Cell #', $data['ginikanan_anak_cell']);
    $row('Asawa / Bana', $data['asawa_bana']);
    $row('Cell #', $data['asawa_bana_cell']);
    
    $pdf->Ln(5);
    $row('Kasal', $data['kasal']);
    $row('Petsa sa Kasal', $data['petsa_sa_kasal']);
    $row('Diin', $data['diin_kasal']);
    
    // Note for signatures
    $pdf->Ln(15);
    $pdf->Cell(90, 8, '________________________________', 0, 0, 'C');
    $pdf->Cell(0, 8, '________________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(90, 5, 'Pirma sa Nagpa-lubong', 0, 0, 'C');
    $pdf->Cell(0, 5, 'Pirma sa Kura Paroko', 0, 1, 'C');
    
    return $pdf->Output('S');
}
