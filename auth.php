<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phone_number = trim($_POST['phone_number'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($phone_number === '' || $password === '') {
        $error = 'Please enter phone number and password.';
    } else {

        $stmt = db()->prepare("
            SELECT
                id,
                client_id,
                first_name,
                last_name,
                phone_number,
                email,
                client_password,
                is_active
            FROM customer_accounts
            WHERE phone_number = ?
            LIMIT 1
        ");

        $stmt->execute([$phone_number]);

        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $customer &&
            (int)$customer['is_active'] === 1 &&
            hash_equals($customer['client_password'], $password)
        ) {

            session_regenerate_id(true);

            $_SESSION['logged_in'] = true;

            // Important: client_id will be used throughout the portal
            $_SESSION['client_id'] = $customer['client_id'];

            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['first_name'] = $customer['first_name'];
            $_SESSION['last_name'] = $customer['last_name'];
            $_SESSION['phone_number'] = $customer['phone_number'];
            $_SESSION['email'] = $customer['email'];

            header('Location: dashboard.php');
            exit;
        }

        $error = 'Invalid phone number or password.';
    }
}