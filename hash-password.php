<?php
// Command-line helper: creates the value for 'admin_password_hash' in config.php.
//   php hash-password.php          (prompts, input is not echoed)
//   php hash-password.php 'pw'     (visible in shell history, avoid)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pw = $argv[1] ?? null;
if ($pw === null) {
    fwrite(STDERR, 'Password: ');
    if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    if (DIRECTORY_SEPARATOR === '/') system('stty echo');
    fwrite(STDERR, "\n");
}
if (strlen($pw) < 12) { fwrite(STDERR, "Use at least 12 characters.\n"); exit(1); }

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo password_hash($pw, $algo), "\n";
