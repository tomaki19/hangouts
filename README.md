# Hangout

Simple scheduling hangouts for shared PHP hosting. PHP backend, plain JavaScript frontend, no database, no dependencies.

## Features
- **Admin page** (password protected): create, edit, close and delete hangouts; delete individual responses.
- **Calendar date picker**: tap days to add them as options; tap a day again to add another entry on the same day. Entries are removed with ✕. Add a time or note to each entry, with an emoji picker.
- **Logo per hangout**: in the hangout editor the admin can upload an optional logo (PNG, JPEG, WebP, GIF or SVG; rasters are scaled down in the browser; SVGs are sanitised on the server (UTF-8 only, no DOCTYPE/entities, only plain SVG elements; scripts, event handlers and external references are stripped); stored as `data/logos/<hangout>.dat`). It is shown at the top of that hangout's page and removed together with the hangout.
- **Public hangout link** with an unguessable id: anyone can add a response (Yes / Maybe / No per option). The table shows everyone's answers, with totals per option.
- **Anyone with the link can edit any response** by clicking the name (like Doodle/Framadate). Only the admin can delete responses. Closed hangouts are read-only.
- Mobile friendly: large touch targets, stacked editor rows on small screens.

## Requirements
PHP >= 7.4 (8.x recommended) with the `mbstring` extension, and a web server that can run PHP. Works on nginx and Apache. Hangouts are stored in `data/hangouts.json`.

## Deploy
1. In the hosting control panel, create a domain or subdomain (for example `hangout.example.ch`), choose PHP 8.x, and enable HTTPS (a free certificate).
2. Upload the contents of this folder, including the hidden `.htaccess` files, to the **root** of that domain. The pages use absolute `/api/...` paths, so a sub-folder won't work.
3. On your own computer run `php hash-password.php`, type the admin password when prompted, and copy the printed hash.
4. Copy `config.sample.php` to `config.php` and paste the hash into `admin_password_hash`, **in single quotes** (the hash contains `$`). Only the hash is stored on the server, never the password. `hash-password.php` does not need to be uploaded.
5. Make sure `data/` is writable by PHP (755 usually works; try 775 if saving fails).
6. Open `https://your-domain/admin`. Hangout links look like `https://your-domain/p/<id>`; the admin page has a copy-link button for each hangout. If `/admin` gives the server's own 404, the root `.htaccess` is missing or inactive (see Troubleshooting); `https://your-domain/index.php/admin` works regardless and produces `/index.php/p/<id>` links.

## Configuration (`config.php`)
| Key | Meaning |
|---|---|
| `admin_password_hash` | Required. Create it with `php hash-password.php`. |
| `base` | Optional URL prefix, normally left unset: links follow how the page was opened (`/admin` gives `/p/<id>`, `/index.php/admin` gives `/index.php/p/<id>`). Force a style with `''` or `'/index.php'`. |
| `insecure_cookie` | Optional, `true` only for local development over plain http. Drops the `Secure` flag and `__Host-` prefix of the login cookie. Never use it in production. |
| `data_dir` | Optional. Where hangouts, sessions and login attempts are stored (must be writable). Best placed outside the web root. |

## Security notes
- The password is stored only as an Argon2id/bcrypt hash. Login attempts are rate limited per IP (10 failures per 15 minutes).
- Admin sessions are random tokens. Only their SHA-256 is stored, in `data/sessions.json`, so neither the config nor the data files contain anything that can log someone in or forge a cookie. To log everyone out, delete that file.
- The login cookie is `__Host-session`: always `Secure`, `HttpOnly`, `SameSite=Strict`, path `/`, so it only works over HTTPS (use `insecure_cookie` for local http development) and can't be overwritten from a subdomain or plain http.
- PHP errors are logged, never printed into responses (`display_errors` is forced off).
- Page content is built with `textContent`, API requests must be JSON, and a strict Content-Security-Policy is sent.
- `.htaccess` blocks `data/`, `views/` and `config.php` when it is active, so check that `https://your-domain/data/hangouts.json` does **not** show content, and prefer `data_dir` outside the web root.
- Hangout links are unguessable but not secret from anyone you share them with, and any visitor can change anyone's response. Close a hangout to freeze it.

## Files
| Path | Purpose |
|---|---|
| `index.php` | Front controller: JSON API, auth, storage, serves the pages |
| `views/` | `admin.html`, `hangout.html`, `index.html` |
| `common.js`, `style.css` | Shared frontend code and styles |
| `hash-password.php` | Command-line helper that creates the password hash |
| `config.sample.php` | Template for `config.php` (which is git-ignored) |
| `data/` | Runtime data (git-ignored) |

## Troubleshooting
- **Blank page or 500:** check the host's PHP error log and the PHP version. A message about `admin_password_hash` means `config.php` is missing or the hash isn't a valid Argon2id/bcrypt hash (check the single quotes).
- **`/admin` or `/p/...` gives the server's own 404:** the root `.htaccess` isn't active. Check that the hidden file was uploaded next to `index.php` (many FTP clients hide dotfiles), and that the host allows `.htaccess` rewrites. Meanwhile `/index.php/admin` always works. Test: `https://your-domain/config.php` should return 403 when `.htaccess` is active.
- **Saving a hangout or logging in gives 500:** `data/` is not writable.
- **Changes don't show up:** hard-refresh; browsers cache `style.css` and `common.js`.

## License
MIT, see [LICENSE](LICENSE).
