<?php
require_once __DIR__ . '/config.php';
require_login();

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$clientId = $_SESSION['client_id'] ?? '';

if (!$documentId || $clientId === '') {
    http_response_code(400);
    exit('Invalid request.');
}

$pdo = db();

$stmt = $pdo->prepare("\n    SELECT id, media_url, document_type\n    FROM documents\n    WHERE id = :id\n      AND client_id = :client_id\n    LIMIT 1\n");
$stmt->execute([
    ':id' => $documentId,
    ':client_id' => $clientId
]);

$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$mediaUrl = trim((string)($document['media_url'] ?? ''));

if ($mediaUrl === '' || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid document URL.');
}

$parts = parse_url($mediaUrl);
$scheme = strtolower($parts['scheme'] ?? '');

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('Unsupported document URL.');
}

// Download the file from the document URL on the server,
// then stream it to the browser. The browser saves it locally.
$ch = curl_init($mediaUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'PRINT-EASY-Document-Download/1.0',
]);

$fileContents = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($fileContents === false || $httpCode < 200 || $httpCode >= 300) {
    error_log(
        'Document download failed. ID=' . $documentId .
        ' HTTP=' . $httpCode .
        ' Error=' . $curlError
    );

    http_response_code(502);
    exit('Unable to retrieve document.');
}

// Prefer the filename from the URL path.
$pathName = basename(parse_url($mediaUrl, PHP_URL_PATH) ?: '');
$fileName = urldecode($pathName);

if ($fileName === '' || $fileName === '.' || $fileName === '/') {
    $type = strtolower(trim((string)($document['document_type'] ?? '')));
    $extensionMap = [
        'pdf' => 'pdf',
        'jpg' => 'jpg',
        'jpeg' => 'jpeg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
        'word' => 'docx',
        'doc' => 'doc',
        'docx' => 'docx',
    ];

    $extension = $extensionMap[$type] ?? 'bin';
    $fileName = 'document_' . $documentId . '.' . $extension;
}

// Make the filename safe for Windows.
$fileName = preg_replace('/[<>:"\\\/|?*\x00-\x1F]/', '_', $fileName);
$fileName = rtrim($fileName, ". ");

if ($fileName === '') {
    $fileName = 'document_' . $documentId;
}

if (!$contentType || stripos($contentType, 'text/html') !== false) {
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $contentType = $mimeMap[$extension] ?? 'application/octet-stream';
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($fileContents));
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, "\\\"") . '"');
header('X-File-Name: ' . rawurlencode($fileName));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

if (ob_get_level()) {
    ob_end_clean();
}

echo $fileContents;