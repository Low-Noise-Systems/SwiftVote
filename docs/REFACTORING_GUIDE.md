# Refactoring Guide - Centralized Admin Guard

## 🎯 Objective

Centralize admin security logic in reusable files instead of repeating `require_admin_auth()` everywhere.

---

## 📁 New Files Created

### 1. `php-survey-backend/includes/admin_guard.php`
**Usage:** Admin pages with public actions (login, logout)

```php
// public/admin/index.php - BEFORE
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
init_secure_session();
// ... 50 lines of config ...
if ($action === 'verify') { /* login */ }
if ($action === 'disconnect') { /* logout */ }
require_admin_auth($config); // ← Repetitive and easy to forget
```

```php
// public/admin/index.php - AFTER
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard.php'; // ← Intelligent guard
init_secure_session();
// ... the rest ...
```

**Advantages:**
- ✅ Automatically handles public actions (request_link, verify, disconnect)
- ✅ Blocks everything else
- ✅ One line to add

---

### 2. `php-survey-backend/includes/admin_guard_strict.php`
**Usage:** 100% private admin pages (no login)

```php
// public/candidates/index.php - BEFORE
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
init_secure_session();
// ... config ...
require_admin_auth($config); // ← Repetitive
```

```php
// public/candidates/index.php - AFTER
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';
require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard_strict.php'; // ← Strict guard
init_secure_session();
// ... the rest ...
```

**Advantages:**
- ✅ IMMEDIATELY blocks all non-admins
- ✅ More explicit: "this page is strictly admin"
- ✅ Prevents oversights

---

## 🔄 Refactoring Plan

### Phase 1: Strictly Admin Pages (EASY)

| File | Guard to Use | Line to Add |
|---------|------------------|-----------------|
| `public/candidates/index.php` | `admin_guard_strict.php` | After config_loader |
| `public/admin/vote-settings/index.php` | `admin_guard_strict.php` | After config_loader |

**Example for candidates/index.php:**

```php
<?php
declare(strict_types=1);

// Load security functions and configuration first
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';

// SECURITY: Use strict admin guard - this page has no public actions
// This replaces the manual require_admin_auth($config) call
require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard_strict.php';

// Initialize secure session
init_secure_session();

// ... rest of code WITHOUT manual require_admin_auth() ...
```

### Phase 2: Admin Page with Login (MEDIUM)

| File | Guard to Use | Notes |
|---------|------------------|-------|
| `public/admin/index.php` | `admin_guard.php` | Handles request_link, verify, disconnect |

**Example for admin/index.php:**

```php
<?php
declare(strict_types=1);

// Load security and config
include_once __DIR__ . '/../../php-survey-backend/includes/security.php';
include_once __DIR__ . '/../../php-survey-backend/includes/config_loader.php';

init_secure_session();
// ... config loading ...

// Login actions (executed before guard)
if ($action === 'request_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... login logic ...
}

if ($action === 'verify') {
    // ... verification logic ...
}

if ($action === 'disconnect') {
    // ... logout logic ...
}

// SECURITY: Admin guard (allows public actions above, blocks everything else)
require_once __DIR__ . '/../../php-survey-backend/includes/admin_guard.php';

// Admin-only actions below this point
if ($is_admin && $action === 'set_mode') {
    // ...
}
```

---

## ✅ Advantages of This Architecture

### 1. **DRY (Don't Repeat Yourself)**
```php
// BEFORE: 8 files with require_admin_auth($config)
// AFTER: 1 include per file
```

### 2. **Security by Default**
```php
// If a developer forgets require_admin_auth()
// → The centralized guard catches it automatically
```

### 3. **Easy Maintenance**
```php
// Need to change admin auth logic?
// → 2 files to modify instead of 8+
```

### 4. **Self-Documentation**
```php
// admin_guard_strict.php = "This page is 100% admin"
// admin_guard.php = "This page has public actions"
```

---

## 🔒 Security Comparison

