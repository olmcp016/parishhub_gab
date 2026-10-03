<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SERVER_NAME'] = 'localhost';
$_POST = [
    'csrf_token' => 'dummy',
    'ajax' => '1',
    'service_id' => '4', // Assuming Funeral is 4
    'appointment_date' => '2026-10-15',
    'appointment_time' => '13:00',
    'date_of_death' => '2026-10-01',
    'pss_claim' => 'non_pss',
    'draft_mode' => '0',
];
$_SESSION = [
    'user_id' => 1,
    'role' => 'parishioner',
    'csrf_token' => 'dummy',
];
$_FILES = [
    'req_doc_0' => [
        'name' => 'death_cert.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => '/tmp/dummy',
        'error' => UPLOAD_ERR_NO_FILE,
        'size' => 0
    ],
    // Let's also simulate an empty extra documents field
    'documents' => [
        'name' => [''],
        'type' => [''],
        'tmp_name' => [''],
        'error' => [UPLOAD_ERR_NO_FILE],
        'size' => [0]
    ]
];

ob_start();
try {
    require 'parishioner/book.php';
} catch (Throwable $e) {
    echo "Uncaught Throwable: " . $e->getMessage() . "\n";
}
$out = ob_get_clean();
echo "OUTPUT:\n" . $out . "\n";
