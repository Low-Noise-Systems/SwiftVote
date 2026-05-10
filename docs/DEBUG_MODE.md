# Debug Mode Documentation

## Overview

The debug system provides an easy way to test the voting application locally without needing to go through email verification. This is particularly useful during development for quickly testing admin and voter workflows.

## Security Features

The debug system is designed with security in mind:

- **Only works on localhost**: Debug features are disabled unless the request comes from `localhost`, `127.0.0.1`, or `::1`
- **Requires DEBUG_MODE=true**: Must be explicitly enabled in `.env` file
- **No production risk**: Even if `DEBUG_MODE=true` is accidentally left in production, the debug panel will not appear on non-localhost hosts
- **Proper session handling**: Uses the same secure session management as normal authentication
- **Validates user permissions**: Still checks that users exist in `admin_emails.txt` or `voter_emails.txt`

## Setup Instructions

### 1. Enable Debug Mode

Edit `php-survey-backend/config/.env` and set:

```env
DEBUG_MODE=true
```

### 2. Ensure Localhost is Trusted

Make sure localhost is in your `TRUSTED_HOSTS`:

```env
TRUSTED_HOSTS=example.org,localhost,localhost:8000,127.0.0.1
```

### 3. Start the Development Server

Use the provided startup script:

```bash
./start-dev-server.sh
```

Or manually:

```bash
cd public && php -S localhost:8000
```

## Using Debug Mode

### Quick Login Buttons

When debug mode is active, you'll see a yellow debug panel on:
- Main page: `http://localhost:8000`
- Admin login: `http://localhost:8000/admin/login.php`

The debug panel provides:

1. **Login as Admin** button - Logs in as the first admin from `admin_emails.txt`
2. **Login as Voter** button - Logs in as the first voter from `voter_emails.txt`
3. **Advanced options** (expandable) - Login as any specific admin or voter email

### What Happens During Debug Login

1. No email is sent
2. No token verification required
3. Session is created immediately
4. You're redirected to the appropriate page:
   - Admin: redirected to `/admin/`
   - Voter: redirected to `/announcement/`

### Testing Different User Roles

#### Test as Admin:
1. Visit `http://localhost:8000`
2. Click "🔑 Login as Admin"
3. You're now logged in as an administrator

#### Test as Voter:
1. Visit `http://localhost:8000`
2. Click "🗳️ Login as Voter"
3. You're now logged in as a voter

#### Test as Specific User:
1. Visit `http://localhost:8000`
2. Expand "Advanced: Login as specific user"
3. Enter the email address
4. Click the appropriate login button

## Debug Mode Benefits

### Extended Session Timeout
- **Production**: 5 minutes idle timeout
- **Debug Mode**: 2 hours idle timeout
- Makes testing more convenient without constant re-authentication

### Bypass Email Verification
- No need to check email during development
- Instant login with one click
- Test multiple user roles quickly

### Test on Any Port
Works on any localhost port:
- `http://localhost:8000`
- `http://localhost:3000`
- `http://127.0.0.1:8080`
- etc.

## Troubleshooting

### Debug panel not showing?

Check these conditions:
1. Is `DEBUG_MODE=true` in `.env`?
2. Are you accessing via `localhost` or `127.0.0.1`?
3. Is localhost in `TRUSTED_HOSTS`?
4. Check browser console for JavaScript errors
5. Check PHP error logs for issues

### Login fails?

1. Make sure the email exists in:
   - `php-survey-backend/config/admin_emails.txt` (for admin)
   - `php-survey-backend/config/voter_emails.txt` (for voter)
2. Check PHP error logs: `php-survey-backend/logs/error_log`
3. Verify file permissions on config files

### Still getting "Unauthorized host" error?

Add more localhost variants to `TRUSTED_HOSTS`:
```env
TRUSTED_HOSTS=yourdomain.com,localhost,localhost:8000,127.0.0.1,127.0.0.1:8000
```

## Production Deployment

### Important: Disable Debug Mode

Before deploying to production, set in `.env`:

```env
DEBUG_MODE=false
```

### What Happens in Production

Even if you forget to set `DEBUG_MODE=false`, the debug features won't work because:
1. Production hostname is not localhost
2. `is_debug_enabled()` returns `false`
3. Debug panel is not rendered
4. Debug login endpoint returns 403 Forbidden

However, you'll still get the extended session timeout benefit if `DEBUG_MODE=true`, so it's best practice to set it to `false` in production.

## Code Structure

### Files Modified/Created

1. **`php-survey-backend/includes/debug.php`** - New file with debug functions
2. **`public/index.php`** - Added debug panel and login handler
3. **`public/admin/login.php`** - Added debug panel and login handler
4. **`php-survey-backend/config/.env.example`** - Updated documentation
5. **`start-dev-server.sh`** - New convenience script

### Key Functions

- `is_debug_enabled()` - Checks if debug mode is active and on localhost
- `debug_login_as_admin($email, $config)` - Quick admin login
- `debug_login_as_voter($email, $config)` - Quick voter login
- `render_debug_panel($config)` - Displays the debug UI
- `handle_debug_login_action($config)` - Processes debug login requests

## Best Practices

1. **Use debug mode only during development**
2. **Never commit `.env` with `DEBUG_MODE=true`** to version control for production
3. **Keep `.env.example` with `DEBUG_MODE=false`** as the default
4. **Test both debug and production modes** before deploying
5. **Review logs** regularly to ensure debug features aren't being misused

## Example Workflow

```bash
# 1. Enable debug mode
echo "DEBUG_MODE=true" >> php-survey-backend/config/.env

# 2. Start dev server
./start-dev-server.sh

# 3. Open browser to http://localhost:8000

# 4. Click "Login as Admin" or "Login as Voter"

# 5. Test your features

# 6. When done, disable debug mode
# Edit .env and set DEBUG_MODE=false
```

## Summary

The debug system provides a secure, convenient way to test authentication flows during development without the hassle of email verification. It's designed to be safe in production (won't activate) while being incredibly useful during development.

Happy testing! 🚀
