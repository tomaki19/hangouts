<?php
// Copy to config.php and edit. Keep config.php out of version control.
return [
    // Hash of the admin password (never the password itself). Create it with:  php hash-password.php
    // Single quotes matter: the hash contains $ characters.
    'admin_password_hash' => '',
    // URL prefix. '/index.php' works everywhere (nginx, no rewrite rules): /index.php/admin, /index.php/p/<id>.
    // Use '' for the short URLs /admin and /p/<id> if the server applies the rewrite rules in .htaccess (Apache).
    'base' => '/index.php',
    // optional: store polls outside the web root (must be writable by PHP), e.g. '/home/you/hangout-data'
    // 'data_dir' => __DIR__ . '/data',
];
