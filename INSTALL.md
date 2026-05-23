# Laskie Rental Property Management System
## Installation Guide — Debian 13 + Apache + MySQL + FileZilla

---

## REQUIREMENTS

| Component | Version |
|-----------|---------|
| Debian    | 12 / 13 |
| Apache    | 2.4+    |
| PHP       | 8.1+    |
| MySQL     | 8.0+ (or MariaDB 10.6+) |
| FileZilla | Any     |

---

## STEP 1 — Install LAMP Stack (SSH as root or sudo user)

```bash
# Update system
apt update && apt upgrade -y

# Install Apache
apt install apache2 -y

# Install PHP 8.2 + required extensions
apt install php8.2 php8.2-mysql php8.2-mbstring php8.2-xml \
    php8.2-curl php8.2-gd php8.2-zip libapache2-mod-php8.2 -y

# Install MySQL
apt install mysql-server -y

# Install Chromium — required for PDF generation
# (Statement of Account, Audit Report downloads use chromium --headless --print-to-pdf)
apt install chromium -y

# Install Composer — required to run the PHPUnit test suite
apt install composer -y

# Enable Apache modules
a2enmod rewrite headers expires deflate
systemctl restart apache2
```

> **PDF generator note:** the SoA / audit PDF downloads invoke `/usr/bin/chromium`
> in-process. If your distro packages it under a different name (e.g.
> `chromium-browser`, `google-chrome`) the app auto-falls-back to those paths.
> To use a custom path, set `chromium_path` in the `settings` table:
> ```sql
> INSERT INTO settings (setting_key, setting_value)
> VALUES ('chromium_path', '/usr/local/bin/chrome')
> ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
> ```

---

## STEP 2 — Create MySQL Database & User

```bash
# Open MySQL as root
mysql -u root -p
```

Inside MySQL shell:

```sql
CREATE DATABASE laskie_rental CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'laskie_db_user'@'localhost' IDENTIFIED BY 'StrongPassword2024!';

GRANT ALL PRIVILEGES ON laskie_rental.* TO 'laskie_db_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

> **Change `StrongPassword2024!` to your own strong password.**  
> Use the same password in `config/db.php`.

---

## STEP 3 — Import Database Schema

```bash
mysql -u laskie_db_user -p laskie_rental < /var/www/laskie/install.sql
```

Or via phpMyAdmin: select `laskie_rental` → Import → choose `install.sql`.

---

## STEP 4 — Generate self-signed TLS cert + configure Apache vhost

The production deploy runs HTTPS only on a single port (49200 by default).
We use a self-signed cert and accept the browser warning, since exposing
port 80 (for Let's Encrypt HTTP-01) is not in scope here.

### 4a. Generate the cert (one-time, valid 10 years)

```bash
# Replace ahastulog.duckdns.org with your hostname, and 192.168.x.y with
# the LAN IP of the server. Add more IP:... SANs for any other addresses
# clients will use to reach the box.
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
  -subj "/CN=ahastulog.duckdns.org" \
  -addext "subjectAltName=DNS:ahastulog.duckdns.org,DNS:localhost,IP:127.0.0.1,IP:192.168.9.18" \
  -addext "keyUsage=digitalSignature,keyEncipherment" \
  -addext "extendedKeyUsage=serverAuth" \
  -keyout /etc/ssl/private/laskie.key \
  -out /etc/ssl/certs/laskie.crt

sudo chmod 600 /etc/ssl/private/laskie.key
sudo a2enmod ssl
```

### 4b. Trust the cert on each client device

Because the cert is self-signed, every browser will show a warning on
first visit. Either click through each time, or import the cert once
per device:

- **Linux / Chrome** — copy `/etc/ssl/certs/laskie.crt` to the device and
  open `chrome://settings/certificates` → Authorities → Import.
- **Android** — copy the `.crt` file to the phone, open it, choose
  "VPN and apps" category. (Some Android versions: Settings → Security →
  Install from storage.)
- **Windows** — double-click the `.crt` → Install Certificate →
  Local Machine → "Trusted Root Certification Authorities".
