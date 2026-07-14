# CLAUDE.md — working notes for AI assistants / contributors

Guidance for anyone (human or AI) editing this plugin. Read `ARCHITECTURE.md`
first for how the code is organised.

## Conventions

- Plain procedural PHP, WordPress coding standards. All functions prefixed `gm_otp_`.
- Keep related functions in the same include (`core` / `admin` / `login`). The
  main `gm-otp.php` only holds the header, constants and the `require_once`s.
- Constants are defined in `gm-otp.php` **before** the includes load. Inside an
  include, never use `__FILE__` to identify the plugin — use `GM_OTP_PLUGIN_FILE`
  / `GM_OTP_PLUGIN_URL` (otherwise `plugin_basename()`/asset URLs point at the
  include, not the plugin).
- All files must be UTF-8 **without BOM** — a BOM before `<?php` emits output
  and breaks `setcookie()`/headers on the login flow.
- Superglobals are read through `gm_otp_input()` (unslash + sanitize) to satisfy
  the security sniff, even for values only used in logs/routing.

## Versioning / release checklist

On any change:
1. Bump `Version:` in the header **and** `GM_OTP_VERSION`, update `GM_OTP_BUILD_TIME`.
2. Update `Stable tag` and add a `== Changelog ==` entry in `readme.txt`.
3. Lint every touched file: `php -l <file>`.
4. Run Plugin Check's PHPCS ruleset — target **0 errors** (the `error_log()` and
   "nonce"/`is_writable` warnings are expected and annotated).
5. Run the test harness (see below).
6. Rebuild the distribution zip (single `gm-otp/` folder incl. `includes/` +
   `assets/`).

## Testing

The auth decision logic is covered by a stub harness that runs the real
`includes/` code under plain PHP CLI (no WordPress). It verifies:

- multisite vs single-site option routing,
- Wordfence `wfls_captcha_verify` bypass (valid creds proceed, invalid stay rejected),
- feature gating (disabled / SMTP-unconfirmed / exempt → passthrough),
- AJAX inline flow (demand / correct+grace / wrong),
- grace-token passthrough (Wordfence phase 2),
- non-AJAX → dialog-page bounce.

Run: `php tests/run.php` and `php tests/run-dialog.php` (bootstrap stubs WP
functions and loads the real plugin main file). All must pass before release.

## Environment gotchas (learned the hard way)

- **Wordfence Login Security reCAPTCHA** does a two-phase login: an
  `admin-ajax.php` pre-check, then a real `wp-login.php` POST. Both run the
  `authenticate` filter. The grace token stops the second phase demanding a new
  code (which would overwrite the one just used → "wrong code" loop).
- **ql-registration / custom login pages** redirect the re-rendered login form
  to a custom `/sign-in/` page, dropping an inline field. The dialog-page
  transport (non-AJAX branch) exists for exactly this.
- **wp.flash-jet.com** uploader double-nests any zip that contains a wrapping
  folder. Deploy there by extracting the normal zip via File Manager, or use a
  flat zip. This is a server quirk, not something the plugin can detect.
- Local's bundled PHP CLI has **mbstring off**; the test bootstrap stubs
  `mb_substr`/`mb_strlen`. Real WP servers have it.

## Build (Windows / PowerShell)

- Lint uses Local's PHP binary under `lightning-services/php-*/bin/win64/php.exe`.
- Zips are written with `System.IO.Compression.ZipArchive` using forward-slash
  entry names (PowerShell's `Compress-Archive` writes Windows backslashes that
  break extraction on Linux hosts).
- Do not ship `dox/` or `tests/` in the distributed plugin zip.