### Current Architecture (Post-Fix)
```
public/admin/index.php
├── include security.php
├── include config_loader.php
├── init_secure_session()
├── ... 100 lines of config ...
├── if action === 'verify' → login
├── if action === 'disconnect' → logout
└── require_admin_auth($config) ← MANUAL, easy to forget
```

### Refactored Architecture (Recommended)
```
public/admin/index.php
├── include security.php
├── include config_loader.php
├── require admin_guard.php ← AUTOMATIC, impossible to forget
├── init_secure_session()
├── ... config ...
├── if action === 'verify' → login (allowed by guard)
└── if action === 'disconnect' → logout (allowed by guard)
```

---

## 🚀 Progressive Migration

**Option 1: Immediate Migration (Recommended)**
- Refactor all admin pages at once
- Test each page
- 1 commit with all changes

**Option 2: Progressive Migration**
- Start with `candidates/index.php` (easiest)
- Test
- Continue with `vote-settings/index.php`
- Test
- Finish with `admin/index.php`
- 3 separate commits

---

## 🧪 Tests Required After Refactoring

### For admin_guard_strict.php (candidates, vote-settings)
```bash
# Test 1: Voter cannot access
curl -H "Cookie: PHPSESSID=voter" http://localhost/candidates/
# Expected: HTTP 403 {"error":"Admin authentication required"}

# Test 2: Admin can access
curl -H "Cookie: PHPSESSID=admin" http://localhost/candidates/
# Expected: HTTP 200 + HTML page
```

### For admin_guard.php (admin/index.php)
```bash
# Test 1: Unauthenticated can request a link
POST /admin/ action=request_link
# Expected: HTTP 200 + email sent

# Test 2: Non-admin cannot see the panel
GET /admin/
# Expected: Login form (or 403 if no action)

# Test 3: Admin can see the panel
GET /admin/ (with admin session)
# Expected: HTTP 200 + admin panel
```

---

## 📝 Migration Checklist

- [ ] Create `admin_guard.php` (✅ Done)
- [ ] Create `admin_guard_strict.php` (✅ Done)
- [ ] Refactor `public/candidates/index.php`
- [ ] Test `candidates/`
- [ ] Refactor `public/admin/vote-settings/index.php`
- [ ] Test `vote-settings/`
- [ ] Refactor `public/admin/index.php`
- [ ] Test `admin/`
- [ ] Test complete flow: login → panel → candidates → settings → logout
- [ ] Remove redundant manual calls to `require_admin_auth()`
- [ ] Commit and push

---

## ⚠️ Pitfalls to Avoid

### Pitfall 1: Include Order
```php
// ❌ BAD
require_once __DIR__ . '/admin_guard.php';
include_once __DIR__ . '/config_loader.php'; // Too late!

// ✅ GOOD
include_once __DIR__ . '/security.php';
include_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/admin_guard.php'; // $config exists now
```

### Pitfall 2: Public Actions
```php
// If you add a new public action in admin/index.php
// Don't forget to declare it in admin_guard.php:

$public_actions = [
    'request_link',
    'verify',
    'disconnect',
    'new_action', // ← Add here
];
```

### Pitfall 3: Don't Mix Guards
```php
// ❌ BAD: Use admin_guard.php on candidates/
// → Allows 'disconnect' action even if it doesn't exist

// ✅ GOOD: Use admin_guard_strict.php
// → Blocks everything unless admin authenticated
```

---

## 🎯 Final Result

All admin pages use a centralized guard:

```
public/
├── admin/
│   ├── index.php                → admin_guard.php (login + panel)
│   ├── vote-settings/index.php  → admin_guard_strict.php
│   └── init/
│       ├── index.php            → admin_guard_strict.php
│       ├── save_pubkey.php      → admin_guard_strict.php
│       └── check_pubkey.php     → admin_guard_strict.php
└── candidates/index.php         → admin_guard_strict.php
```

**Security:** ✅ Consistent, centralized, maintainable
**Maintainability:** ✅ DRY, self-documented
**Robustness:** ✅ Impossible to forget protection
