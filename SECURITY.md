# Security Policy

## Supported versions

The latest released version receives security fixes. Please upgrade before
reporting an issue.

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

- Preferred: open a private report via GitHub → **Security → Report a vulnerability**
  (GitHub Security Advisories) on this repository.
- Or email the maintainer at **affiliate.gabmag@gmail.com** with details and, if
  possible, a proof-of-concept.

Please include the plugin version, WordPress version, and steps to reproduce.
You'll get an acknowledgement as soon as possible, and a coordinated fix and
disclosure once the issue is confirmed.

## Scope notes

GM OTP is a login second factor. Relevant areas include: the OTP generation and
verification flow, the pending-session cookie/transient handling, the code
dialog page, and the settings pages. Delivery of the code depends on the site's
outgoing email (an SMTP plugin is recommended) — mail deliverability itself is
out of scope.