- **macOS / iOS** — install via Keychain Access (macOS) or Profiles (iOS),
  then mark "Always Trust" for `ahastulog.duckdns.org`.

### 4c. Vhost config

```bash
sudo nano /etc/apache2/sites-available/laskie.conf
```

```apache
<VirtualHost *:49200>
    ServerName ahastulog.duckdns.org
    DocumentRoot /var/www/laskie
    DirectoryIndex index.php

    SSLEngine on
    SSLCertificateFile      /etc/ssl/certs/laskie.crt
    SSLCertificateKeyFile   /etc/ssl/private/laskie.key
    SSLProtocol             all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite          ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256
    SSLHonorCipherOrder     on

    <Directory /var/www/laskie>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny direct access to sensitive dirs
    <Directory /var/www/laskie/config>
        Require all denied
    </Directory>
    <Directory /var/www/laskie/includes>
        Require all denied
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/laskie_error.log
    CustomLog ${APACHE_LOG_DIR}/laskie_access.log combined
</VirtualHost>
```

Also edit `/etc/apache2/ports.conf` to only listen on the single port:

```
Listen 49200
```

> **`.htaccess` already sets `session.cookie_secure 1`** — sessions will only
> be sent over HTTPS. If you ever revert to plain HTTP, flip that back to 0
> or logins will silently break.

Enable the site:

```bash
a2ensite laskie.conf
a2dissite 000-default.conf   # optional: disable default site
systemctl reload apache2
```

---

## STEP 5 — Upload Files via FileZilla

### FileZilla Connection Settings
| Field    | Value                        |
|----------|------------------------------|
| Host     | Your server IP or hostname   |
| Username | `bulik`                      |
| Password | Your SSH password            |
| Port     | 22                           |
| Protocol | SFTP – SSH File Transfer Protocol |

### Steps
1. Open FileZilla
2. File → Site Manager → New Site → fill in the settings above
3. Connect
4. On the **Remote** panel, navigate to `/var/www/`
5. On the **Local** panel, navigate to the `laskie/` folder you downloaded
6. Drag the entire `laskie/` folder into `/var/www/`
7. Wait for transfer to complete

---

## STEP 6 — Set File Permissions (SSH)

```bash
# Set ownership to Apache web user
chown -R www-data:www-data /var/www/laskie

# Directories: 755, Files: 644
find /var/www/laskie -type d -exec chmod 755 {} \;
find /var/www/laskie -type f -exec chmod 644 {} \;

# Uploads directory needs write permission
chmod -R 775 /var/www/laskie/uploads

# If bulik user needs to manage files too:
usermod -aG www-data bulik
```

---

## STEP 7 — Update Database Config

Edit `config/db.php` on the server (or before uploading):

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'laskie_rental');
define('DB_USER', 'laskie_db_user');     // match Step 2
define('DB_PASS', 'StrongPassword2024!'); // match Step 2
```

```bash
# Edit directly on server
nano /var/www/laskie/config/db.php
```

---

## STEP 8 — First Login

Open your browser and go to:

```
http://YOUR_SERVER_IP/laskie/
```

Or if you set `ServerName laskie.local`, add to `/etc/hosts` on your PC:

```
192.168.x.x    laskie.local
```

Then visit: `http://laskie.local/`

**Default credentials:**

| Field    | Value         |
|----------|---------------|
| Username | `admin`       |
| Password | `Admin@2024`  |

> ⚠️ **Change the password immediately** after first login:  
> Go to **Admin → Accounts → Edit your account → set new password**

---

## STEP 8.5 — Install Composer dependencies + run tests (developers only)

Skip on production hosts that don't run the test suite.

```bash
cd /var/www/laskie
composer install

# Fast Unit suite — pure PHP, no DB needed (~50ms)
vendor/bin/phpunit
# Or via the composer script:
composer test-unit

# Integration suite — needs the DB connection from config/db.php (~2s)
composer test-integration

# Everything (Unit + Integration)
vendor/bin/phpunit --testsuite Unit --testsuite Integration
```

