<?php

require_once __DIR__ . '/config.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$client_id = $_SESSION['client_id'] ?? '';

if ($client_id === '') {
    http_response_code(401);
    exit('Unauthorized.');
}

$ids = trim($_GET['ids'] ?? '');

if ($ids === '') {
    http_response_code(400);
    exit('No document IDs provided.');
}

/*
 * Convert comma-separated IDs into integers.
 */
$documentIds = array_values(
    array_filter(
        array_map(
            'intval',
            explode(',', $ids)
        ),
        function ($id) {
            return $id > 0;
        }
    )
);

if (!$documentIds) {
    http_response_code(400);
    exit('Invalid document IDs.');
}

/*
 * Verify that all requested documents belong
 * to the currently logged-in client.
 */
$pdo = db();

$placeholders = implode(
    ',',
    array_fill(0, count($documentIds), '?')
);

$sql = "
    SELECT id
    FROM documents
    WHERE client_id = ?
      AND is_deleted = 0
      AND id IN ($placeholders)
";

$params = array_merge(
    [$client_id],
    $documentIds
);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (count($validIds) !== count($documentIds)) {
    http_response_code(403);
    exit('One or more documents are not accessible.');
}

/*
 * Prepare JSON payload for merge API.
 */
$payload = json_encode([
    'doc_ids' => implode(',', $documentIds)
]);

/*
 * Call merge_files API.
 */
$ch = curl_init(
    'http://localhost:5006/agentic_ai/merge_files'
);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
]);

$response = curl_exec($ch);

$curlError = curl_error($ch);
$httpCode = curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

curl_close($ch);

if ($response === false || $curlError !== '') {
    error_log(
        'Merge API CURL error: ' . $curlError
    );

    http_response_code(500);
    exit('Unable to merge documents.');
}

if ($httpCode < 200 || $httpCode >= 300) {

    error_log(
        'Merge API HTTP error: ' .
        $httpCode .
        ' Response: ' .
        $response
    );

    http_response_code(500);
    exit('Merge API failed.');
}

/*
 * Decode merge API response.
 */
$data = json_decode(
    $response,
    true
);

if (
    !is_array($data) ||
    ($data['response'] ?? '') !== 'success' ||
    empty($data['final_doc'])
) {
    error_log(
        'Invalid merge API response: ' .
        $response
    );

    http_response_code(500);
    exit('Invalid merge response.');
}

$finalDoc = $data['final_doc'];

/*
 * Security:
 * Only allow files inside /var/www/html/PrintDocs/
 */
$allowedDirectory = realpath(
    '/var/www/html/PrintDocs'
);

$realFinalDoc = realpath($finalDoc);

if (
    $allowedDirectory === false ||
    $realFinalDoc === false ||
    !is_file($realFinalDoc) ||
    strpos(
        $realFinalDoc,
        $allowedDirectory . DIRECTORY_SEPARATOR
    ) !== 0
) {
    error_log(
        'Invalid final document path: ' .
        $finalDoc
    );

    http_response_code(500);
    exit('Generated file not found.');
}

/*
 * Download filename.
 */
$downloadName = basename($realFinalDoc);

/*
 * Force browser download.
 */
header('Content-Type: application/pdf');
header(
    'Content-Disposition: attachment; filename="' .
    $downloadName .
    '"'
);
header(
    'Content-Length: ' .
    filesize($realFinalDoc)
);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($realFinalDoc);

exit;