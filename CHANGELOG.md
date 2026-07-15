# Changelog

Notable changes. The authoritative, full list lives in
[`readme.txt`](readme.txt) (WordPress.org format); highlights below.

## 3.18.2
- Fixed the Log section posting to the old `options-general.php` URL (page moved
  to a top-level menu in 3.11.2), which stopped the logging toggle saving.

## 3.18.0 – 3.18.1
- Opt-in logging: an "Enable logging" checkbox in the Log section (off by
  default); View/Clear Log disabled while logging is off.

## 3.17.0 – 3.17.1
- Post-login redirect on the dialog flow now mirrors core (applies the
  `login_redirect` filter used by LoginWP / QL Redirector, plus multisite
  fallbacks) instead of always going to wp-admin.
- Fixed garbled em-dashes/ellipses in the admin UI (encoding slip from the
  3.15.0 split); lockout-risk text rewritten as short bullets.

## 3.16.0
- Moved the dialog page's static CSS to `assets/gm-otp-dialog.css`.
- Added developer docs (`dox/`) and a stub test suite (`tests/`) plus CI.

## 3.15.0
- Split the single plugin file into `includes/core.php`, `includes/admin.php`,
  `includes/login.php`. Behaviour verified by automated tests.

## 3.14.0
- Automatic transport fallback: normal logins use a dedicated code dialog page;
  AJAX logins (Wordfence reCAPTCHA) keep the inline field. XML-RPC/REST skipped.

## 3.10.0 – 3.13.1
- Lockout-risk acknowledgement gate; Save disabled until both confirmation
  boxes are checked; top-level admin menu; Plugins-list Settings link;
  Enter-to-submit; Clear-log buttons for the plugin and PHP error logs.

## 3.8.x
- Same-page inline field; Wordfence reCAPTCHA/AJAX compatibility and a
  single-use grace token for its two-phase login.

## 3.6.0
- Bypass Wordfence's `wfls_captcha_verify` rejection when credentials are valid.

## 3.0.x – 3.5.x
- Role/user exemptions, custom login logo, log viewer, SMTP delivery gate,
  WordPress.org readiness (license header, text domain, Plugin Check clean).

## 1.0.0 – 2.x
- Core OTP: generate, email, verify, lockout, resend; enable/disable;
  configurable attempts + lockout; multisite; exemptions.
