<?php

require_once __DIR__ . '/config.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$client_id = $_SESSION['client_id'] ?? null;

if (!$client_id) {
    http_response_code(401);
    exit('Unauthorized.');
}

/*
|--------------------------------------------------------------------------
| Get document IDs
|--------------------------------------------------------------------------
|
| Supports:
|   download_all.php?ids=1,2,3
|
*/

$idsParam = $_GET['ids'] ?? '';

if (empty($idsParam)) {
    http_response_code(400);
    exit('No documents selected.');
}

$ids = array_filter(
    array_map('intval', explode(',', $idsParam)),
    fn($id) => $id > 0
);

if (empty($ids)) {
    http_response_code(400);
    exit('Invalid document IDs.');
}


/*
|--------------------------------------------------------------------------
| Get documents belonging to logged-in client
|--------------------------------------------------------------------------
*/

$pdo = db();

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$sql = "
    SELECT
        id,
        phone,
        media_url,
        document_type
    FROM documents
    WHERE client_id = ?
      AND id IN ($placeholders)
    ORDER BY id ASC
";

$params = array_merge([$client_id], $ids);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$documents) {
    http_response_code(404);
    exit('No documents found.');
}


/*
|--------------------------------------------------------------------------
| Check ZipArchive
|--------------------------------------------------------------------------
*/

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('PHP ZipArchive extension is not installed.');
}


/*
|--------------------------------------------------------------------------
| Temporary ZIP file
|--------------------------------------------------------------------------
*/

$tempZip = tempnam(sys_get_temp_dir(), 'print_easy_');

if ($tempZip === false) {
    http_response_code(500);
    exit('Unable to create temporary ZIP file.');
}

$zip = new ZipArchive();

if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tempZip);

    http_response_code(500);
    exit('Unable to create ZIP file.');
}


/*
|--------------------------------------------------------------------------
| Download remote files and add them to ZIP
|--------------------------------------------------------------------------
*/

$addedFiles = 0;

foreach ($documents as $document) {

    $documentId = (int)$document['id'];
    $mediaUrl = trim($document['media_url'] ?? '');

    if ($mediaUrl === '') {
        continue;
    }

    /*
     * Only allow HTTP/HTTPS URLs.
     */
    $urlParts = parse_url($mediaUrl);

    if (
        !$urlParts ||
        empty($urlParts['scheme']) ||
        !in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)
    ) {
        continue;
    }


    /*
     * Download remote file into temporary file.
     */
    $tempFile = tempnam(sys_get_temp_dir(), 'print_file_');

    if ($tempFile === false) {
        continue;
    }

    $fp = fopen($tempFile, 'wb');

    if ($fp === false) {
        @unlink($tempFile);
        continue;
    }

    $ch = curl_init($mediaUrl);

    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_TIMEOUT         => 120,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_USERAGENT       => 'PrintEasy Document Downloader',
        CURLOPT_FAILONERROR     => false,
    ]);

    $success = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);
    fclose($fp);


    /*
     * Check download result.
     */
    if (
        $success === false ||
        $httpCode < 200 ||
        $httpCode >= 300 ||
        !file_exists($tempFile) ||
        filesize($tempFile) === 0
    ) {
        @unlink($tempFile);
        continue;
    }


    /*
     * Try to determine original filename from URL.
     */
    $path = parse_url($mediaUrl, PHP_URL_PATH);
    $originalName = $path ? basename($path) : '';

    /*
     * Remove query-string artifacts and sanitize filename.
     */
    $originalName = preg_replace('/[^\w.\- ]+/u', '_', $originalName);

    if (!$originalName || $originalName === '.' || $originalName === '..') {

        $type = strtolower(trim($document['document_type'] ?? ''));

        switch ($type) {
            case 'pdf':
                $extension = 'pdf';
                break;

            case 'word':
            case 'doc':
                $extension = 'doc';
                break;

            case 'docx':
                $extension = 'docx';
                break;

            case 'image':
            case 'jpg':
            case 'jpeg':
                $extension = 'jpg';
                break;

            case 'png':
                $extension = 'png';
                break;

            default:
                $extension = 'file';
        }

        $originalName = 'document_' . $documentId . '.' . $extension;
    }


    /*
     * Prefix ID to avoid duplicate filenames inside ZIP.
     */
    $zipName = $documentId . '_' . $originalName;


    /*
     * Add file to ZIP.
     */
    if ($zip->addFile($tempFile, $zipName)) {
        $addedFiles++;

        /*
         * Keep temp file until ZIP is closed.
         * Store it for cleanup later.
         */
        $tempFiles[] = $tempFile;
    } else {
        @unlink($tempFile);
    }
}


/*
|--------------------------------------------------------------------------
| Close ZIP
|--------------------------------------------------------------------------
*/

$zip->close();


/*
|--------------------------------------------------------------------------
| Cleanup / validate
|--------------------------------------------------------------------------
*/

if ($addedFiles === 0 || !file_exists($tempZip) || filesize($tempZip) === 0) {

    @unlink($tempZip);

    if (!empty($tempFiles)) {
        foreach ($tempFiles as $file) {
            @unlink($file);
        }
    }

    http_response_code(404);
    exit('Unable to download any documents.');
}


/*
|--------------------------------------------------------------------------
| ZIP filename
|--------------------------------------------------------------------------
*/

$phone = $documents[0]['phone'] ?? 'documents';

/*
 * Sanitize phone for filename.
 */
$phone = preg_replace('/[^\w\-]+/', '_', $phone);

$zipFilename =
    'PRINT_EASY_' .
    $phone .
    '_' .
    date('Ymd_His') .
    '.zip';


/*
|--------------------------------------------------------------------------
| Send ZIP to browser
|--------------------------------------------------------------------------
*/

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($tempZip));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tempZip);


/*
|--------------------------------------------------------------------------
| Cleanup
|--------------------------------------------------------------------------
*/

@unlink($tempZip);

if (!empty($tempFiles)) {
    foreach ($tempFiles as $file) {
        @unlink($file);
    }
}

exit;