Integration tests tag every row they insert with the marker
`PHPUNIT_INTEGRATION_TEST` and clean them up in tearDown(), so the suite
is safe to run repeatedly against the live DB without leaving residue.

---

## STEP 9 — Initial System Setup (after login)

Do these in order immediately after first login:

1. **Admin → Accounts** — Change the default admin password. Add your staff accounts.
2. **Admin → Units → Unit Types** — Review/add your property types (Room, Apartment, etc.)
3. **Admin → Units → Rental Units** — Add all your rental units with correct rates and due days
4. **Admin → Units → Service Types** — Set default amounts for deposits, late fees, parking, etc.
5. **Admin → Tenants** — Add all current tenants, link them to their units, upload contracts
6. **Settings** (via DB or future settings page) — Update company name, address, phone, email in the `settings` table

Update company info directly in MySQL:

```sql
UPDATE settings SET setting_value='Your Company Name'   WHERE setting_key='company_name';
UPDATE settings SET setting_value='Your Address'        WHERE setting_key='company_address';
UPDATE settings SET setting_value='09xx-xxx-xxxx'       WHERE setting_key='company_phone';
UPDATE settings SET setting_value='email@yourdomain.com' WHERE setting_key='company_email';
```

---

## STEP 10 — Brute-force defences (3 layers)

The login form has three independent throttles. Each works on its own; combined,
they put a sharp cap on credential-stuffing.

### 10a. App-level lockout (already shipped in `index.php`)

After **5 failed attempts** from the same IP **or** the same username within a
**15-minute window**, the form rejects further attempts with
*"Too many failed attempts. Try again in 15 minutes."* The window resets on
the next successful `LOGIN_SUCCESS` row for that IP/username.

Both thresholds and the window are constants at the top of `index.php`:

```php
const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_WINDOW_MIN = 15;
```

### 10b. Progressive delay (already shipped in `index.php`)

Each consecutive failure adds a 250 ms `usleep` to the response, capped at
1.25 s. A human notices nothing on attempt #1; scripted bursts slow to a crawl.

### 10c. OS-level ban via fail2ban

```bash
sudo apt install fail2ban -y

# Filter — matches POST /index.php with HTTP 200 (failed logins re-render
# the form with 200; successful logins return 302 and so are NOT counted).
sudo tee /etc/fail2ban/filter.d/laskie-login.conf > /dev/null <<'EOF'
[Definition]
failregex = ^<HOST> .* "POST /index\.php HTTP/[\d.]+" 200
ignoreregex =
EOF

# Jail — 10 failures in 10 minutes = 30-minute IP ban at iptables level.
sudo tee /etc/fail2ban/jail.d/laskie.conf > /dev/null <<'EOF'
[laskie-login]
enabled  = true
port     = 49200,http,https
filter   = laskie-login
logpath  = /var/log/apache2/laskie_access.log
maxretry = 10
findtime = 600
bantime  = 1800
backend  = auto
EOF

sudo systemctl restart fail2ban
sudo fail2ban-client status laskie-login   # should show the jail active
```

### Verification

```bash
# Verify recent failed attempts are visible in system_logs
mysql -u laskie_db_user -p laskie_rental \
  -e "SELECT action, username, ip_address, created_at FROM system_logs WHERE action LIKE 'LOGIN%' ORDER BY id DESC LIMIT 10;"

# Tail fail2ban activity
sudo journalctl -u fail2ban -n 20 --no-pager

# Show currently banned IPs (if any)
sudo fail2ban-client status laskie-login
```

To manually unban an IP (e.g. you locked yourself out while testing):

```bash
sudo fail2ban-client set laskie-login unbanip 192.168.x.y
```

To clear the app-level lockout for yourself, log in successfully once from
a clean IP, or insert a `LOGIN_SUCCESS` row directly:

```sql
INSERT INTO system_logs (user_id, username, action, module, details, ip_address)
SELECT id, username, 'LOGIN_SUCCESS', 'Auth', 'Manual reset', '192.168.x.y'
FROM users WHERE username='your_username';
```

---

## DIRECTORY STRUCTURE

