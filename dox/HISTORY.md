# GM OTP — Development history

A narrative of how the plugin evolved. For the precise per-release list see the
`== Changelog ==` section in `readme.txt`.

## The short story

GM OTP started as a single-file plugin that emailed a 6-digit code after the
password. The hard part was never the code — it was making the second factor
work across three very different login environments without locking anyone out:

1. a clean WordPress site,
2. a site behind **Wordfence Login Security** (reCAPTCHA + AJAX login),
3. a multisite whose login is a **custom page** (ql-registration `/sign-in/`).

## Milestones

- **1.0 – 2.x** — Core OTP: generate, email, verify, lockout after N wrong
  attempts, resend with cooldown. Added an enable/disable switch, configurable
  attempts + lockout, multisite (one network-wide switch), role/user
  exemptions, custom login logo, and an in-admin log viewer.

- **2.0 / 2.7** — Moved code entry to its own screen, then to an
  auto-submitting POST "bounce" so plugins that hijack GET requests to
  `wp-login.php` couldn't strip the action.

- **3.0 – 3.6** — WordPress.org readiness: license header, text domain,
  Plugin Check to 0 errors, `readme.txt`. Added an SMTP "email delivery
  confirmed" gate (with a real Send Test Email button) so OTP can't be enabled
  without working mail. Submitted to the plugin directory.

- **3.6** — **Wordfence captcha bypass.** Wordfence's own `wfls_captcha_verify`
  rejected logins before GM OTP ever saw a valid user. Fix: when that specific
  error appears, re-verify the credentials against core and proceed if genuine.

- **3.7 – 3.8** — Discovered Wordfence submits login via `admin-ajax.php`, which
  the raw-HTML bounce page couldn't survive. Rewrote to a **same-page inline
  field**. Then found Wordfence's **two-phase** login (ajax pre-check → real
  `wp-login.php` POST) caused a "wrong code" loop; fixed with a single-use
  **grace token**. Made the field always-present-but-hidden so the AJAX flow
  can reveal it via a `gm_otp_show` cookie.

- **3.9 – 3.13** — UI/admin polish: side-by-side settings layout; top-level
  admin menu; Plugins-list "Settings" link; Enter-to-submit in the code field;
  a required "lockout risk acknowledged" checkbox; Save disabled until both
  confirmation boxes are checked; Clear-log buttons for both the plugin log and
  the PHP error log.

- **3.14** — **Automatic transport fallback.** Normal (non-AJAX) logins now
  redirect to a dedicated dialog page, while AJAX logins keep the inline field.
  This is what makes the ql-registration custom-login multisite work. XML-RPC /
  REST logins are skipped.

- **3.15** — Refactor: split the single file into `includes/core.php`,
  `includes/admin.php`, `includes/login.php`. Behaviour verified by an
  automated stub test of the captcha/multisite/AJAX decision logic.

- **3.16** — Moved the dialog page's static CSS to `assets/gm-otp-dialog.css`.

## Distribution

- Canonical package: a single `gm-otp/` folder (with `includes/` + `assets/`).
- `wp.flash-jet.com` needs the zip extracted via File Manager because its
  uploader double-nests wrapping folders — a server quirk, documented in
  `CLAUDE.md`.
