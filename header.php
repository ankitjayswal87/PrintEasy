<?php
require_once __DIR__ . '/config.php';
require_login();

$client_id = $_SESSION['client_id'] ?? '';

$customerName = '';

if ($client_id !== '') {
    $stmtCustomer = db()->prepare("
        SELECT first_name, last_name
        FROM customer_accounts
        WHERE client_id = :client_id
        LIMIT 1
    ");

    $stmtCustomer->execute([
        ':client_id' => $client_id
    ]);

    $customer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);

    if ($customer) {
        $customerName = trim(
            ($customer['first_name'] ?? '') . ' ' .
            ($customer['last_name'] ?? '')
        );
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title ?? 'Client Portal') ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<header class="topbar">

    <a class="brand" href="dashboard.php">
        <span class="brand-mark large">🖨</span>
        <span>PRINT EaSY</span>
    </a>

    <nav>

        <?php if ($customerName !== ''): ?>
            <span class="customer-name">
                <?= e($customerName) ?>
            </span>
        <?php endif; ?>

        <a
            href="dashboard.php"
            class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>"
        >
            Dashboard
        </a>

        <a
            href="documents.php"
            class="<?= ($active ?? '') === 'documents' ? 'active' : '' ?>"
        >
            Documents
        </a>

        <a
            href="settings.php"
            class="<?= ($active ?? '') === 'settings' ? 'active' : '' ?>"
        >
            Settings
        </a>

        <a href="logout.php" class="logout-link">
            Logout
        </a>

    </nav>

</header>

<main class="container">