<?php
return [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => (getenv('DB_PASS') === false) ? '' : getenv('DB_PASS'),
    'db_name' => getenv('DB_NAME') ?: 'survey',
];
