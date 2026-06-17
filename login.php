<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

if (isLoggedIn()) {
    redirect(homeUrlForRole(currentRoleId()));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userName = trim($_POST['userName'] ?? '');
    $password  = $_POST['password']  ?? '';

    if (empty($userName) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = $conn->prepare(
            "SELECT u.userId, u.userName, u.roleId, u.accountStatus, u.passwordHash
             FROM user u
             WHERE u.userName = ? LIMIT 1"
        );
        $stmt->bind_param('s', $userName);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'Invalid username or password.';
        } elseif ($user['accountStatus'] !== 'active') {
            $error = 'Your account is inactive. Please contact an administrator.';
        } elseif (!verifyPassword($password, $user['passwordHash'])) {
            $error = 'Invalid username or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['userId']   = $user['userId'];
            $_SESSION['userName'] = $user['userName'];
            $_SESSION['roleId']   = $user['roleId'];

            logAudit($conn, $user['userId'], 'LOGIN: ' . $user['userName']);
            flashMessage('success', 'Welcome back, ' . $user['userName'] . '!');
            redirect(homeUrlForRole((int)$user['roleId']));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Survey System</title>
    <link rel="stylesheet" href="<?= appUrl('/assets/style.css') ?>">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <h1>Survey System</h1>
            <p>Feedback &amp; Response Management</p>
        </div>

        <?php if ($error): ?>
            <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
            <div class="flash flash-warning">You do not have permission to access that page.</div>
        <?php endif; ?>

        <form method="POST" action="<?= appUrl('/login.php') ?>" novalidate>
            <div class="form-group">
                <label class="form-label" for="userName">Username</label>
                <input
                    type="text"
                    id="userName"
                    name="userName"
                    class="form-control"
                    value="<?= htmlspecialchars($_POST['userName'] ?? '') ?>"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    </div>
</div>
</body>
</html>
