<?php
/**
 * Backend for the Official Parish Certificate workflow — FLOW 1 (first
 * issuance right after a sacrament appointment is marked Completed) and
 * FLOW 2 (a parishioner's paid "Certificate Request" re-issuance/certified
 * copy). Both flows share one issued_certificates row per appointment; see
 * database/migration_issued_certificates.sql.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/certificate-generator.php';
require_once __DIR__ . '/document-storage.php';
require_once __DIR__ . '/wedding-forms.php';
require_once __DIR__ . '/mailer.php';

/** The certificate type produced when THIS sacrament category is completed. Null = not certificate-eligible. */
function certificateTypeForCategory(string $category): ?string
{
    return match ($category) {
        'Wedding' => 'Marriage',
        'Baptism' => 'Baptism',
        'Confirmation' => 'Confirmation',
        'Funeral' => 'Death',
        default => null,
    };
}

/** The certificate_type a Certificate Request's chosen certificate_type string maps to. */
function certificateTypeFromRequestLabel(string $label): ?string
{
    return match (true) {
        str_starts_with($label, 'Baptismal') => 'Baptism',
        str_starts_with($label, 'Confirmation') => 'Confirmation',
        str_starts_with($label, 'Death') => 'Death',
        str_starts_with($label, 'Marriage') => 'Marriage',
        default => null,
    };
}

/**
 * Parses the pipe-delimited "Label: Value | Label: Value" string that
 * parishioner/book.php saves into appointments.remarks for a Certificate
 * Request (see book.php's $certDetails). Returns ['Certificate' => '...', 'Requestor' => '...', ...].
 */
function certificateRequestParseRemarks(?string $remarks): array
{
    $out = [];
    foreach (explode(' | ', (string) $remarks) as $segment) {
        $segment = trim($segment);
        if ($segment === '' || !str_contains($segment, ': ')) continue;
        [$label, $value] = explode(': ', $segment, 2);
        $out[trim($label)] = trim($value);
    }
    return $out;
}

/**
 * FLOW 2 guard: whether a paid "Certificate Request" appointment actually has
 * a verified payment on record. Used before the certificate can be generated
 * or released — a Certificate Request must not skip the payment step just
 * because it reuses the general appointment pipeline.
 */