```
/var/www/laskie/
├── index.php              ← Login page
├── logout.php
├── dashboard.php          ← Annual dashboard (landing after login)
├── expenses.php           ← Expenses tracking
├── cash.php               ← Cash on hand
├── my_summary.php         ← Per-user summary
├── install.sql            ← Run once to create DB
├── .htaccess              ← Apache security rules
│
├── admin/
│   ├── accounts.php       ← User account management
│   ├── tenants.php        ← Tenant management
│   ├── units.php          ← Units, types, services
│   └── logs.php           ← Master audit logs
│
├── payments/
│   ├── collection.php     ← Payment collection dashboard
│   ├── history.php        ← Statement of account
│   ├── invoice_print.php  ← Printable invoice
│   ├── soa_pdf.php        ← PDF statement of account
│   └── api_payment.php    ← Payment API
│
├── api/
│   ├── expenses_api.php   ← Expenses API
│   └── cash_api.php       ← Cash on hand API
│
├── config/
│   ├── db.php             ← Database credentials
│   └── functions.php      ← Core helpers
│
├── includes/
│   ├── header.php         ← Sidebar + topbar layout
│   └── footer.php         ← JS includes + closing tags
│
├── assets/
│   ├── css/app.css        ← Design system
│   └── js/app.js          ← Shared JavaScript
│
└── uploads/               ← User-uploaded files (writable)
    ├── contracts/
    ├── receipts/
    ├── docs/
    └── remittance/
```

---

## TROUBLESHOOTING

| Problem | Fix |
|---------|-----|
| White/blank page | Check Apache error log: `tail -f /var/log/apache2/laskie_error.log` |
| "Database connection failed" | Check `config/db.php` credentials and MySQL is running: `systemctl status mysql` |
| Upload fails | Check `uploads/` is writable: `chmod -R 775 /var/www/laskie/uploads && chown -R www-data:www-data /var/www/laskie/uploads` |
| 403 Forbidden | Verify `AllowOverride All` in VirtualHost and `mod_rewrite` is enabled: `a2enmod rewrite` |
| Login not working | Confirm `install.sql` was imported — check `users` table exists |
| Session expires fast | Add `php_value session.gc_maxlifetime 7200` to `.htaccess` |
| FileZilla permission denied | Make sure `bulik` is in `www-data` group: `usermod -aG www-data bulik` then re-login |
| .htaccess not working | Ensure `AllowOverride All` is set and `mod_rewrite` enabled |

---

## BACKUP RECOMMENDATION

```bash
# Database backup (run daily via cron)
mysqldump -u laskie_db_user -p laskie_rental > /home/bulik/backups/laskie_$(date +%Y%m%d).sql

# Files backup
tar -czf /home/bulik/backups/laskie_files_$(date +%Y%m%d).tar.gz /var/www/laskie/uploads

# Cron job (daily at 2am)
crontab -e
# Add: 0 2 * * * mysqldump -u laskie_db_user -pStrongPassword2024! laskie_rental > /home/bulik/backups/laskie_$(date +\%Y\%m\%d).sql
```

---

## ROLE PERMISSIONS SUMMARY

| Feature              | Admin | Accountant | Staff |
|----------------------|:-----:|:----------:|:-----:|
| Dashboard            | ✓     | ✓          | ✓     |
| Payment Collection   | ✓     | ✓          | ✓     |
| Statement of Account | ✓     | ✓          | ✓     |
| Expenses             | ✓     | ✓          | ✓     |
| Cash on Hand         | ✓     | ✓          | ✓     |
| My Summary           | ✓     | ✓          | ✓     |
| Manage Accounts      | ✓     | ✗          | ✗     |
| Manage Tenants       | ✓     | ✗          | ✗     |
| Manage Units         | ✓     | ✗          | ✗     |
| Audit Logs           | ✓     | ✗          | ✗     |
| Delete Payments      | ✓     | ✗          | ✗     |
| Delete Expenses      | ✓     | ✗          | ✗     |
| Manage Categories    | ✓     | ✗          | ✗     |

---

*Laskie Rental Property Management System — v1.0.0*
