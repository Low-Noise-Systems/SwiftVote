# SwiftVote

An online voting solution that prioritizes deployment simplicity while maintaining security guarantees suitable for associative, professional, or student elections.

## Project Objective

Provide a fast and reliable way to launch an electronic vote without complex infrastructure, while ensuring data security and ballot confidentiality.

## Why this application?

- **Deployment simplicity**: Compatible with shared or low-budget hosting
- **Robust security**: Ballots are systematically encrypted (X25519) before recording
- **Accessibility**: Authentication by magic link, no need to create user accounts
- **Total control**: The private decryption key remains offline with administrators

## Main Features

- 🔐 **End-to-end encryption**: X25519 encryption on server side with private key reserved for administrators
- 📨 **Simplified authentication**: Magic link by email and whitelist of voters
- 🛡️ **Integrated protections**: CSRF, attempt limitation, Cloudflare Turnstile integration
- 📊 **Administration interface**: Key generation, activity tracking, and secure tallying
- 📧 **Advanced SMTP support**: Hosting mode or single external SMTP configuration

## Secure Architecture

The application follows PHP security best practices:

- **Directory separation**: Sensitive code outside the public web directory
- **Defense in depth**: Physical protection + .htaccess + code-level safeguards
- **Compatible with all hosts**: Works on Apache, Nginx, shared hosting

## Prerequisites

- PHP 8.0+ with the `sodium` extension enabled
- SMTP access (hosting email account or third-party provider)
- Ability to create two folders: one public (`public/`), the other protected (`php-survey-backend/`)

## Documentation

To install and use this application, consult the **QUICKSTART.md** file.

## Support and Maintenance

- Check `php-survey-backend/logs/` in case of issues
- Regularly backup `php-survey-backend/data/` and `vote_data/`
- Renew your voting keys for each new election

---

This application is designed to offer a robust compromise between deployment ease and security for sensitive but reasonably scaled votes.
