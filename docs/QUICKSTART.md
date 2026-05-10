# Quick Start Guide

This guide allows you to install and launch your first poll in a few minutes.

## Local Installation

### 1. Clone and configure

```bash
git clone <REPO_URL>
cd php-survey
cp php-survey-backend/config/.env.example php-survey-backend/config/.env
```

### 2. Minimal configuration

Edit `php-survey-backend/config/.env` :

```env
SECRET_KEY=your-very-long-and-random-secret-key
APP_NAME=My Poll
BASE_URL=http://localhost:8000
TRUSTED_HOSTS=localhost

# Email mode (hosting or smtp)
MAIL_MODE=hosting
HOSTING_FROM_ADDRESS=noreply@yourdomain.com
HOSTING_FROM_NAME=Poll Bot

# Cloudflare Turnstile (optional for local test)
TURNSTILE_SITE_KEY=1x00000000000000000000AA
TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA
```

### 3. Add an administrator

Create or edit `php-survey-backend/config/admin_emails.txt` :

```
your-email@example.com
```

### 4. Launch the local server

```bash
php -S localhost:8000 -t public
```

Access http://localhost:8000

## Production Deployment

### Directory Structure

```
Your hosting:
├── /home/user/
│   └── php-survey-backend/     ← Place here (NOT public)
│       ├── actions/
│       ├── includes/
│       ├── views/
│       ├── config/
│       ├── data/
│       ├── vote_data/
│       └── logs/
└── public_html/                ← Place the content of public/ here
    ├── index.php
    ├── admin/
    ├── vote/
    └── assets/
```

### Deployment Steps

1. **Upload files**
   - Backend in your private directory (ex. `/home/user/php-survey-backend/`)
   - Content of `public/` in your web folder (ex. `public_html/`)

2. **Configure the environment**
   - Copy `.env.example` to `.env`
   - Fill in production values
   - Add admin emails in `admin_emails.txt`

3. **Set permissions**
   ```bash
   chmod 750 ~/php-survey-backend/vote_data
   chmod 750 ~/php-survey-backend/logs
   chmod 750 ~/php-survey-backend/data
   chmod 640 ~/php-survey-backend/config/.env
   ```

4. **Activate HTTPS**
   - Configure an SSL certificate (Let's Encrypt recommended)
   - Update `BASE_URL` in `.env`

5. **Test the installation**
   - Check that `https://yourdomain.com/` works
   - Check that `https://yourdomain.com/admin/` works
   - Check that `https://yourdomain.com/php-survey-backend/config/.env` returns 404

## Launch a Poll

### 1. Generate Encryption Keys

1. Access `http://localhost:8000/admin/` (or your domain)
2. Click on "Generate New Keys"
3. **Important**: Download and save the private key offline
4. The public key is automatically stored on the server

### 2. Add Authorized Voters

Edit `php-survey-backend/vote_data/allowed_emails.txt` :

```
voter1@example.com
voter2@example.com
voter3@example.com
```

One email per line.

### 3. Configure the Poll

In the admin interface, configure:
- Poll name
- Number of possible choices
- Validity duration of magic links (default 1 hour)

### 4. Send Invitations

Voters can request their magic link on the home page:
1. The voter enters their email
2. If the email is authorized, a unique link is sent
3. The link is valid for the configured duration (1 hour by default)
4. The voter votes by clicking on the link

### 5. Monitor Activity

In the admin interface:
- Check the number of votes received
- Check activity logs
- Monitor access attempts

### 6. Tally the Results

1. Download the encrypted ballots from the admin interface
2. Use the integrated decryption tool
3. Provide the private key (it stays in your browser)
4. Results are decrypted locally
5. Export or view the results

## SMTP Configuration (Optional)

### Hosting Mode (Default)

Uses PHP's `mail()` function. Configuration in `.env`:

```env
MAIL_MODE=hosting
HOSTING_FROM_ADDRESS=noreply@yourdomain.com
HOSTING_FROM_NAME=Poll Bot
```

### External SMTP Mode

To use Gmail, SendGrid, etc.:

1. **Create the configuration file**
   ```bash
   cp php-survey-backend/config/smtp_mailboxes.json.example php-survey-backend/config/smtp_mailboxes.json
   ```

2. **Configure SMTP Server**

   Edit `smtp_mailboxes.json`:
   ```json
   {
     "host": "smtp.gmail.com",
     "port": 587,
     "username": "your-email@gmail.com",
     "password": "your-app-password",
     "encryption": "tls",
     "from": {
       "address": "noreply@yourdomain.com",
       "name": "Poll Bot"
     },
     "reply_to": {
       "address": "support@yourdomain.com",
       "name": "Support"
     }
   }
   ```

3. **Activate SMTP Mode**

   In `.env`:
   ```env
   MAIL_MODE=smtp
   ```

Only a single SMTP server is supported. If your existing file uses an array, only the first entry will be used.

## Security

### Essential Checkpoints

- ✅ HTTPS enabled in production
- ✅ Long and random `SECRET_KEY` (128+ characters)
- ✅ Backend outside the public web directory
- ✅ Private encryption key kept offline
- ✅ File permissions correctly set
- ✅ Cloudflare Turnstile enabled to limit bots

### Data Protection

- Ballots are encrypted **before** storage on the server
- The private key never leaves the administrator's browser
- Decryption is done **locally** client-side
- No ballot is ever stored in plain text

## Quick Troubleshooting

### Emails Not Sending

- Check SMTP configuration in `.env`
- Check `php-survey-backend/logs/error_log`
- Test sending a test email

### "Configuration File Not Found" Error

- Check that `php-survey-backend/` is in the right place
- Check relative paths between `public/` and `php-survey-backend/`

### Permission Issues

```bash
chmod 750 ~/php-survey-backend/vote_data
chmod 750 ~/php-survey-backend/logs
chmod 750 ~/php-survey-backend/data
```

### Votes Not Being Recorded

- Check that the public key is generated
- Check permissions of the `vote_data/` folder
- Check logs for more details

## Resources

### Main Configuration Files

- `.env`: General configuration
- `admin_emails.txt`: List of administrators
- `allowed_emails.txt`: List of authorized voters
- `smtp_mailboxes.json`: SMTP configuration (optional)

### Important Folders

- `vote_data/`: Encrypted ballots and voting data
- `logs/`: Activity and error logs
- `data/`: Public encryption keys

### Maintenance

- Regularly backup `vote_data/` and `data/`
- Check logs to monitor activity
- Renew keys for each new election
- Clean up old files after each poll

---

**Need help?** Check the logs in `php-survey-backend/logs/` or contact your system administrator.
