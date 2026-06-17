<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['userId']) && isset($_SESSION['roleId']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . appUrl('/login.php'));
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if ($_SESSION['roleId'] != 1) {
        flashMessage('warning', 'You do not have permission to access that page.');
        header('Location: ' . homeUrlForRole((int)$_SESSION['roleId']));
        exit;
    }
}

function homeUrlForRole(int $roleId): string {
    return match ($roleId) {
        1 => appUrl('/admin/dashboard.php'),
        2 => appUrl('/user/dashboard.php'),
        default => appUrl('/login.php'),
    };
}

function appBasePath(): string {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');

    if (substr($scriptDir, -6) === '/admin') {
        $scriptDir = substr($scriptDir, 0, -6);
    }
    if (substr($scriptDir, -5) === '/user') {
        $scriptDir = substr($scriptDir, 0, -5);
    }
    if (substr($scriptDir, -7) === '/public') {
        $scriptDir = substr($scriptDir, 0, -7);
    }

    return ($scriptDir === '' || $scriptDir === '/') ? '' : $scriptDir;
}

function appUrl(string $path = ''): string {
    $path = '/' . ltrim($path, '/');
    return appBasePath() . $path;
}

function currentUserId(): int {
    return (int)($_SESSION['userId'] ?? 0);
}

function currentUserName(): string {
    return htmlspecialchars($_SESSION['userName'] ?? '');
}

function currentRoleId(): int {
    return (int)($_SESSION['roleId'] ?? 0);
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function logAudit($conn, int $userId, string $action): void {
    $stmt = $conn->prepare(
        "INSERT INTO audit_trail (userId, action, timestamp) VALUES (?, ?, NOW())"
    );
    $stmt->bind_param('is', $userId, $action);
    $stmt->execute();
    $stmt->close();
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void {
    $base = appBasePath();

    if ($base !== '' && ($url === $base || str_starts_with($url, $base . '/'))) {
        // Already fully qualified for this app.
    } elseif (substr($url, 0, 1) === '/') {
        $url = appUrl($url);
    }
    header("Location: $url");
    exit;
}

function flashMessage(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
