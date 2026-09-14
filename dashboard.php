<?php
require_once __DIR__ . '/config.php';
require_login();

$page_title = 'Dashboard';
$active = 'dashboard';

$pdo = db();

// Get logged-in client's ID from session
$client_id = $_SESSION['client_id'];

// Total documents for this client
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM documents
    WHERE client_id = ?
");
$stmt->execute([$client_id]);

$totalDocuments = (int)$stmt->fetchColumn();

// Documents created today for this client
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM documents
    WHERE client_id = ?
      AND created_at >= CURDATE()
      AND created_at < CURDATE() + INTERVAL 1 DAY
");
$stmt->execute([$client_id]);

$todayDocuments = (int)$stmt->fetchColumn();

require __DIR__ . '/header.php';
?>

<div class="page-heading">
    <div>
        <h1>Dashboard</h1>
        <p>Overview of your document activity.</p>
    </div>

    <a class="btn btn-primary" href="documents.php">
        View Documents
    </a>
</div>

<section class="stats-grid">

    <div class="stat-card">
        <div class="stat-icon">▣</div>

        <div>
            <div class="stat-label">
                Total Documents
            </div>

            <div class="stat-value">
                <?= $totalDocuments ?>
            </div>
        </div>
    </div>

    <div class="stat-card accent-card">
        <div class="stat-icon">◷</div>

        <div>
            <div class="stat-label">
                Documents Today
            </div>

            <div class="stat-value">
                <?= $todayDocuments ?>
            </div>
        </div>
    </div>

</section>

<section class="content-card welcome-card">
    <div>
        <h2>Document Management</h2>

        <p>
            View uploaded PDFs, check page counts and costs,
            filter by phone or date, and print documents.
        </p>
    </div>

    <a class="btn btn-outline" href="documents.php">
        Open Documents →
    </a>
</section>

<?php require __DIR__ . '/footer.php'; ?>