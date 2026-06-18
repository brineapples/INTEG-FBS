<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/brand.php';
require_once __DIR__ . '/icons.php';
requireAdmin();
$flash = getFlash();
$currentPage = basename($_SERVER['PHP_SELF']);

function navLink(string $href, string $icon, string $label, string $current): string {
    $active = (basename($href) === $current) ? ' active' : '';
    $url = appUrl($href);
    return "<a href=\"$url\" class=\"$active\"><span class=\"nav-icon\">" . appIcon($icon) . "</span>$label</a>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - ' : '' ?><?= APP_NAME ?> Admin</title>
    <link rel="stylesheet" href="<?= appUrl('/assets/style.css') ?>">
</head>
<body>
<div class="admin-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <a href="<?= appUrl('/admin/dashboard.php') ?>" class="brand-logo-link" aria-label="<?= APP_NAME ?>">
                <?= appLogo('app-logo sidebar-logo') ?>
            </a>
        </div>
        <nav class="sidebar-nav">
            <?= navLink('/admin/dashboard.php', 'dashboard', 'Dashboard', $currentPage) ?>
            <div class="nav-section">Surveys</div>
            <?= navLink('/admin/surveys.php', 'survey', 'All Surveys', $currentPage) ?>
            <?= navLink('/admin/questions.php', 'questions', 'Questions', $currentPage) ?>
            <?= navLink('/admin/responses.php', 'responses', 'Responses', $currentPage) ?>
            <div class="nav-section">Users</div>
            <?= navLink('/admin/users.php', 'users', 'User Accounts', $currentPage) ?>
            <?= navLink('/admin/respondents.php', 'users', 'Respondents', $currentPage) ?>
            <div class="nav-section">System</div>
            <?= navLink('/admin/audit.php', 'audit', 'Audit Trail', $currentPage) ?>
            <?= navLink('/admin/reports.php', 'reports', 'Reports', $currentPage) ?>
        </nav>
        <div class="sidebar-footer">
            <a href="<?= appUrl('/logout.php') ?>"><span class="nav-icon"><?= appIcon('logout') ?></span>Sign out</a>
        </div>
    </aside>

    <div class="main-wrap">
        <div class="topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-toggle" data-sidebar-toggle aria-label="Toggle navigation">
                    <?= appIcon('menu') ?>
                </button>
                <?php if ($currentPage !== 'dashboard.php'): ?>
                    <a href="<?= appUrl('/admin/dashboard.php') ?>" class="btn btn-outline btn-sm topbar-back">
                        <?= appIcon('chevron-left') ?>Back
                    </a>
                <?php endif; ?>
                <span class="topbar-title"><?= isset($pageTitle) ? htmlspecialchars($pageTitle) : 'Dashboard' ?></span>
            </div>
            <div class="topbar-user">
                <div class="topbar-avatar"><?= strtoupper(substr(currentUserName(), 0, 1)) ?></div>
                <?= currentUserName() ?>
            </div>
        </div>
        <div class="page-content">
            <?php if ($flash): ?>
                <div class="flash flash-<?= $flash['type'] ?>">
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>
