# Bandwith monitor

A full-stack PoP bandwith monitoring system. Collects per-interface throughput from Linux routers via SSH, stores data in mySQL and servers a real-time web dashboard.

## Architecture

[ Linux Routers ] ──SSH──► [ Python Collector ] ──► [ MySQL ]
│
[ PHP/Apache API + Frontend ]
│
[ Browser (JS/Chart.js) ]

## Prerequisites

| Component     | Version     |
|---------------|-------------|
| PHP           | 8.1+        |
| MySQL/MariaDB | 8.0+ / 10.6+|
| Python        | 3.9+        |
| Apache        | 2.4+        |
| mod_rewrite   | enabled     |

## Quick Start

### 1. Database

```bash
mysql -u root -p < sql/schema.sql
```

```bash
CREATE USER 'bm_user'@'localhost' IDENTIFIED BY 'strongpassword';
GRANT SELECT, INSERT, UPDATE, DELETE ON bandwidth_monitor.* TO 'bm_user'@'localhost';
FLUSH PRIVILEGES;
```
### 2. PHP Configuration

```bash
cp config/config.php config/config.local.php
# Edit config/config.local.php with your DB credentials and pepper

# Generate a proper pepper:
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

```bash
# Set the admin password:

php -r "
require 'config/config.php';
\$hash = password_hash(
    hash_hmac('sha256', 'YOUR_NEW_PASSWORD', AUTH_PEPPER),
    PASSWORD_ARGON2ID,
    ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]
);
echo \$hash . PHP_EOL;
"

# Then: UPDATE users SET password_hash='<hash>' WHERE username='admin';
```
### 3. Apache VirtualHost

```text
<VirtualHost *:80>
    ServerName bwmon.example.com
    DocumentRoot /var/www/bandwidth-monitor
    
    <Directory /var/www/bandwidth-monitor>
        AllowOverride All
        Require all granted
    </Directory>
    
    # Block sensitive dirs at Apache level too
    <DirectoryMatch "/(config|lib|backend/collector|systemd)">
        Require all denied
    </DirectoryMatch>
</VirtualHost>
```

### 4. Python Collector

```bash
cd backend/collector
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt

# Edit ../../config/collector_config.json

# One-time: discover and register interfaces from all DB routers
python setup_interfaces.py

# Run the collector
python collector.py
```

### 5. systemd Service (recommended)

```bash
cp systemd/bandwidth-collector.service /etc/systemd/system/
# Edit WorkingDirectory and ExecStart paths
systemctl daemon-reload
systemctl enable --now bandwidth-collector
```

# API Endpoints

| Method | Path                                 | Auth | Description                |
| ------ | ------------------------------------ | ---- | -------------------------- |
| POST   | /api/login                           | No   | Login, returns session     |
| POST   | /api/logout                          | Yes  | Destroy session            |
| GET    | /api/pops                            | Yes  | PoPs + routers tree        |
| GET    | /api/interfaces?router_id=N          | Yes  | Interfaces for router      |
| GET    | /api/interface/{id}/live             | Yes  | Latest Mbps sample         |
| GET    | /api/interface/{id}/history?range=5m | Yes  | Time-series (5m/1h/24h/7d) |