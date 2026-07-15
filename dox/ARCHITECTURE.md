# GM OTP — Architecture

Email-based one-time-password second factor for WordPress login. After core
validates the username/password, GM OTP emails a 6-digit code and requires it
before the login completes.

## File layout

```
gm-otp.php              Bootstrap: plugin header, constants, require_once of includes
includes/core.php       Logging, log viewer UI, and the shared helpers
includes/admin.php      Settings pages (single-site + network), menu, action links, field renderers
includes/login.php      The OTP auth flow + both code-entry UIs
assets/gm-otp-dialog.css  Static styles for the dedicated dialog page
```

Everything is plain functions prefixed `gm_otp_`. No classes, no autoloader —
the main file `require_once`s the three includes in order (core → admin → login).
Constants (`GM_OTP_*`) are defined in the main file before the includes load.

## Constants (main file)

| Constant | Meaning |
|---|---|
| `GM_OTP_VERSION` / `GM_OTP_BUILD_TIME` | shown on the Plugins list + network settings |
| `GM_OTP_PLUGIN_FILE` / `GM_OTP_PLUGIN_URL` | used by action-link filters and asset enqueues (must NOT be `__FILE__` inside an include) |
| `GM_OTP_COOKIE` | name of the HttpOnly cookie holding the pending-session token |
| `GM_OTP_TTL` (300) | code validity, seconds |
| `GM_OTP_RESEND_WAIT` (60) | resend cooldown, seconds |
| `GM_OTP_*_OPTION` | option keys (enabled, max attempts, lockout minutes, logo, exempt roles/users, smtp confirmed, lockout ack) |
| `GM_OTP_LOG_DIR` / `GM_OTP_LOG_FILE` | plugin log under `wp-content/gm-otp-logs/` |

## The login flow (`includes/login.php`)

`gm_otp_maybe_require_otp()` is hooked on `authenticate` at priority 30 (after
core's own username/password check at 20). Decision order:

1. Feature off, or SMTP never confirmed → pass `$user` through untouched.
2. **Wordfence captcha bypass** — if `$user` is a `WP_Error` with code
   `wfls_captcha_verify`, re-verify the credentials directly against core
   (`wp_authenticate_username_password`). If genuinely valid, proceed; else
   leave the rejection in place.
3. Not a `WP_User` (bad creds) → return as-is.
4. Exempt role/user → pass through.
5. XML-RPC / REST (application passwords) → pass through (can't prompt).
6. **Grace token** — a single-use token consumed here lets Wordfence's second
   login phase (a non-AJAX `wp-login.php` POST that follows its admin-ajax
   pre-check) through without demanding a fresh code.
7. **Transport split:**
   - **AJAX login** (`wp_doing_ajax()`, e.g. Wordfence reCAPTCHA posting to
     `admin-ajax.php`) → inline field flow. The code field lives permanently
     (hidden) in the login form and is revealed by JS when the server sets the
     non-secret `gm_otp_show` cookie. On correct code, mints the grace token.
   - **Normal POST login** → `gm_otp_start_dialog_flow()`: generate a code,
     email it, and POST-bounce to a dedicated page (`wp-login.php?action=gm_otp`)
     that shows the code dialog and completes the login itself. This survives
     custom login pages (e.g. ql-registration) that hijack the re-rendered
     login form.

### Post-login redirect

Both transports honour normal WordPress redirect rules. The inline/AJAX flow
returns the `WP_User` and lets core's `wp_signon()` resolve the destination.
The dialog flow completes the login itself, so it calls
`gm_otp_login_redirect()` which mirrors core's `wp-login.php`: it applies the
`login_redirect` filter (used by LoginWP / Peter's Login Redirect, QL Custom
Registration/Redirector and other role/capability redirectors), honours an
explicit `redirect_to`, and otherwise falls back to core's multisite/capability
defaults (e.g. sending a user to their own site's dashboard on multisite). It
never hard-codes `wp-admin`.

### Why two transports

A single approach can't cover both:
- A redirect/dialog page is returned as an AJAX response body by Wordfence and
  never becomes a real page → inline field is required there.
- An inline field is dropped when a custom login plugin redirects the
  re-rendered form → a dedicated dialog page is required there.

`wp_doing_ajax()` distinguishes them at runtime.

## Data storage

- **Pending session** — transient `gm_otp_<token>` = `{user_id, code, last_sent[, remember, redirect_to]}`, keyed by the `GM_OTP_COOKIE` token. Dialog-flow sessions also store `remember`/`redirect_to` so the dialog can finish the login; the presence of `redirect_to` is how the `login_form_login` fallback distinguishes a dialog session from an inline one.
- **Attempts / lockout** — transients `gm_otp_attempts_<user_id>` and `gm_otp_lockout_<user_id>`.
- **Grace token** — transient `gm_otp_grace_<user_id>` matched against the `gm_otp_grace` cookie.
- **Reveal signal** — non-secret `gm_otp_show` cookie the inline JS polls for.
- **Settings** — options via `gm_otp_option()` which routes to `get_site_option` on multisite, `get_option` otherwise.

## Settings (`includes/admin.php`)

- Top-level "GM OTP" admin menu (single entry). "Settings" link on the Plugins list.
- Single-site page is editable; on multisite it's read-only and points at the
  network settings page (the network switch gates the feature network-wide).
- Enabling OTP is gated behind two required checkboxes — "Email delivery
  confirmed" (backed by a real Send Test Email button) and "Lockout risk
  acknowledged". The Save button stays disabled until both are checked.
- Exemptions, login logo, max attempts + lockout duration.

## Logging (`includes/core.php`)

- Logging is **opt-in**: the `gm_otp_logging_enabled` option
  (`GM_OTP_LOGGING_OPTION`, off by default) gates it. `gm_otp_log()` returns
  immediately when it's off. The "Enable logging" checkbox in the Log section
  toggles it (saved via `gm_otp_update_option()`, the write counterpart of
  `gm_otp_option()`); View Log / Clear Log are disabled while it's off.
- `gm_otp_log()` writes to `wp-content/gm-otp-logs/otp.log` (protected by
  `.htaccess`/`index.php`) and mirrors to `error_log()`.
- The settings pages show a log viewer with View/Clear for both the plugin log
  and a best-effort reader of the server's PHP error log (probes common paths
  when `ini_get('error_log')` is empty).
