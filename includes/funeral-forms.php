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
        'deceased_name' => trim((string) ($data['deceased_name'] ?? '')),
        'deceased_age' => trim((string) ($data['deceased_age'] ?? '')),
        'cause_of_death' => trim((string) ($data['cause_of_death'] ?? '')),
        'place_of_wake' => trim((string) ($data['place_of_wake'] ?? '')),
        'burial_date' => trim((string) ($data['burial_date'] ?? '')),
        'burial_time' => trim((string) ($data['burial_time'] ?? '')),
        'cemetery' => trim((string) ($data['cemetery'] ?? '')),
        
        'spouse_name' => trim((string) ($data['spouse_name'] ?? '')),
        'father_name' => trim((string) ($data['father_name'] ?? '')),
        'mother_name' => trim((string) ($data['mother_name'] ?? '')),
        
        'informant_name' => trim((string) ($data['informant_name'] ?? '')),
        'informant_relationship' => trim((string) ($data['informant_relationship'] ?? '')),
        'informant_address' => trim((string) ($data['informant_address'] ?? '')),
        'informant_phone' => trim((string) ($data['informant_phone'] ?? ''))
    ];
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
    
    $row('Ngalan sa Namatay', $data['deceased_name']);
    $row('Edad', $data['deceased_age']);
    $row('Sakit/Hinungdan sa Kamatayon', $data['cause_of_death']);
    $row('Lugar sa Minatay', $data['place_of_wake']);
    
    // Date and Time on same row
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 8, 'PETSA SA PAGLUBONG:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(50, 8, strtoupper($data['burial_date']), 'B', 0, 'L');
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(45, 8, 'ORAS SA PAGLUBONG:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, strtoupper($data['burial_time']), 'B', 1, 'L');
    $pdf->Ln(2);
    
    $row('Sementeryo nga Pagalubngan', $data['cemetery']);
    
    $pdf->Ln(5);
    $row('Ngalan sa Bana/Asawa', $data['spouse_name']);
    $row('Ngalan sa Amahan', $data['father_name']);
    $row('Ngalan sa Inahan', $data['mother_name']);
    
    $pdf->Ln(5);
    $row('Pangalan sa Nagpa-lubong', $data['informant_name']);
    $row('Relasyon', $data['informant_relationship']);
    $row('Address', $data['informant_address']);
    $row('Telepono', $data['informant_phone']);
    
    // Note for signatures
    $pdf->Ln(15);
    $pdf->Cell(90, 8, '________________________________', 0, 0, 'C');
    $pdf->Cell(0, 8, '________________________________', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(90, 5, 'Pirma sa Nagpa-lubong', 0, 0, 'C');
    $pdf->Cell(0, 5, 'Pirma sa Kura Paroko', 0, 1, 'C');
    
    return $pdf->Output('S');
}