function certificateRequestPaymentVerified(array $appointment): bool
{
    $appointmentId = (int) ($appointment['appointment_id'] ?? 0);
    if ($appointmentId < 1) return false;
    $stmt = db()->prepare(
        "SELECT 1 FROM payments WHERE appointment_id = ? AND payment_status = 'verified' LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    return (bool) $stmt->fetchColumn();
}

function issuedCertificateLoad(int $appointmentId): ?array
{
    $stmt = db()->prepare('SELECT * FROM issued_certificates WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['form_data'] = json_decode((string) $row['form_data'], true) ?: [];
    return $row;
}

/**
 * Saves (inserts or updates) the registry fields and/or form data for an
 * appointment's certificate, without generating a PDF. Used both to record
 * Book/Page/Line before generating, and to persist edits.
 */
function issuedCertificateSaveFields(int $appointmentId, string $certificateType, array $fields): int
{
    $existing = issuedCertificateLoad($appointmentId);
    $formData = array_merge($existing['form_data'] ?? [], $fields['form_data'] ?? []);
    $encoded = json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    if ($existing) {
        db()->prepare(
            'UPDATE issued_certificates SET book_no = ?, page_no = ?, line_no = ?, registry_date = ?,
                certificate_purpose = ?, form_data = ?, source_appointment_id = COALESCE(?, source_appointment_id)
             WHERE issued_certificate_id = ?'
        )->execute([
            $fields['book_no'] ?? $existing['book_no'],
            $fields['page_no'] ?? $existing['page_no'],
            $fields['line_no'] ?? $existing['line_no'],
            $fields['registry_date'] ?? $existing['registry_date'],
            $fields['certificate_purpose'] ?? $existing['certificate_purpose'],
            $encoded,
            $fields['source_appointment_id'] ?? null,
            $existing['issued_certificate_id'],
        ]);
        return (int) $existing['issued_certificate_id'];
    }

    db()->prepare(
        'INSERT INTO issued_certificates
            (appointment_id, source_appointment_id, certificate_type, book_no, page_no, line_no, registry_date, certificate_purpose, form_data, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')'
    )->execute([
        $appointmentId,
        $fields['source_appointment_id'] ?? null,
        $certificateType,
        $fields['book_no'] ?? null,
        $fields['page_no'] ?? null,
        $fields['line_no'] ?? null,
        $fields['registry_date'] ?? null,
        $fields['certificate_purpose'] ?? null,
        $encoded,
    ]);
    $stmt = db()->prepare('SELECT issued_certificate_id FROM issued_certificates WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    return (int) $stmt->fetchColumn();
}

/**
 * The Parish Priest signatory to save with a certificate, from the form's choice
 * and the certificate's SAVED form_data (not autofill). 'saved', or the saved
 * priest's own id, keeps the saved snapshot exactly as it is; a different id must
 * be an active priest with a verified printed name (admin/settings.php), which is
 * what gets printed; '' leaves the line blank. Anything else — free text, an
 * inactive, unknown or unverified priest — is refused ('ok' => false).
 *
 * @return array{ok: bool, parish_priest_id: string, parish_priest: string}
 */
function certificateResolveSignatory(string $posted, array $savedFormData): array
{
    $savedName = trim((string) ($savedFormData['parish_priest'] ?? ''));
    $savedId = (string) ($savedFormData['parish_priest_id'] ?? '');
    $refused = ['ok' => false, 'parish_priest_id' => '', 'parish_priest' => ''];
    if ($posted === 'saved' || ($posted !== '' && $posted === $savedId && $savedName !== '')) {
        return $savedName !== '' ? ['ok' => true, 'parish_priest_id' => $savedId, 'parish_priest' => $savedName] : $refused;
    }
    if ($posted === '') return ['ok' => true, 'parish_priest_id' => '', 'parish_priest' => ''];
    if (!ctype_digit($posted)) return $refused;
    $printedName = activePriestById((int) $posted) ? priestPrintedName((int) $posted) : null;
    return $printedName !== null ? ['ok' => true, 'parish_priest_id' => $posted, 'parish_priest' => $printedName] : $refused;
}

/** Generates the PDF from the certificate's currently-saved fields and attaches it as an uploaded_document. */
function issuedCertificateGenerate(int $appointmentId, int $actorUserId): array
{
    $cert = issuedCertificateLoad($appointmentId);
    if (!$cert) throw new RuntimeException('Save the registry details before generating the certificate.');

    $data = array_merge($cert['form_data'], [
        'book_no' => (string) ($cert['book_no'] ?? ''),
        'page_no' => (string) ($cert['page_no'] ?? ''),
        'line_no' => (string) ($cert['line_no'] ?? ''),
    ]);
    $errors = certificateValidationErrors($cert['certificate_type'], $data, true);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdfBytes = certificatePdf($cert['certificate_type'], $data);
        $stored = documentStorageWriteBytes($pdfBytes);
        $label = 'Certificate of ' . $cert['certificate_type'] . '.pdf';
        $pdo->prepare(
            "INSERT INTO uploaded_documents (appointment_id, file_name, file_path, file_type, requirement_label, review_status, verified, document_source, generated_form_type)
             VALUES (?, ?, ?, 'application/pdf', ?, 'approved', TRUE, 'generated', 'official_certificate')"
        )->execute([$appointmentId, $label, $stored['key'], $label]);
        $documentId = (int) $pdo->lastInsertId();

        $oldDocumentId = (int) ($cert['document_id'] ?? 0);
        $pdo->prepare(
            "UPDATE issued_certificates SET document_id = ?, status = 'generated', issued_by = ?, issued_at = CURRENT_TIMESTAMP WHERE issued_certificate_id = ?"
        )->execute([$documentId, $actorUserId, $cert['issued_certificate_id']]);

        if ($oldDocumentId) {
            $pdo->prepare('UPDATE uploaded_documents SET superseded_by = ? WHERE document_id = ? AND superseded_by IS NULL')->execute([$documentId, $oldDocumentId]);
        }
        $pdo->commit();
        return ['document_id' => $documentId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * FLOW 1 autofill: pulls whatever is already on file for a just-completed
 * sacrament appointment — the appointment row, its generated requirement
 * forms (Wedding/Baptism/Funeral), the assigned priest, and the parishioner's
 * own profile — into the certificate's field keys. Fields with no upstream
 * source are left blank for the Secretary to type (same spirit as every
 * other generated form in this app: a head start, not a guarantee).
 */
function certificateAutoFillFromAppointment(int $appointmentId): array
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT a.*, s.category, p.title AS priest_title, p.full_name AS priest_name,
                u.firstname, u.lastname, u.middlename, u.gender, u.address AS profile_address
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN priests p ON p.priest_id = a.priest_id
         LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = par.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $a = $stmt->fetch();
    if (!$a) return [];

    $certType = certificateTypeForCategory((string) $a['category']);
    if (!$certType) return [];

    // Officiating priest: the assigned priest's verified printed name when the Admin has
    // verified one; otherwise the existing editable pre-fill from the priest record.
    $priestName = (!empty($a['priest_id']) ? priestPrintedName((int) $a['priest_id']) : null)
        ?? trim(($a['priest_title'] ?? '') . ' ' . ($a['priest_name'] ?? ''));
    $parishionerName = trim(implode(' ', array_filter([$a['firstname'] ?? '', $a['middlename'] ?? '', $a['lastname'] ?? ''])));
    $parishName = 'Our Lady of Mt. Carmel Parish';
    // The signatory defaults to the current Parish Priest's VERIFIED printed name —
    // applied only here, when a certificate is first prepared; blank when none is
    // verified. Once saved, the certificate keeps its own snapshot.
    $parishPriest = currentParishPriest();
    $parishPriestName = currentParishPriestSignatory();
    $signatory = [
        'parish_priest_id' => $parishPriest && $parishPriestName !== '' ? (string) $parishPriest['priest_id'] : '',
        'parish_priest' => $parishPriestName,
    ];
    $parishAddress = trim((string) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'parish_address'")->fetchColumn() ?: ''));

    $forms = [];
    $fstmt = $pdo->prepare("SELECT form_type, form_data FROM generated_forms WHERE service_category = ? AND appointment_id = ?");
    $fstmt->execute([$a['category'], $appointmentId]);
    foreach ($fstmt->fetchAll() as $row) {
        $forms[$row['form_type']] = json_decode((string) $row['form_data'], true) ?: [];
    }

    $data = [];
    if ($certType === 'Baptism') {
        $k = $forms['katin_awan_bunyag'] ?? [];
        $data = [
            'parish_of' => $parishName,
            'child_name' => $k['child_name'] ?? $parishionerName,
            'father_name' => $k['father_name'] ?? '',
            'mother_name' => $k['mother_name'] ?? '',
            'birth_date' => $k['birth_date'] ?? '',
            'birth_place' => $k['birth_place'] ?? '',
            'baptism_date' => (string) ($a['appointment_date'] ?? ''),
            'baptism_place' => $parishName,
            'godparent_1' => $k['sponsor_1'] ?? '',
            'godparent_2' => $k['sponsor_2'] ?? '',
            'officiating_priest' => $priestName,
        ] + $signatory;
    } elseif ($certType === 'Marriage') {
        $app = $forms['matrimony_application'] ?? [];
        $cluster = $forms['cluster_clearance'] ?? [];
        $data = [
            'church_of' => $parishName,
            'place' => 'Balilihan',
            'province' => 'Bohol',
            'groom_name' => $app['groom_name'] ?? $cluster['kaslonon_name'] ?? '',
            'bride_name' => $app['bride_name'] ?? $cluster['spouse_name'] ?? '',
            'groom_nationality' => 'Filipino',
            'bride_nationality' => 'Filipino',
            'groom_residence' => $app['groom_address'] ?? $cluster['address'] ?? '',
            'bride_residence' => $app['bride_address'] ?? $cluster['spouse_address'] ?? '',
            'groom_father' => $app['groom_father'] ?? $cluster['father_name'] ?? '',
            'bride_father' => $app['bride_father'] ?? '',
            'groom_mother' => $app['groom_mother_maiden_name'] ?? $app['groom_mother'] ?? $cluster['mother_name'] ?? '',
            'bride_mother' => $app['bride_mother_maiden_name'] ?? $app['bride_mother'] ?? '',
            'marriage_date' => (string) ($a['appointment_date'] ?? ''),
            'officiating_priest' => $priestName,
            'witness_name' => $cluster['sponsor_1'] ?? '',
            'witness_residence' => '',
            'marriage_license_no' => '',
            'license_issued_at' => '',
            'license_issued_date' => '',
            'certificate_dated' => date('Y-m-d'),
        ] + $signatory;
        if (!empty($app['groom_birth_date'])) $data['groom_age'] = (string) (weddingMarriageAge($app['groom_birth_date'], (string) $a['appointment_date']) ?? '');
        if (!empty($app['bride_birth_date'])) $data['bride_age'] = (string) (weddingMarriageAge($app['bride_birth_date'], (string) $a['appointment_date']) ?? '');
    } elseif ($certType === 'Confirmation') {
        $data = [
            'parish_of' => $parishName,
            'parish_place' => $parishAddress,
            'confirmed_name' => $parishionerName,
            'father_name' => '',
            'mother_name' => '',
            'confirmation_date' => (string) ($a['appointment_date'] ?? ''),
            'officiating_bishop' => $priestName,
            'sponsor_1' => '',
            'sponsor_2' => '',
            'given_date' => date('Y-m-d'),
        ] + $signatory;
    } elseif ($certType === 'Death') {
        $k = $forms['katin_awan_paglubong'] ?? [];
        $isSingle = empty($k['asawa_bana']);
        $data = [
            'deceased_name' => $k['ngalan_sa_ilubong'] ?? '',
            'barangay' => '',
            'municipality' => 'Balilihan',
            'province' => 'Bohol',
            'civil_status' => $isSingle ? 'single' : 'married',
            'relation_names' => $isSingle ? trim(($k['ginikanan_anak'] ?? '')) : trim(($k['asawa_bana'] ?? '')),
            'death_date' => $k['kanus_a_namatay'] ?? (string) ($a['date_of_death'] ?? ''),
            'death_age' => $k['edad'] ?? '',
            'burial_type' => 'parish',
            'municipal_cemetery_name' => '',
            'burial_date' => $k['kanus_a_ilubong'] ?? (string) ($a['appointment_date'] ?? ''),
            'cause_of_death' => $k['unsay_namatyan'] ?? '',
            'sacraments_received' => (!empty($k['sakramento_hilog']) || !empty($k['sakramento_kumpisal'])) ? 'received' : 'not_received',
            'parish_name' => $parishName,
            'parish_place' => $parishAddress,
            'certificate_dated' => date('Y-m-d'),
        ] + $signatory;
    }

    return $data;
}

/**
 * FLOW 2 "Search & Autofill from Parish Records": finds completed sacrament
 * appointments whose subject name resembles $name, across all four
 * certificate-eligible categories, for the Secretary to pick from.
 *
 * @return array<int, array{appointment_id:int, category:string, certificate_type:string, name:string, date:string}>
 */
function certificateSearchMatches(string $name): array
{
    $name = trim($name);
    if ($name === '') return [];
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $name) . '%';

    $stmt = db()->prepare(
        "SELECT a.appointment_id, s.category, a.appointment_date, a.patient_name, a.guest_name,
                COALESCE(u.firstname || ' ' || u.lastname, '') AS parishioner_name,
                gf.form_type, gf.form_data
         FROM appointments a
         JOIN services s ON s.service_id = a.service_id
         LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = par.user_id
         LEFT JOIN generated_forms gf ON gf.appointment_id = a.appointment_id
         WHERE a.status_id = 6 AND s.category IN ('Wedding', 'Baptism', 'Confirmation', 'Funeral')
           AND (
             a.patient_name ILIKE ?
             OR a.guest_name ILIKE ?
             OR (u.firstname || ' ' || u.lastname) ILIKE ?
             OR gf.form_data::text ILIKE ?
           )
         ORDER BY a.appointment_date DESC LIMIT 25"
    );
    $stmt->execute([$like, $like, $like, $like]);

    $results = [];
    foreach ($stmt->fetchAll() as $row) {
        $appointmentId = (int) $row['appointment_id'];
        $category = (string) $row['category'];
        $certType = certificateTypeForCategory($category);
        if (!$certType || isset($results[$appointmentId])) continue;

        $formData = $row['form_data'] ? (json_decode((string) $row['form_data'], true) ?: []) : [];
        $displayName = match ($category) {
            'Wedding' => trim(($formData['groom_name'] ?? '') . ' & ' . ($formData['spouse_name'] ?? $formData['bride_name'] ?? '')),
            'Baptism' => $formData['child_name'] ?? $row['parishioner_name'],
            'Funeral' => $formData['ngalan_sa_ilubong'] ?? $row['patient_name'] ?? '',
            default => $row['parishioner_name'] ?: $row['patient_name'],
        };
        if (trim((string) $displayName) === '' || !str_contains(strtolower((string) $displayName), strtolower($name))) {
            // The name match came from a different field (e.g. the requester's own
            // name) — still worth showing, just don't silently drop it either way.
            $displayName = $displayName ?: ($row['parishioner_name'] ?: $row['patient_name'] ?: 'Unknown');
        }

        $results[$appointmentId] = [
            'appointment_id' => $appointmentId,
            'category' => $category,
            'certificate_type' => $certType,
            'name' => (string) $displayName,
            'date' => (string) $row['appointment_date'],
        ];
    }
    return array_values($results);
}

/**
 * Automatically searches for an exact completed sacrament appointment match
 * using the structured name fields and optional year from a Certificate Request.
 * Returns the matched row if exactly one confident match is found, null otherwise.
 *
 * @param string $certType 'Marriage', 'Baptism', 'Confirmation', 'Death'
 * @param array $fields Parsed request fields from remarks
 * @return array{appointment_id:int, date:string, name:string, category:string, certificate_type:string}|null
 */
function certificateFindExactMatch(string $certType, array $fields): ?array
{
    $pdo = db();
    $targetCategory = match ($certType) {
        'Marriage' => 'Wedding',
        'Baptism' => 'Baptism',
        'Confirmation' => 'Confirmation',
        'Death' => 'Funeral',
        default => null,
    };
    if (!$targetCategory) return null;

    if ($certType === 'Marriage') {
        $gFirst = trim((string) ($fields['Groom First Name'] ?? ''));
        $gLast = trim((string) ($fields['Groom Last Name'] ?? ''));
        $bFirst = trim((string) ($fields['Bride First Name'] ?? ''));
        $bLast = trim((string) ($fields['Bride Last Name'] ?? ''));
        $year = trim((string) ($fields['Year of Marriage'] ?? ''));
        $groomFull = trim((string) ($fields['Groom'] ?? ''));
        $brideFull = trim((string) ($fields['Bride'] ?? ''));

        if (($gLast === '' || $gFirst === '') && $groomFull === '') return null;

        $sql = "SELECT a.appointment_id, s.category, a.appointment_date,
                       gf.form_type, gf.form_data
                FROM appointments a
                JOIN services s ON s.service_id = a.service_id
                LEFT JOIN generated_forms gf ON gf.appointment_id = a.appointment_id AND gf.form_type = 'matrimony_application'
                WHERE a.status_id = 6 AND s.category = 'Wedding'";
        $params = [];
        if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
            $sql .= " AND EXTRACT(YEAR FROM a.appointment_date) = ?";
            $params[] = (int) $year;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = [];

        foreach ($stmt->fetchAll() as $row) {
            $formData = $row['form_data'] ? (json_decode((string) $row['form_data'], true) ?: []) : [];
            $recordGroom = strtolower(trim((string) ($formData['groom_name'] ?? '')));
            $recordBride = strtolower(trim((string) ($formData['bride_name'] ?? '')));

            $groomMatch = false;
            if ($gLast !== '' && $gFirst !== '') {
                $groomMatch = str_contains($recordGroom, strtolower($gLast)) && str_contains($recordGroom, strtolower($gFirst));
            } elseif ($groomFull !== '') {
                $groomMatch = str_contains($recordGroom, strtolower($groomFull));
            }

            $brideMatch = false;
            if ($bLast !== '' && $bFirst !== '') {
                $brideMatch = str_contains($recordBride, strtolower($bLast)) && str_contains($recordBride, strtolower($bFirst));
            } elseif ($brideFull !== '') {
                $brideMatch = str_contains($recordBride, strtolower($brideFull));
            }

            if ($groomMatch && $brideMatch) {
                $matches[(int) $row['appointment_id']] = [
                    'appointment_id' => (int) $row['appointment_id'],
                    'category' => 'Wedding',
                    'certificate_type' => 'Marriage',
                    'name' => trim(($formData['groom_name'] ?? '') . ' & ' . ($formData['bride_name'] ?? '')),
                    'date' => (string) $row['appointment_date'],
                ];
            }
        }
        return count($matches) === 1 ? reset($matches) : null;
    }

    if ($certType === 'Baptism') {
        $first = trim((string) ($fields['Registrant First Name'] ?? ''));
        $last = trim((string) ($fields['Registrant Last Name'] ?? ''));
        $regFull = trim((string) ($fields['Registrant'] ?? ''));
        $year = trim((string) ($fields['Year of Baptism'] ?? ''));

        if (($first === '' || $last === '') && $regFull === '') return null;

        $sql = "SELECT a.appointment_id, s.category, a.appointment_date,
                       COALESCE(u.firstname || ' ' || u.lastname, '') AS parishioner_name,
                       gf.form_type, gf.form_data
                FROM appointments a
                JOIN services s ON s.service_id = a.service_id
                LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
                LEFT JOIN users u ON u.user_id = par.user_id
                LEFT JOIN generated_forms gf ON gf.appointment_id = a.appointment_id AND gf.form_type = 'katin_awan_bunyag'
                WHERE a.status_id = 6 AND s.category = 'Baptism'";
        $params = [];
        if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
            $sql .= " AND EXTRACT(YEAR FROM a.appointment_date) = ?";
            $params[] = (int) $year;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = [];

        foreach ($stmt->fetchAll() as $row) {
            $formData = $row['form_data'] ? (json_decode((string) $row['form_data'], true) ?: []) : [];
            $childName = strtolower(trim((string) ($formData['child_name'] ?? $row['parishioner_name'] ?? '')));

            $matched = false;
            if ($first !== '' && $last !== '') {
                $matched = str_contains($childName, strtolower($first)) && str_contains($childName, strtolower($last));
            } elseif ($regFull !== '') {
                $matched = str_contains($childName, strtolower($regFull));
            }

            if ($matched) {
                $matches[(int) $row['appointment_id']] = [
                    'appointment_id' => (int) $row['appointment_id'],
                    'category' => 'Baptism',
                    'certificate_type' => 'Baptism',
                    'name' => (string) ($formData['child_name'] ?? $row['parishioner_name']),
                    'date' => (string) $row['appointment_date'],
                ];
            }
        }
        return count($matches) === 1 ? reset($matches) : null;
    }

    if ($certType === 'Confirmation') {
        $first = trim((string) ($fields['Registrant First Name'] ?? ''));
        $last = trim((string) ($fields['Registrant Last Name'] ?? ''));
        $regFull = trim((string) ($fields['Registrant'] ?? ''));
        $year = trim((string) ($fields['Year of Confirmation'] ?? ''));

        if (($first === '' || $last === '') && $regFull === '') return null;

        $sql = "SELECT a.appointment_id, s.category, a.appointment_date, a.patient_name,
                       COALESCE(u.firstname || ' ' || u.lastname, '') AS parishioner_name
                FROM appointments a
                JOIN services s ON s.service_id = a.service_id
                LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
                LEFT JOIN users u ON u.user_id = par.user_id
                WHERE a.status_id = 6 AND s.category = 'Confirmation'";
        $params = [];
        if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
            $sql .= " AND EXTRACT(YEAR FROM a.appointment_date) = ?";
            $params[] = (int) $year;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = [];

        foreach ($stmt->fetchAll() as $row) {
            $target = strtolower(trim((string) ($row['parishioner_name'] ?: $row['patient_name'])));
            $matched = false;
            if ($first !== '' && $last !== '') {
                $matched = str_contains($target, strtolower($first)) && str_contains($target, strtolower($last));
            } elseif ($regFull !== '') {
                $matched = str_contains($target, strtolower($regFull));
            }

            if ($matched) {
                $matches[(int) $row['appointment_id']] = [
                    'appointment_id' => (int) $row['appointment_id'],
                    'category' => 'Confirmation',
                    'certificate_type' => 'Confirmation',
                    'name' => (string) ($row['parishioner_name'] ?: $row['patient_name']),
                    'date' => (string) $row['appointment_date'],
                ];
            }
        }
        return count($matches) === 1 ? reset($matches) : null;
    }

    if ($certType === 'Death') {
        $first = trim((string) ($fields['Deceased First Name'] ?? ''));
        $last = trim((string) ($fields['Deceased Last Name'] ?? ''));
        $decFull = trim((string) ($fields['Deceased'] ?? ''));
        $year = trim((string) ($fields['Year of Death'] ?? ''));

        if (($first === '' || $last === '') && $decFull === '') return null;

        $sql = "SELECT a.appointment_id, s.category, a.appointment_date, a.patient_name,
                       gf.form_type, gf.form_data
                FROM appointments a
                JOIN services s ON s.service_id = a.service_id
                LEFT JOIN generated_forms gf ON gf.appointment_id = a.appointment_id AND gf.form_type = 'katin_awan_paglubong'
                WHERE a.status_id = 6 AND s.category = 'Funeral'";
        $params = [];
        if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
            $sql .= " AND (EXTRACT(YEAR FROM a.appointment_date) = ? OR EXTRACT(YEAR FROM a.date_of_death) = ?)";
            $params[] = (int) $year;
            $params[] = (int) $year;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $matches = [];

        foreach ($stmt->fetchAll() as $row) {
            $formData = $row['form_data'] ? (json_decode((string) $row['form_data'], true) ?: []) : [];
            $target = strtolower(trim((string) ($formData['ngalan_sa_ilubong'] ?? $row['patient_name'] ?? '')));

            $matched = false;
            if ($first !== '' && $last !== '') {
                $matched = str_contains($target, strtolower($first)) && str_contains($target, strtolower($last));
            } elseif ($decFull !== '') {
                $matched = str_contains($target, strtolower($decFull));
            }

            if ($matched) {
                $matches[(int) $row['appointment_id']] = [
                    'appointment_id' => (int) $row['appointment_id'],
                    'category' => 'Funeral',
                    'certificate_type' => 'Death',
                    'name' => (string) ($formData['ngalan_sa_ilubong'] ?? $row['patient_name']),
                    'date' => (string) $row['appointment_date'],
                ];
            }
        }
        return count($matches) === 1 ? reset($matches) : null;
    }

    return null;
}

/**
 * FLOW 2: marks a generated certificate "Ready for Pick-up" and tells the
 * requester — an in-app notification for a logged-in parishioner, an email
 * for a guest (no account to notify in-app) — same split as the rest of the
 * app's guest/parishioner notification handling (includes/mailer.php).
 */
function issuedCertificateMarkReadyForPickup(int $appointmentId): void
{
    $stmt = db()->prepare(
        "SELECT a.guest_name, a.guest_email, a.patient_name, ic.certificate_type,
                u.user_id, u.firstname, u.lastname, u.email
         FROM appointments a
         JOIN issued_certificates ic ON ic.appointment_id = a.appointment_id
         LEFT JOIN parishioners par ON par.parishioner_id = a.parishioner_id
         LEFT JOIN users u ON u.user_id = par.user_id
         WHERE a.appointment_id = ?"
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Certificate not found for this appointment.');

    $current = issuedCertificateLoad($appointmentId);
    if (!$current || $current['status'] !== 'generated' || empty($current['document_id'])) {
        throw new RuntimeException('Generate the certificate before marking it ready for pick-up.');
    }

    db()->prepare("UPDATE issued_certificates SET status = 'ready_for_pickup' WHERE appointment_id = ? AND status = 'generated'")->execute([$appointmentId]);

    $certLabel = 'Certificate of ' . $row['certificate_type'];
    $title = 'Certificate Ready for Pick-up';
    $message = "Your $certLabel is ready for pick-up at the parish office. Please bring a valid ID.";

    $isGuest = empty($row['user_id']);
    if ($isGuest) {
        if (!empty($row['guest_email'])) {
            $html = '<p>Good day ' . e($row['guest_name'] ?: '') . ',</p><p>' . e($message) . '</p>';
            sendEmail((string) $row['guest_email'], $row['guest_name'], $title, emailTemplate($title, $html));
        }
        return;
    }

    try {
        db()->prepare("INSERT INTO notifications (user_id, type, category, title, message) VALUES (?, 'website', 'appointment', ?, ?)")
            ->execute([(int) $row['user_id'], $title, $message]);
    } catch (Throwable $e) {
        error_log("Ready-for-pickup notification for appointment #$appointmentId failed: " . $e->getMessage());
    }
    if (!empty($row['email'])) {
        $html = '<p>Good day ' . e($row['firstname'] ?: '') . ',</p><p>' . e($message) . '</p>';
        sendEmail((string) $row['email'], trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')), $title, emailTemplate($title, $html));
    }
}

/**
 * FLOW 2 lifecycle closure: the requester physically picked up the printed,
 * stamped certificate at the parish office. Marks the certificate 'claimed'
 * (with who/when) and — since nothing else ever advances a Certificate
 * Request's own appointment status — closes the appointment out as
 * Completed too, so it stops sitting open indefinitely.
 */
function issuedCertificateMarkClaimed(int $appointmentId, int $actorUserId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            "UPDATE issued_certificates
             SET status = 'claimed', claimed_at = CURRENT_TIMESTAMP, claimed_by_user_id = ?
             WHERE appointment_id = ? AND status = 'ready_for_pickup'"
        );
        $update->execute([$actorUserId, $appointmentId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            throw new RuntimeException('Certificate is not awaiting pick-up.');
        }
        $pdo->prepare("UPDATE appointments SET status_id = 6 WHERE appointment_id = ? AND status_id = 5")
            ->execute([$appointmentId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
