# Changelog

Notable changes. The authoritative, full list lives in
[`readme.txt`](readme.txt) (WordPress.org format); highlights below.

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
