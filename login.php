<?php
require_once __DIR__ . '/auth.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Portal - Login</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="login-body">

<div class="login-shell">
    <div class="login-card">

        <div class="brand-mark">🖨</div>

        <h1>PRINT EaSY</h1>
        <p class="login-subtitle">Sign in to manage documents</p>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">

            <label for="phone_number">Phone Number</label>

            <input
                id="phone_number"
                name="phone_number"
                type="text"
                value="<?= e($_POST['phone_number'] ?? '') ?>"
                required
                autofocus
            >

            <label for="password">Password</label>

            <input
                id="password"
                name="password"
                type="password"
                required
            >

            <button class="btn btn-primary btn-large" type="submit">
                Login
            </button>

        </form>

    </div>
</div>

</body>
</html>