=== GM OTP ===
Contributors: affigabmag
Tags: login, security, two factor, otp, email
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 3.12.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Requires an email code after password on login, network-wide on multisite, with lockout, resend, logo, and role/user exemptions.

== Description ==

After a correct username/password, GM OTP sends a 6-digit code to the user's email and requires it as a 3rd field on the same login form before login completes.

Features:

* Enable/disable network-wide on multisite, or per-site on single-site installs
* Configurable max wrong-code attempts and lockout duration
* Resend code with a cooldown timer
* Masked email address shown on the code screen
* Custom login logo upload
* Exempt specific roles or users from the OTP requirement
* Built-in log viewer for troubleshooting delivery/redirect issues

== Installation ==

1. Upload the plugin to /wp-content/plugins/gm-otp
2. Activate through the 'Plugins' menu (or Network Activate on multisite)
3. Configure under Settings > GM OTP (or Network Admin > Settings > GM OTP on multisite)

== Changelog ==

= 3.12.1 =
* Fixed the Save-button gating never actually taking effect: the script ran before the Save button existed on the page and bailed out, so Save stayed enabled. It now runs on DOMContentLoaded and correctly stays greyed out until both confirmation boxes are checked.

= 3.12.0 =
* The three activation checkboxes (Enable OTP login, Email delivery confirmed, Lockout risk acknowledged) now sit side by side in one row of three columns, under a single "Activation" heading, instead of stacked.

= 3.11.3 =
* "Save Changes" now stays disabled until BOTH "Email delivery confirmed" and "Lockout risk acknowledged" are checked (previously only the lockout box gated it). The "Send Test Email" button stays clickable so delivery can be verified first.

= 3.11.2 =
* Fixed GM OTP appearing twice in the admin menu (top-level and under Settings). It now shows only once, as the top-level item.

= 3.11.1 =
* Added a "Settings" link next to Deactivate on the Plugins list (and the network Plugins list on multisite).

= 3.11.0 =
* The "Save Changes" button now stays disabled (grey) until the "Lockout risk acknowledged" box is checked, then turns primary (blue).

= 3.10.0 =
* Added a top-level "GM OTP" admin menu item (in addition to Settings > GM OTP).
* Pressing Enter in the code field now submits the login (as if clicking Log In) instead of triggering Resend.
* Added a required "Lockout risk acknowledged" checkbox: OTP login can't be enabled until you confirm you understand you could be locked out and have a recovery path (FTP/file-manager/DB access to remove or disable the plugin).

= 3.9.0 =
* Settings layout: "Max attempts" + "Lockout duration", "Exempt roles" + "Exempt users", and "Log" + "PHP Error Log" now sit side by side instead of stacked, for a more compact page.

= 3.8.1 =
* Fixed a "wrong code" loop with security plugins that authenticate twice per login (Wordfence Login Security's reCAPTCHA: once over admin-ajax.php, then again on the real wp-login.php POST). The code was consumed in the first phase, so the second phase generated and emailed a brand-new code, invalidating the one just entered. A single-use grace token minted after a correct code in the AJAX phase now lets the follow-up login POST through.

= 3.8.0 =
* The code field is now always present in the login form (hidden until a code is pending) instead of being printed only when required. This makes it work with AJAX login flows that never re-render the page (e.g. Wordfence Login Security's reCAPTCHA, which submits via admin-ajax.php): the field is carried on every submission and a small script reveals it, via a non-secret companion cookie, the moment a code is required. No need to disable the security plugin's reCAPTCHA.

= 3.7.0 =
* Rewrote the code screen: instead of a separate redirect page, the 6-digit code is now a 3rd field on the same login form (below username/password), submitted together. Needed for compatibility with AJAX-based login flows (e.g. Wordfence Login Security posts to admin-ajax.php, which a raw-HTML redirect page can't survive). Also removes the resend/verify nonce entirely — every submission already re-proves the password via core's own check.

= 3.6.0 =
* Added a bypass for Wordfence Login Security's own captcha check (error code wfls_captcha_verify) rejecting logins before GM OTP ever sees them: if the underlying username/password are genuinely correct, re-verify directly against core and proceed with our own OTP flow instead of honoring that rejection.

= 3.5.1 =
* Author name updated.

= 3.5.0 =
* PHP Error Log viewer now probes common hosting log paths (PHP-FPM, Apache, Nginx, temp dir) when PHP's own error_log directive is empty, instead of giving up. Also shows SAPI name and loaded php.ini path as a manual fallback.

= 3.4.1 =
* Fixed a real bug (not Wordfence): the nonce check was rejecting the bounce-landing POST itself (which never carries the verify nonce, since that's only created when the form renders), showing "page expired" on the very first load of the code screen. Nonce is now only required on actual resend/code submissions.

= 3.4.0 =
* Added a cookie-based fallback: if a plugin/proxy/security tool ever causes the login page to fall back to the normal 'login' action instead of ours, the code screen still renders as long as a valid pending-OTP cookie exists — the cookie is now the source of truth, not the URL's action parameter.
* "Back to login" now actually cancels the pending OTP session instead of getting re-caught by that same fallback.

= 3.3.1 =
* The redirect-page fallback button is now always visible instead of hidden inside <noscript> — a strict CSP can block the auto-submit script without disabling JS outright, which previously left no visible way to continue.

= 3.3.0 =
* Added a warning notice and confirmation dialog when enabling OTP login, with a link to install/configure SMTP Mailer.

= 3.2.0 =
* Enable OTP login now requires a separate "email delivery confirmed" checkbox, backed by a real Send Test Email button, before it takes effect.

= 3.1.1 =
* Ran Plugin Check (PCP): eliminated all file-operation errors (fopen/fread/fclose replaced with file_get_contents on the tail-read paths), added a nonce to the OTP code/resend form, and properly unslashed/sanitized all superglobal reads used in logging and routing.

= 3.0.1 =
* Added license header, text domain

= 3.0.0 =
* Checkbox-list UI for role/user exemptions

= 2.9.0 =
* Added role and user exemptions

= 2.8.0 =
* Added custom login logo upload

= 2.7.0 =
* Switched code-page redirect to an auto-submitting POST bounce for compatibility with plugins that intercept GET requests to wp-login.php

= 2.6.0 =
* Added in-admin PHP error log viewer

= 2.3.0 =
* Added in-admin log viewer

= 2.0.0 =
* Moved code entry to its own screen instead of sharing the login form

= 1.0.0 =
* Initial release
