<?php

require_once __DIR__ . '/config.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

$client_id = $_SESSION['client_id'] ?? null;

if (!$client_id) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Get document IDs
|--------------------------------------------------------------------------
|
| Expected:
| close_documents.php?ids=1,2,3
|
*/

$idsParam = $_GET['ids'] ?? '';

if (empty($idsParam)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'No documents selected.'
    ]);

    exit;
}


$ids = array_filter(
    array_map('intval', explode(',', $idsParam)),
    fn($id) => $id > 0
);


if (empty($ids)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid document IDs.'
    ]);

    exit;
}


try {

    $pdo = db();

    /*
     * Update only documents:
     *
     * 1. Whose ID was supplied
     * 2. Belong to the logged-in client
     *
     * This prevents one client from closing another
     * client's documents.
     */

    $placeholders = implode(
        ',',
        array_fill(0, count($ids), '?')
    );

    $sql = "
        UPDATE documents
        SET is_deleted = 1
        WHERE client_id = ?
          AND id IN ($placeholders)
    ";

    $params = array_merge(
        [$client_id],
        $ids
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'updated' => $stmt->rowCount(),
        'message' => 'Documents closed successfully.'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to close documents.'
    ]);
}