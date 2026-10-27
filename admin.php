<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD = 'KkcMw3u3uc7HdHCO';

$pdo = db();
if (!$pdo instanceof PDO) { http_response_code(500); exit('Database connection unavailable.'); }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function uuidV4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 15) | 64);
    $d[8] = chr((ord($d[8]) & 63) | 128);
    $x = bin2hex($d);
    return substr($x,0,8).'-'.substr($x,8,4).'-'.substr($x,12,4).'-'.substr($x,16,4).'-'.substr($x,20,12);
}
function newClientId(PDO $pdo): string {
    $q = $pdo->prepare('SELECT 1 FROM customer_accounts WHERE client_id=? LIMIT 1');
    do { $id = uuidV4(); $q->execute([$id]); } while ($q->fetchColumn());
    return $id;
}
function csrf(): string {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['admin_csrf'];
}
function checkCsrf(): void {
    if (!isset($_POST['csrf_token'], $_SESSION['admin_csrf']) ||
        !hash_equals((string)$_SESSION['admin_csrf'], (string)$_POST['csrf_token'])) {
        throw new RuntimeException('Security token expired. Refresh and try again.');
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['print_easy_admin'], $_SESSION['admin_csrf']);
    header('Location: admin.php'); exit;
}

if (empty($_SESSION['print_easy_admin'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        if (hash_equals(ADMIN_USERNAME, (string)($_POST['username'] ?? '')) &&
            hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['print_easy_admin'] = true;
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            header('Location: admin.php'); exit;
        }
        $error = 'Invalid username or password.';
    }
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Print EaSY Admin Login</title>
    <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f4ee;font-family:Arial,sans-serif;color:#171b19;padding:20px}
    form{width:min(100%,400px);background:#fffefa;border:1px solid #e3e1d9;border-radius:14px;padding:30px;box-shadow:0 10px 30px #0000000c}
    h1{color:#164c39;font-size:24px;margin:0 0 8px}p{color:#68716b;font-size:14px;margin:0 0 22px}
    label{display:block;font-weight:bold;font-size:13px;margin:15px 0 7px}input{width:100%;padding:12px;border:1px solid #d5d8d4;border-radius:7px}
    button{width:100%;padding:12px;margin-top:22px;border:0;border-radius:7px;background:#1f6b4f;color:white;font-weight:bold;cursor:pointer}
    .error{background:#fde8e7;color:#9f2521;padding:10px;border-radius:7px;margin-bottom:14px}
    </style></head><body><form method="post"><h1>PRINT EaSY Admin</h1><p>Sign in to manage customer accounts.</p>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <input type="hidden" name="action" value="login">
    <label>Username</label><input name="username" autocomplete="username" required>
    <label>Password</label><input name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Log In</button></form></body></html>
    <?php exit;
}

$message = ''; $messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'login') {
    try {
        checkCsrf();
        $action = (string)($_POST['action'] ?? '');
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $phone = trim((string)($_POST['phone_number'] ?? ''));
        $numberId = trim((string)($_POST['number_id'] ?? ''));
        $description = trim((string)($_POST['number_description'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['client_password'] ?? '');
        $clientId = trim((string)($_POST['client_id'] ?? ''));

        if (!$first || !$last || !$phone || !$numberId || !$description || !$email || !$password)
            throw new RuntimeException('Please fill in all fields.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        if (!preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) throw new RuntimeException('Enter a valid phone number.');

        if ($action === 'add_customer') {
            $q = $pdo->prepare('SELECT 1 FROM did_numbers WHERE number_id=? LIMIT 1');
            $q->execute([$numberId]);
            if ($q->fetchColumn()) throw new RuntimeException('This number_id already exists.');
            $clientId = newClientId($pdo);

            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('INSERT INTO customer_accounts (client_id,first_name,last_name,phone_number,email,client_password,is_active) VALUES (?,?,?,?,?,?,1)');
                $q->execute([$clientId,$first,$last,$phone,$email,$password]);
                $q = $pdo->prepare('INSERT INTO did_numbers (client_id,number_id,number,number_description,is_deleted) VALUES (?,?,?,?,0)');
                $q->execute([$clientId,$numberId,$phone,$description]);
                $q = $pdo->prepare('INSERT INTO print_settings (client_id,single_side_cost,double_side_cost,is_selected) VALUES (?,2.00,1.50,1)');
                $q->execute([$clientId]);
                $pdo->commit();
                $message = 'Customer added successfully. Client ID: '.$clientId;
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        } elseif ($action === 'update_customer') {
            if (!$clientId) throw new RuntimeException('Missing client_id.');
            $q = $pdo->prepare('SELECT 1 FROM customer_accounts WHERE client_id=? LIMIT 1');
            $q->execute([$clientId]);
            if (!$q->fetchColumn()) throw new RuntimeException('Customer not found.');
            $q = $pdo->prepare('SELECT 1 FROM did_numbers WHERE number_id=? AND client_id<>? LIMIT 1');
            $q->execute([$numberId,$clientId]);
            if ($q->fetchColumn()) throw new RuntimeException('This number_id is already assigned to another customer.');

            $pdo->beginTransaction();
            try {
                $q = $pdo->prepare('UPDATE customer_accounts SET first_name=?,last_name=?,phone_number=?,email=?,client_password=? WHERE client_id=?');
                $q->execute([$first,$last,$phone,$email,$password,$clientId]);

                $q = $pdo->prepare('SELECT id FROM did_numbers WHERE client_id=? ORDER BY id LIMIT 1');
                $q->execute([$clientId]); $didId = $q->fetchColumn();
                if ($didId) {
                    $q = $pdo->prepare('UPDATE did_numbers SET number_id=?,number=?,number_description=?,is_deleted=0 WHERE id=?');
                    $q->execute([$numberId,$phone,$description,$didId]);
                } else {
                    $q = $pdo->prepare('INSERT INTO did_numbers (client_id,number_id,number,number_description,is_deleted) VALUES (?,?,?,?,0)');
                    $q->execute([$clientId,$numberId,$phone,$description]);
                }

                $q = $pdo->prepare('SELECT id FROM print_settings WHERE client_id=? ORDER BY id LIMIT 1');
                $q->execute([$clientId]); $settingsId = $q->fetchColumn();
                if ($settingsId) {
                    // Preserve the customer's existing print rates and selected status.
                } else {
                    $q = $pdo->prepare('INSERT INTO print_settings (client_id,single_side_cost,double_side_cost,is_selected) VALUES (?,2.00,1.50,1)');
                    $q->execute([$clientId]);
                }
                $pdo->commit();
                $message = 'Customer updated successfully.';
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { $message = 'Operation failed: '.$e->getMessage(); $messageType = 'error'; }
}

$editId = trim((string)($_GET['edit'] ?? ''));
$edit = null;
if ($editId !== '') {
    $q = $pdo->prepare("SELECT ca.client_id,ca.first_name,ca.last_name,ca.phone_number,ca.email,ca.client_password,dn.number_id,dn.number_description FROM customer_accounts ca LEFT JOIN did_numbers dn ON dn.client_id=ca.client_id AND dn.is_deleted=0 WHERE ca.client_id=? ORDER BY dn.id LIMIT 1");
    $q->execute([$editId]); $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$edit && !$message) { $message='Customer not found.'; $messageType='error'; }
}
$q = $pdo->query("SELECT ca.client_id,ca.first_name,ca.last_name,ca.phone_number,ca.email,ca.client_password,ca.is_active,ca.created_at,dn.number_id,dn.number_description,ps.single_side_cost,ps.double_side_cost FROM customer_accounts ca LEFT JOIN did_numbers dn ON dn.id=(SELECT MIN(d2.id) FROM did_numbers d2 WHERE d2.client_id=ca.client_id AND d2.is_deleted=0) LEFT JOIN print_settings ps ON ps.id=(SELECT MIN(p2.id) FROM print_settings p2 WHERE p2.client_id=ca.client_id) ORDER BY ca.created_at DESC,ca.id DESC");
$customers = $q->fetchAll(PDO::FETCH_ASSOC);
$form = $edit ?: ['client_id'=>'','first_name'=>'','last_name'=>'','phone_number'=>'','number_id'=>'','number_description'=>'','email'=>'','client_password'=>''];
$showForm = $edit !== null || (($_GET['add'] ?? '') === '1');
$token = csrf();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PRINT EaSY Customer Admin</title>
<style>
:root{--green:#1f6b4f;--dark:#164c39;--pale:#e5f0eb;--ink:#171b19;--cream:#f6f4ee;--white:#fffefa;--border:#e3e1d9}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font-family:Arial,Helvetica,sans-serif}
header{background:var(--dark);color:white;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px}header h1{font-size:21px;margin:0}header a{color:white;text-decoration:none;border:1px solid #ffffff70;border-radius:7px;padding:9px 13px}
main{max-width:1450px;margin:25px auto;padding:0 18px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}h2{margin:0;color:var(--dark);font-size:21px}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:7px;border:0;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;transition:.18s}.primary{background:var(--green);color:white}.primary:hover{background:var(--dark);transform:translateY(-1px)}.light{background:white;color:var(--dark);border:1px solid var(--border)}.light:hover{background:var(--pale)}
.alert{padding:13px 16px;border-radius:8px;margin:0 0 18px;overflow-wrap:anywhere;font-size:14px}.success{background:#e2f4e9;color:#185b37}.failure{background:#fde8e7;color:#9f2521}
.card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:19px;margin-bottom:22px;box-shadow:0 5px 20px #171b1908}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.field input{width:100%;min-width:0;padding:11px;border:1px solid #d5d8d4;border-radius:7px;background:white}.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:8px}table{border-collapse:collapse;width:100%;min-width:1050px;background:white}th,td{padding:12px;text-align:left;border-bottom:1px solid var(--border);font-size:13px;vertical-align:top}th{background:var(--pale);color:var(--dark);white-space:nowrap}tr:last-child td{border-bottom:0}tbody tr:hover{background:#fafbf8}.uuid{font:11px monospace;overflow-wrap:anywhere;max-width:180px}.badge{display:inline-block;padding:4px 8px;border-radius:20px;background:var(--pale);color:var(--dark);font-size:11px;font-weight:bold}.muted{color:#69716c;font-size:12px}.empty{text-align:center;color:#777;padding:28px}
@media(max-width:850px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){header{padding:15px}header h1{font-size:17px}main{padding:0 12px;margin:18px auto}.card{padding:13px}.grid{grid-template-columns:1fr}}
</style></head><body>
<header><h1>PRINT EaSY — Customer Admin</h1><a href="admin.php?logout=1">Log Out</a></header>
<main>
<?php if ($message !== ''): ?><div class="alert <?= $messageType==='error'?'failure':'success' ?>"><?= h($message) ?></div><?php endif; ?>
<div class="topbar"><h2>Customer Accounts <span class="badge"><?= count($customers) ?> total</span></h2><a class="btn primary" href="admin.php?add=1">＋ Add Customer</a></div>

<?php if ($showForm): ?>
<section class="card"><h2 style="margin-bottom:18px"><?= $edit ? 'Update Customer' : 'Add Customer' ?></h2>
<form method="post" action="admin.php<?= $edit ? '?edit='.rawurlencode((string)$edit['client_id']) : '?add=1' ?>">
<input type="hidden" name="csrf_token" value="<?= h($token) ?>"><input type="hidden" name="action" value="<?= $edit?'update_customer':'add_customer' ?>">
<?php if ($edit): ?><input type="hidden" name="client_id" value="<?= h($form['client_id']) ?>"><?php endif; ?>
<div class="grid">
<div class="field"><label for="first_name">First Name *</label><input id="first_name" name="first_name" required maxlength="100" value="<?= h($form['first_name']) ?>"></div>
<div class="field"><label for="last_name">Last Name *</label><input id="last_name" name="last_name" required maxlength="100" value="<?= h($form['last_name']) ?>"></div>
<div class="field"><label for="phone_number">Phone Number *</label><input id="phone_number" name="phone_number" required maxlength="25" inputmode="tel" value="<?= h($form['phone_number']) ?>"></div>
<div class="field"><label for="number_id">Number ID *</label><input id="number_id" name="number_id" required maxlength="36" value="<?= h($form['number_id']) ?>" placeholder="UUID"></div>
<div class="field"><label for="number_description">Number Description *</label><input id="number_description" name="number_description" required maxlength="255" value="<?= h($form['number_description']) ?>"></div>
<div class="field"><label for="email">Email *</label><input id="email" name="email" type="email" required maxlength="255" value="<?= h($form['email']) ?>"></div>
<div class="field"><label for="client_password">Client Password *</label><input id="client_password" name="client_password" type="text" required maxlength="255" autocomplete="off" value="<?= h($form['client_password']) ?>"></div>
</div><div class="actions"><button class="btn primary" type="submit"><?= $edit?'Save Changes':'Submit Customer' ?></button><a class="btn light" href="admin.php">Cancel</a></div>
<?php if (!$edit): ?><p class="muted">client_id is generated automatically and checked for duplicates. New customers receive default print rates: ₹2.00 single-sided and ₹1.50 double-sided.</p><?php endif; ?>
</form></section>
<?php endif; ?>

<section class="card"><div class="table-wrap"><table><thead><tr><th>Customer</th><th>Phone</th><th>Email</th><th>Number ID</th><th>Number Description</th><th>Client ID</th><th>Print Rates</th><th>Created At</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$customers): ?><tr><td colspan="10" class="empty">No customer accounts found.</td></tr><?php else: foreach ($customers as $c): ?>
<tr><td><strong><?= h(trim(($c['first_name']??'').' '.($c['last_name']??''))) ?></strong><br><span class="muted">Password: <?= h($c['client_password']??'') ?></span></td>
<td><?= h($c['phone_number']??'') ?></td><td><?= h($c['email']??'') ?></td><td class="uuid"><?= h($c['number_id']??'—') ?></td><td><?= h($c['number_description']??'—') ?></td><td class="uuid"><?= h($c['client_id']) ?></td>
<td>Single: ₹<?= h(number_format((float)($c['single_side_cost']??2),2)) ?><br>Double: ₹<?= h(number_format((float)($c['double_side_cost']??1.5),2)) ?></td><td><?= h($c['created_at']??'—') ?></td><td><span class="badge"><?= (int)$c['is_active']===1?'Active':'Inactive' ?></span></td><td><a class="btn light" href="admin.php?edit=<?= rawurlencode((string)$c['client_id']) ?>">Edit</a></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></section>
</main></body></html>
