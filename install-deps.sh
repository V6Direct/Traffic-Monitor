#!/usr/bin/env bash
# install-deps.sh — Bandwidth Monitor full dependency installer
# Target: Debian 12 (Bookworm)
# Run as root: bash install-deps.sh

set -euo pipefail

echo "==> Updating package index..."
apt-get update -y

echo "==> Installing system packages..."
apt-get install -y \
    # ── Web server ──────────────────────────────────────────
    apache2 \
    # ── PHP 8.2 + required extensions ───────────────────────
    php8.2 \
    php8.2-cli \
    php8.2-fpm \
    php8.2-mysql \
    php8.2-mbstring \
    php8.2-json \
    php8.2-opcache \
    libapache2-mod-php8.2 \
    # ── MariaDB (MySQL-compatible, Debian 12 default) ────────
    mariadb-server \
    mariadb-client \
    # ── Python 3.11 + pip + venv ─────────────────────────────
    python3 \
    python3-pip \
    python3-venv \
    python3-dev \
    # ── SSH client (for manual testing) ──────────────────────
    openssh-client \
    # ── Build tools (needed by some pip packages) ────────────
    build-essential \
    libssl-dev \
    libffi-dev \
    # ── Misc utilities ───────────────────────────────────────
    curl \
    git \
    unzip

echo "==> Enabling Apache modules..."
a2enmod rewrite
a2enmod headers
a2enmod php8.2

echo "==> Starting and enabling services..."
systemctl enable --now apache2
systemctl enable --now mariadb

echo "==> Securing MariaDB (sets root password, removes test DBs)..."
mysql_secure_installation

echo "==> Installing Python dependencies into virtualenv..."
PROJECT_DIR="/var/www/bandwidth-monitor"
VENV_DIR="${PROJECT_DIR}/backend/collector/venv"

# Create venv only if project dir exists already.
if [ -d "${PROJECT_DIR}/backend/collector" ]; then
    python3 -m venv "${VENV_DIR}"
    "${VENV_DIR}/bin/pip" install --upgrade pip
    "${VENV_DIR}/bin/pip" install \
        paramiko==4.0.0 \
        mysql-connector-python==8.4.0
    echo "==> Python venv ready at ${VENV_DIR}"
else
    echo "!  Project not deployed yet — run pip install manually after deployment:"
    echo "   cd ${PROJECT_DIR}/backend/collector"
    echo "   python3 -m venv venv && source venv/bin/activate"
    echo "   pip install -r requirements.txt"
fi

echo ""
echo "============================================"
echo " Installed versions:"
echo "============================================"
apache2    -v           | head -1
php8.2     --version    | head -1
python3    --version
mysql      --version
echo "============================================"
echo " Done. Next steps:"
echo "  1. Deploy project to ${PROJECT_DIR}"
echo "  2. Run: mysql -u root -p < sql/schema.sql"
echo "  3. Configure config/config.php and config/collector_config.json"
echo "  4. Set Apache DocumentRoot to ${PROJECT_DIR}"
echo "  5. systemctl enable --now bandwidth-collector"
echo "============================================"
