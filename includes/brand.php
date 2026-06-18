<?php
const APP_NAME = 'PULSE';
const APP_LOGO_PATH = '/assets/pulse-logo.png';

function appLogo(string $class = 'app-logo', string $alt = APP_NAME): string {
    return '<img class="' .
        htmlspecialchars($class, ENT_QUOTES, 'UTF-8') .
        '" src="' .
        htmlspecialchars(appUrl(APP_LOGO_PATH), ENT_QUOTES, 'UTF-8') .
        '" alt="' .
        htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') .
        '">';
}
