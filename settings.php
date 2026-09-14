<?php
require_once __DIR__ . '/config.php';
require_login();

$page_title = 'Print Settings';
$active = 'settings';

$pdo = db();
$client_id = $_SESSION['client_id'];
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $single_cost = filter_input(INPUT_POST, 'single_side_cost', FILTER_VALIDATE_FLOAT);
        $double_cost = filter_input(INPUT_POST, 'double_side_cost', FILTER_VALIDATE_FLOAT);

        if ($single_cost === false || $double_cost === false || $single_cost < 0 || $double_cost < 0) {
            $error = 'Please enter valid non-negative cost amounts.';
        } else {
            // Check if dynamic setting record already exists for this client
            $stmt = $pdo->prepare("SELECT id FROM print_settings WHERE client_id = :client_id");
            $stmt->execute([':client_id' => $client_id]);
            $exists = $stmt->fetch();

            if ($exists) {
                $stmt = $pdo->prepare("UPDATE print_settings SET single_side_cost = :single, double_side_cost = :double WHERE client_id = :client_id");
                $stmt->execute([':single' => $single_cost, ':double' => $double_cost, ':client_id' => $client_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO print_settings (client_id, single_side_cost, double_side_cost, is_selected) VALUES (:client_id, :single, :double, 1)");
                $stmt->execute([':client_id' => $client_id, ':single' => $single_cost, ':double' => $double_cost]);
            }
            $message = 'Settings saved successfully.';
        }
    } elseif ($action === 'apply') {
        // Toggle/Ensure selection for client settings
        $stmt = $pdo->prepare("UPDATE print_settings SET is_selected = 1 WHERE client_id = :client_id");
        $stmt->execute([':client_id' => $client_id]);
        $message = 'Settings applied as active configuration.';
    }
}

// Fetch current dynamic settings
$stmt = $pdo->prepare("SELECT * FROM print_settings WHERE client_id = :client_id LIMIT 1");
$stmt->execute([':client_id' => $client_id]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback default values
$single_side_cost = $settings['single_side_cost'] ?? 2.00;
$double_side_cost = $settings['double_side_cost'] ?? 1.50;
$is_selected = $settings['is_selected'] ?? 0;

require __DIR__ . '/header.php';
?>

<div class="page-heading">
    <div>
        <h1>Print Settings</h1>
        <p>Configure dynamic print page costs</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="padding: 10px; background: #d4edda; color: #155724; border-radius: 6px; margin-bottom: 15px;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" style="padding: 10px; background: #f8d7da; color: #721c24; border-radius: 6px; margin-bottom: 15px;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<section class="content-card">
    <form method="post" class="filter-form" style="display: flex; flex-direction: column; gap: 15px; max-width: 400px;">
        <input type="hidden" name="action" value="save">
        
        <div class="field">
            <label for="single_side_cost">Single Side Cost (₹)</label>
            <input id="single_side_cost" name="single_side_cost" type="number" step="0.01" min="0" value="<?= e(number_format((float)$single_side_cost, 2, '.', '')) ?>" required>
        </div>

        <div class="field">
            <label for="double_side_cost">Two Side Cost (₹)</label>
            <input id="double_side_cost" name="double_side_cost" type="number" step="0.01" min="0" value="<?= e(number_format((float)$double_side_cost, 2, '.', '')) ?>" required>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 10px;">
            <button class="btn btn-primary" type="submit">Save Settings</button>
        </div>
    </form>

    <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border);">

    <form method="post">
        <input type="hidden" name="action" value="apply">
        <div style="display: flex; align-items: center; gap: 15px;">
            <button class="btn btn-light" type="submit" style="background: var(--dark-slate); color: white;">
                <?= $is_selected ? '✔ Applied' : 'Apply Settings' ?>
            </button>
            <span style="font-size: 13px; color: var(--muted);">
                Status: <?= $is_selected ? '<strong>Active</strong>' : 'Inactive' ?>
            </span>
        </div>
    </form>
</section>

<?php require __DIR__ . '/footer.php'; ?>