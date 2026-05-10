# SwiftVote

Self-hosted, end-to-end-encrypted online voting for associations,
boards, and student or professional groups that need a real ballot
without enterprise complexity.

## What it is

A PHP voting application designed to run on shared, low-budget, or
self-managed hosting. There is no database — state lives in flat files
and JSON. Ballots are encrypted with **X25519 sealed boxes** before
they ever touch storage, and the private decryption key never leaves
the administrator's machine.

- **End-to-end encryption** — ballots sealed with the election's
  public key; tallying happens in the admin's browser using a private
  key that the server never sees.
- **Magic-link authentication** — voters receive a one-time link by
  email, no accounts, no passwords.
- **Approval and Single Transferable Vote (STV)** — voting method
  configurable per election.
- **Cloudflare Turnstile** — optional captcha on the request-link
  endpoint.
- **Hardened by default** — CSRF tokens, rate limiting, CSP headers,
  hashed time-limited tokens, defence-in-depth directory layout.
- **Setup wizard** — first-run flow auto-generates the application
  secret, registers the first administrator, and tests email delivery.

## Status & license

Production-ready and actively maintained. The source is **public and
readable** but the project is **proprietary, not open-source** — see
[`LICENSE`](LICENSE). Use, redistribution, and modification require
written permission. Commercial enquiries:
[contact@lownoisesystems.org](mailto:contact@lownoisesystems.org).

## Requirements

- PHP **8.0+** with the `sodium` extension enabled
- A web server able to serve a public directory (Apache, Nginx, or
  PHP's built-in server for local development)
- Either the host's `mail()` function or SMTP credentials for
  outbound email

No Composer, no npm, no build step.

## Quick start (local development)

```bash
git clone https://github.com/Low-Noise-Systems/SwiftVote.git
cd SwiftVote
php -S localhost:8000 -t public
```

Open <http://localhost:8000/setup/>. The setup wizard will
auto-generate a `SECRET_KEY`, register your first admin email,
configure email delivery, and write `.env` for you. After that, sign
in at <http://localhost:8000/admin/> to generate the election's
encryption keypair, add candidates, and upload the voter allow-list.

For production deployment, hardening, SMTP recipes, and the full admin
walkthrough, read **[`docs/QUICKSTART.md`](docs/QUICKSTART.md)**.

## Architecture in one paragraph

The repository is split into two top-level directories that map
directly onto the production filesystem layout:

- [`public/`](public/) — the **document root**. Everything web-facing
  lives here: voter landing page, voting UI, admin console, setup
  wizard, static assets.
- [`php-survey-backend/`](php-survey-backend/) — application code,
  configuration, encrypted ballot storage, logs. **Must sit one level
  above the web root in production** so it cannot be served directly.

State is entirely file-based: encrypted ballots in
`php-survey-backend/vote_data/votes/`, runtime configuration in
JSON files under `php-survey-backend/config/`, and per-election
settings editable from the admin UI without touching `.env`.

## Security model

1. The administrator generates an X25519 keypair from the admin
   console. The **public key** is uploaded to the server; the
   **private key** is downloaded and kept offline.
2. Voters request a magic link by submitting their email. The server
   issues a hashed, time-limited token (with optional Turnstile
   verification and rate limiting).
3. When a voter casts a ballot, the JSON payload is encrypted with
   `crypto_box_seal()` against the election's public key before being
   written to disk. The server cannot read the ballot it just stored.
4. At tally time, the admin loads the encrypted ballots in a browser
   and decrypts them with the offline private key. The decryption
   code (libsodium-wrappers, WASM) runs entirely client-side.

CSRF protection, rate limits, session timeouts, CSP headers, and
optional Cloudflare Turnstile harden the request paths around that
core flow.

## Documentation

- [`docs/index.md`](docs/index.md) — project overview
- [`docs/QUICKSTART.md`](docs/QUICKSTART.md) — install & deploy guide
- [`docs/DEBUG_MODE.md`](docs/DEBUG_MODE.md) — local development tips
- [`docs/REFACTORING_GUIDE.md`](docs/REFACTORING_GUIDE.md) — for maintainers

## Contributing

This is a source-available proprietary project. Issues and feedback are
welcome via the GitHub tracker. Contributions are accepted by
invitation only — please reach out before opening a pull request.
