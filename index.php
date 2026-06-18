<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(homeUrlForRole(currentRoleId()));
}

redirect(appUrl('/login.php'));