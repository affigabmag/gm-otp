# Contributing to GM OTP

Thanks for helping improve GM OTP! Issues and pull requests are welcome.

## Project layout

See [`dox/ARCHITECTURE.md`](dox/ARCHITECTURE.md). In short:

```
gm-otp.php          Bootstrap (header, constants, require_once of includes)
includes/core.php   Logging, log viewer, shared helpers
includes/admin.php  Settings pages, menu, action links, field renderers
includes/login.php  OTP auth flow, inline field, and dialog page
assets/             Dialog-page CSS + screenshots
tests/              Stub-based tests (plain PHP CLI, no WordPress needed)
```

## Conventions

- Procedural PHP following the WordPress coding standards; all functions
  prefixed `gm_otp_`.
- Keep related functions in the same include; the main file only holds the
  header, constants and `require_once`s.
- Inside an include never use `__FILE__` to identify the plugin — use the
  `GM_OTP_PLUGIN_FILE` / `GM_OTP_PLUGIN_URL` constants.
- Save files as UTF-8 **without BOM** (a BOM before `<?php` breaks headers).
- Read superglobals through `gm_otp_input()`.

More detail and environment gotchas are in [`dox/CLAUDE.md`](dox/CLAUDE.md).

## Running the tests

The tests stub WordPress and exercise the real `includes/` code:

```
php tests/run.php          # captcha bypass, multisite routing, AJAX flow, grace token
php tests/run-dialog.php   # non-AJAX login redirects to the dialog page
```

Both must pass. CI runs them on PHP 7.4 / 8.1 / 8.3 for every push and PR.

## Before opening a PR

1. `php -l` every file you touched (or let CI do it).
2. Run the tests above.
3. If you changed behaviour, bump `Version:` + `GM_OTP_VERSION` +
   `GM_OTP_BUILD_TIME`, and add a `readme.txt` changelog entry.
4. Describe what changed and why.
