<?php

require_once __DIR__ . '/config.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Get client ID ONLY from logged-in session
|--------------------------------------------------------------------------
*/

$client_id = $_SESSION['client_id'] ?? '';

if ($client_id === '') {
    http_response_code(401);
    exit('Unauthorized.');
}


/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$pdo = db();


/*
|--------------------------------------------------------------------------
| Close documents for THIS CLIENT ONLY
|--------------------------------------------------------------------------
*/

$sql = "
    UPDATE documents
    SET is_deleted = 1
    WHERE client_id = ?
      AND is_deleted = 0
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $client_id
]);


/*
|--------------------------------------------------------------------------
| Return result
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,
    'updated' => $stmt->rowCount()
]);

exit;