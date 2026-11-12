<?php
// Copy to config.php and edit. Keep config.php out of version control.
return [
    // Hash of the admin password (never the password itself). Create it with:  php hash-password.php
    // Single quotes matter: the hash contains $ characters.
    'admin_password_hash' => '',
    // Optional. Normally leave unset: links follow how the page was opened (/admin gives /p/<id>, /index.php/admin
    // gives /index.php/p/<id>). Force a style with '' (short URLs) or '/index.php'.
    // 'base' => '',
    // optional: store polls outside the web root (must be writable by PHP), e.g. '/home/you/hangout-data'
    // 'data_dir' => __DIR__ . '/data',
];
