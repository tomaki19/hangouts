# Hangout – PHP version (for shared hosting such as hoststar.ch)

Same app and same frontend as the Node version, with the backend in `index.php`. PHP >= 7.4, Apache with `mod_rewrite` and `.htaccess` enabled. No database; polls live in `data/polls.json`.

## Deploy
1. In the hosting control panel, choose PHP 8.x for the domain/subdomain and enable HTTPS.
2. Upload the contents of this folder (including the hidden `.htaccess` files) to the **root** of the domain or subdomain (the pages use absolute `/api/...` paths, so a sub-folder won't work; use a subdomain like `hangout.example.ch`).
3. On your own computer run `php hash-password.php` (type the password when prompted) and copy the printed hash. Copy `config.sample.php` to `config.php` and paste the hash into `admin_password_hash`, in single quotes. Only the hash is stored on the server, never the password. Do not upload `hash-password.php` if you don't need it (it refuses to run from the web anyway).
4. Make `data/` writable by PHP (usually 755 already works; try 775 if saving fails).
5. Open `https://your-domain/index.php/admin`. (Poll links look like `https://your-domain/index.php/p/<id>`.)

Short URLs (`/admin`, `/p/<id>`) need rewrite rules. They work on Apache hosts that honour `.htaccess`: set `'base' => ''` in `config.php`. nginx servers ignore `.htaccess`, so keep the default `'/index.php'` there.

Admin sessions are random tokens kept (hashed) in `data/sessions.json`; the config holds no secret that could be used to forge a login. To log everyone out, delete that file.

Better: set `data_dir` in `config.php` to a folder outside the web root.

## Troubleshooting
- Blank page or 500: check the host's PHP error log; confirm PHP version.
- `/admin` or `/p/...` gives 404 (server's own 404 page): rewrites aren't active (nginx, or `.htaccess` not honoured). Use `'base' => '/index.php'` in `config.php` and open `/index.php/admin`.
- Test that `https://your-domain/data/polls.json` returns 404/403. If it shows content, move `data_dir` out of the web root.
- Saving polls fails with 500: `data/` is not writable.
- Login says "Login required" right after logging in: you are on http; cookies are only marked Secure over HTTPS, so use HTTPS.
