# Reset Admin Password Instructions

## Goal
Set up admin user "Anna" with password "admin" to have access to all three domains:
- Main site: `https://myeventlane.ddev.site`
- Admin domain: `https://admin.myeventlane.ddev.site`
- Vendor domain: `https://vendor.myeventlane.ddev.site`

## Quick Setup

Run this command in your terminal (you'll be prompted for your password for sudo):

```bash
./reset-admin-password.sh
```

## Manual Steps (if script doesn't work)

1. **Start DDEV** (enter your password when prompted):
   ```bash
   ddev start
   ```

2. **Check if user exists and create/reset password**:
   ```bash
   # Check if user exists
   ddev exec "drush user:information anna"
   
   # If user doesn't exist, create it:
   ddev exec "drush user:create anna --mail=anna@myeventlane.local --password=admin"
   
   # If user exists, reset password:
   ddev exec "drush user:password anna admin"
   ```

3. **Assign administrator role** (gives access to all domains):
   ```bash
   ddev exec "drush user:role:add administrator anna"
   ```

4. **Verify setup**:
   ```bash
   ddev exec "drush user:information anna"
   ```

## Domain Access Summary

### Administrator Role (Anna)
- ✅ **Full access to all three domains**
- ✅ Can access admin routes on any domain
- ✅ Can access vendor routes on vendor domain
- ✅ Can access public routes on main domain

### Vendor Role
- ✅ Can access **main site** (public content, events, etc.)
- ✅ Can access **vendor domain** (vendor dashboard, event management)
- ❌ Cannot access **admin domain** admin routes (redirected to login or vendor domain)

## Login URLs

After setup, you can log in as "Anna" at any of these URLs:
- `https://myeventlane.ddev.site/user/login`
- `https://admin.myeventlane.ddev.site/user/login`
- `https://vendor.myeventlane.ddev.site/user/login`

**Credentials:**
- Username: `anna`
- Password: `admin`

## Troubleshooting

If DDEV fails to start due to hosts file issues:
1. You may need to manually add entries to `/etc/hosts` (requires sudo)
2. Or temporarily remove ngrok hostnames from `.ddev/config.yaml` if not needed

