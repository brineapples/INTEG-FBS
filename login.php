<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/icons.php';

if (isLoggedIn()) {
    redirect(homeUrlForRole(currentRoleId()));
}

$error = '';
$mode = (($_GET['mode'] ?? '') === 'register') ? 'register' : 'login';

function publicUserRoleId(mysqli $conn): int {
    $result = $conn->query("SELECT roleId FROM roles WHERE roleName = 'User' LIMIT 1");
    $row = $result ? $result->fetch_assoc() : null;
    return (int)($row['roleId'] ?? 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    $userName = trim($_POST['userName'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($action === 'register') {
        $mode = 'register';
        $confirmPassword = $_POST['confirmPassword'] ?? '';

        if ($userName === '') {
            $error = 'Please choose a username.';
        } elseif (strlen($userName) > 100) {
            $error = 'Username must be 100 characters or fewer.';
        } elseif ($password === '') {
            $error = 'Please choose a password.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            $check = $conn->prepare("SELECT userId FROM user WHERE userName = ? LIMIT 1");
            $check->bind_param('s', $userName);
            $check->execute();
            $existingUser = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existingUser) {
                $error = 'That username is already taken.';
            } else {
                $roleId = publicUserRoleId($conn);
                $passwordHash = hashPassword($password);
                $accountStatus = 'active';
                $stmt = $conn->prepare(
                    "INSERT INTO user (roleId, userName, accountStatus, passwordHash)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param('isss', $roleId, $userName, $accountStatus, $passwordHash);

                if ($stmt->execute()) {
                    $newUserId = (int)$stmt->insert_id;
                    $stmt->close();

                    session_regenerate_id(true);
                    $_SESSION['userId'] = $newUserId;
                    $_SESSION['userName'] = $userName;
                    $_SESSION['roleId'] = $roleId;

                    logAudit($conn, $newUserId, 'SELF REGISTER: ' . $userName);
                    flashMessage('success', 'Account created successfully.');
                    redirect(homeUrlForRole($roleId));
                }

                $error = 'Unable to create your account right now.';
                $stmt->close();
            }
        }
    } else {
        $mode = 'login';

        if ($userName === '' || $password === '') {
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
                $_SESSION['userId'] = $user['userId'];
                $_SESSION['userName'] = $user['userName'];
                $_SESSION['roleId'] = $user['roleId'];

                logAudit($conn, $user['userId'], 'LOGIN: ' . $user['userName']);
                flashMessage('success', 'Welcome back, ' . $user['userName'] . '!');
                redirect(homeUrlForRole((int)$user['roleId']));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= appUrl('/assets/style.css') ?>">
</head>
<body>
<div class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <?= appLogo('app-logo login-logo-img') ?>
        </div>

        <div class="auth-switch">
            <a href="<?= appUrl('/login.php') ?>" class="<?= $mode === 'login' ? 'active' : '' ?>">Sign In</a>
            <a href="<?= appUrl('/login.php?mode=register') ?>" class="<?= $mode === 'register' ? 'active' : '' ?>">Create Account</a>
        </div>

        <?php if ($error): ?>
            <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
            <div class="flash flash-warning">You do not have permission to access that page.</div>
        <?php endif; ?>

        <?php if ($mode === 'register'): ?>
            <p class="auth-caption">Create a standard user account and start answering surveys immediately.</p>
            <form method="POST" action="<?= appUrl('/login.php?mode=register') ?>" novalidate>
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label class="form-label" for="register-userName">Username</label>
                    <input
                        type="text"
                        id="register-userName"
                        name="userName"
                        class="form-control"
                        value="<?= htmlspecialchars($_POST['userName'] ?? '') ?>"
                        autocomplete="username"
                        required
                        autofocus
                    >
                </div>
                <div class="form-group">
                    <label class="form-label" for="register-password">Password</label>
                    <input
                        type="password"
                        id="register-password"
                        name="password"
                        class="form-control"
                        autocomplete="new-password"
                        minlength="6"
                        required
                    >
                    <div class="form-hint">Minimum 6 characters.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirmPassword">Confirm Password</label>
                    <input
                        type="password"
                        id="confirmPassword"
                        name="confirmPassword"
                        class="form-control"
                        autocomplete="new-password"
                        minlength="6"
                        required
                    >
                </div>
                <button type="submit" class="btn btn-primary"><?= appIcon('plus') ?>Create Account</button>
            </form>
        <?php else: ?>
            <p class="auth-caption">Sign in with an existing account to manage surveys or submit responses.</p>
            <form method="POST" action="<?= appUrl('/login.php') ?>" novalidate>
                <input type="hidden" name="action" value="login">
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
                <button type="submit" class="btn btn-primary"><?= appIcon('login') ?>Sign In</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
