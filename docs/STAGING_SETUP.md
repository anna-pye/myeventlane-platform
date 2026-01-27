# Staging Environment Security Setup

This guide explains how to lock down your MyEventLane staging site to prevent search engine indexing and unauthorized access.

## Overview

The staging environment includes multiple layers of protection:

1. **robots.txt** - Blocks all search engine crawlers
2. **X-Robots-Tag HTTP headers** - Prevents indexing at the HTTP level
3. **Cache-Control headers** - Prevents caching by browsers and CDNs
4. **Nginx configuration** - Server-level security headers
5. **Drupal settings** - Application-level staging detection

## Files Created

- `web/robots.txt.staging` - Staging-specific robots.txt (blocks all crawlers)
- `web/modules/custom/myeventlane_core/src/EventSubscriber/StagingSecuritySubscriber.php` - Adds security headers
- `staging-nginx.conf` - Nginx configuration for VPS
- `STAGING_SETUP.md` - This file

## Setup Instructions

### 1. Deploy robots.txt to Staging

On your staging VPS, replace the production `robots.txt` with the staging version:

```bash
# On staging VPS
cd /path/to/web
cp robots.txt robots.txt.production  # Backup production version
cp robots.txt.staging robots.txt     # Use staging version
```

### 2. Configure Nginx

Add the staging security configuration to your nginx site config:

```nginx
server {
    listen 80;
    listen 443 ssl http2;
    server_name staging.myeventlane.com;  # Your staging domain
    
    root /path/to/web;
    index index.php;
    
    # Include staging security configuration
    include /path/to/staging-nginx.conf;
    
    # Your existing Drupal configuration...
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

After updating nginx config:

```bash
sudo nginx -t                    # Test configuration
sudo systemctl reload nginx      # Reload nginx
```

### 3. Set Environment Variable (Optional but Recommended)

Set the `STAGING_ENVIRONMENT` environment variable on your VPS to explicitly mark it as staging:

**For systemd/PHP-FPM:**
```bash
# Edit PHP-FPM pool configuration
sudo nano /etc/php/8.3/fpm/pool.d/www.conf

# Add to [www] section:
env[STAGING_ENVIRONMENT] = 1

# Restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

**For Docker/Container:**
```yaml
# docker-compose.yml or similar
environment:
  - STAGING_ENVIRONMENT=1
```

**For Apache:**
```apache
# In your VirtualHost or .htaccess
SetEnv STAGING_ENVIRONMENT 1
```

### 4. Optional: HTTP Basic Authentication

For additional protection, you can password-protect the entire staging site using HTTP basic authentication.

**Create password file:**
```bash
sudo htpasswd -c /etc/nginx/.htpasswd staging-user
# Enter password when prompted
```

**Enable in nginx:**
Uncomment the auth_basic lines in `staging-nginx.conf`:

```nginx
auth_basic "Staging Environment - Restricted Access";
auth_basic_user_file /etc/nginx/.htpasswd;
```

**Reload nginx:**
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 5. Verify Staging Detection

The system auto-detects staging environments by hostname patterns:
- `staging.*`
- `stage.*`
- `test.*`
- `dev.*`
- `*.staging.*`

If your staging domain matches any of these patterns, staging mode will be automatically enabled.

You can also verify by checking HTTP headers:

```bash
curl -I https://staging.myeventlane.com
```

You should see:
```
X-Robots-Tag: noindex, nofollow, noarchive, nosnippet
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
```

### 6. Test robots.txt

Verify that robots.txt blocks crawlers:

```bash
curl https://staging.myeventlane.com/robots.txt
```

Should return:
```
User-agent: *
Disallow: /
```

## Verification Checklist

- [ ] `robots.txt` deployed to staging and blocks all crawlers
- [ ] Nginx configuration includes `staging-nginx.conf`
- [ ] HTTP headers include `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`
- [ ] HTTP headers include `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
- [ ] Environment variable `STAGING_ENVIRONMENT=1` set (optional)
- [ ] HTTP basic authentication configured (optional)
- [ ] Tested with `curl -I` to verify headers
- [ ] Tested robots.txt accessibility

## Additional Security Recommendations

1. **IP Whitelisting**: Consider restricting access to specific IP addresses in nginx:
   ```nginx
   allow 1.2.3.4;  # Your office IP
   allow 5.6.7.8;  # Your home IP
   deny all;
   ```

2. **Firewall Rules**: Use UFW or iptables to restrict access:
   ```bash
   sudo ufw allow from 1.2.3.4 to any port 80,443
   ```

3. **SSL/TLS**: Always use HTTPS for staging (required for secure cookies)

4. **Regular Updates**: Keep staging environment updated with security patches

5. **Monitoring**: Set up monitoring to alert on unauthorized access attempts

## Troubleshooting

### Headers Not Appearing

1. Check that `StagingSecuritySubscriber` is registered in `myeventlane_core.services.yml`
2. Clear Drupal cache: `ddev drush cr` (or `drush cr` on VPS)
3. Verify staging detection logic in `settings.php`
4. Check nginx error logs: `sudo tail -f /var/log/nginx/error.log`

### robots.txt Not Working

1. Verify file is in `web/robots.txt` (not `web/sites/default/robots.txt`)
2. Check file permissions: `chmod 644 web/robots.txt`
3. Test with: `curl https://staging.myeventlane.com/robots.txt`

### Staging Detection Not Working

1. Check environment variable is set correctly
2. Verify hostname matches staging patterns
3. Check `settings.php` staging detection code
4. Review Drupal logs: `ddev drush watchdog-show` (or `drush wd-show` on VPS)

## Support

For issues or questions, refer to:
- Drupal documentation: https://www.drupal.org/docs
- Nginx documentation: https://nginx.org/en/docs/
- MyEventLane architecture documentation

