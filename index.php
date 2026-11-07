<?php

session_start();

$errors = [
    'login' => $_SESSION['login_error'] ?? '',
    'register' => $_SESSION['register_error'] ?? ''
];
$activeForm = $_SESSION['active_form'] ?? 'login';

session_unset();

function showError ($error) {
return !empty($error) ? "<p class='error-message'>$error</p>" : '';
}

function isActiveForm($formName, $activeForm) {
return $formName === $activeForm ? 'active': '';
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Login & Register</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="login-page">

<div class="form-box <?= isActiveForm('login', $activeForm); ?>" id="login-form">

    <form action="login.php" method="POST">
        <h1>Login</h1>
        <h2>Cuisene Palembang</h2>
        <?= showError($errors['login']); ?>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="login">Login</button>
        <p>Dont have an account? <a href="#" onclick="showForm('register-form')">Register</a></p>
    </form>

</div>

<div class="form-box <?= isActiveForm('register', $activeForm); ?>" id="register-form">

    <form action="login.php" method="POST">
        <h1>Register</h1>
        <h2>Cuisene Palembang</h2>
        <?= showError($errors['register']); ?>
        <input type="text" name="name" placeholder="Nama" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="register">Register</button>
        <p>Already have an account? <a href="#" onclick="showForm('login-form')">Login</a></p>
    </form>

</div>

<script src="script.js"></script>
</body>
</html>