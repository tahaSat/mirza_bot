#!/bin/bash
#
# Mirza Bot installer — updated for the Pro fork (root deploy, polling/webhook).
# Default install path: /var/www/mirza_bot
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MIRZA_BOT_DIR="${MIRZA_BOT_DIR:-/var/www/mirza_bot}"
MIRZA_LEGACY_DIR="/var/www/html/mirzabotconfig"
MIRZA_APACHE_SITE="${MIRZA_APACHE_SITE:-mirza_bot}"
MIRZA_CONF_DIR="/root/confmirza"
SUPERVISOR_PROG="mirza-polling"
DEFAULT_DB_NAME="mirza_pr"
MIRZA_GIT_REPO="${MIRZA_GIT_REPO:-https://github.com/tahaSat/mirza_bot.git}"

if [[ $EUID -ne 0 ]]; then
    echo -e "\033[31m[ERROR]\033[0m Please run this script as \033[1mroot\033[0m."
    exit 1
fi

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

resolve_bot_dir() {
    if [[ -f "$MIRZA_BOT_DIR/config.php" ]]; then
        echo "$MIRZA_BOT_DIR"
    elif [[ -f "$MIRZA_LEGACY_DIR/config.php" ]]; then
        echo "$MIRZA_LEGACY_DIR"
    else
        echo "$MIRZA_BOT_DIR"
    fi
}

get_config_path() {
    echo "$(resolve_bot_dir)/config.php"
}

get_app_version() {
    local bot_dir version_file
    bot_dir="$(resolve_bot_dir)"
    version_file="$bot_dir/version"
    if [[ -f "$version_file" ]]; then
        tr -d '\r\n' < "$version_file"
    else
        echo "dev"
    fi
}

get_domain_from_config() {
    local config="${1:-$(get_config_path)}"
    if [[ ! -f "$config" ]]; then
        return 1
    fi
    grep '^\$domainhosts' "$config" | head -1 | sed -E "s/.*= *['\"]([^'\"]*)['\"].*/\1/" | sed 's|/.*||'
}

config_uses_polling() {
    local config="${1:-$(get_config_path)}"
    [[ -f "$config" ]] && grep -qE '^\$telegram_polling_mode\s*=\s*true' "$config"
}

https_base_url() {
    local domain="$1"
    if [[ "$domain" == *:* ]]; then
        echo "https://${domain}"
    else
        echo "https://${domain}"
    fi
}

run_step() {
    local label="$1"
    shift
    echo -e "\033[36m→ ${label}\033[0m"
    "$@"
}

die() {
    echo -e "\033[31m[ERROR]\033[0m $*" >&2
    exit 1
}

prompt_yes_no() {
    local prompt="$1" default="${2:-n}" reply
    read -r -p "$prompt [y/N]: " reply
    reply="${reply:-$default}"
    [[ "$reply" =~ ^[Yy]$ ]]
}

ask_telegram_update_mode() {
    echo ""
    echo -e "\033[1;36mTelegram update delivery\033[0m"
    echo "  1) Webhook — Telegram POSTs to https://your-domain/index.php (needs public HTTPS)"
    echo "  2) Polling  — long-polling daemon via Supervisor (for blocked/restricted networks)"
    echo ""
    local choice
    while true; do
        read -r -p "Select mode [1/2] (default: 1): " choice
        choice="${choice:-1}"
        case "$choice" in
            1) TELEGRAM_POLLING_MODE="false"; TELEGRAM_MODE_LABEL="webhook"; return 0 ;;
            2) TELEGRAM_POLLING_MODE="true"; TELEGRAM_MODE_LABEL="polling"; return 0 ;;
            *) echo -e "\033[31mInvalid choice.\033[0m" ;;
        esac
    done
}

ask_optional_telegram_proxy() {
    TELEGRAM_PROXY=""
    TELEGRAM_PROXY_TYPE="socks5"
    TELEGRAM_PROXIES_PHP=""
    if prompt_yes_no "Configure SOCKS/HTTP proxy for outbound Telegram API calls?"; then
        read -r -p "Primary proxy host:port [127.0.0.1:51349]: " TELEGRAM_PROXY
        TELEGRAM_PROXY="${TELEGRAM_PROXY:-127.0.0.1:51349}"
        read -r -p "Proxy type (socks5/http) [socks5]: " TELEGRAM_PROXY_TYPE
        TELEGRAM_PROXY_TYPE="${TELEGRAM_PROXY_TYPE:-socks5}"
        TELEGRAM_PROXIES_PHP=$(cat <<EOF
\$telegram_proxies = [
    ['name' => 'primary', 'proxy' => '${TELEGRAM_PROXY}', 'type' => '${TELEGRAM_PROXY_TYPE}'],
];
EOF
)
    else
        TELEGRAM_PROXIES_PHP='$telegram_proxies = [];'
    fi
}

write_mirza_config() {
    local config_path="$1"
    local bot_token="$2"
    local chat_id="$3"
    local bot_name="$4"
    local db_host="$5"
    local db_name="$6"
    local db_user="$7"
    local db_pass="$8"
    local domain="$9"
    local polling_mode="${10}"

    local polling_php="false"
    [[ "$polling_mode" == "true" ]] && polling_php="true"

    local proxy_line=""
    if [[ -n "${TELEGRAM_PROXY:-}" ]]; then
        proxy_line="\$telegram_proxy = '${TELEGRAM_PROXY}';
\$telegram_proxy_type = '${TELEGRAM_PROXY_TYPE}';"
    else
        proxy_line="\$telegram_proxy = '';
\$telegram_proxy_type = 'socks5';"
    fi

    cat > "$config_path" <<EOF
<?php
\$request_exec_timeout = null;
\$dbhost = '${db_host}';
\$dbname = '${db_name}';
\$usernamedb = '${db_user}';
\$passworddb = '${db_pass}';
\$connect = mysqli_connect(\$dbhost, \$usernamedb, \$passworddb, \$dbname);
if (\$connect->connect_error) { die("error" . \$connect->connect_error); }
mysqli_set_charset(\$connect, "utf8mb4");
\$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
\$dsn = "mysql:host=\$dbhost;dbname=\$dbname;charset=utf8mb4";
try { \$pdo = new PDO(\$dsn, \$usernamedb, \$passworddb, \$options); } catch (\\PDOException \$e) { error_log("Database connection failed: " . \$e->getMessage()); }
\$APIKEY = '${bot_token}';
\$adminnumber = '${chat_id}';
\$domainhosts = '${domain}';
\$usernamebot = '${bot_name}';
${proxy_line}
${TELEGRAM_PROXIES_PHP}
\$telegram_proxy_retry_once = true;
\$telegram_proxy_failover_cooldown_sec = 3;
\$telegram_proxy_healthcheck_timeout_sec = 6;
\$telegram_proxy_prefer_primary_interval_sec = 0;
\$telegram_proxy_state_file = __DIR__ . '/storage/cache/telegram_proxy_state.json';
\$telegram_polling_mode = ${polling_php};
\$telegram_polling_async = true;
\$telegram_local_bot_url = 'http://127.0.0.1/index.php';
\$telegram_polling_debug = false;
\$telegram_polling_log_file = __DIR__ . '/logs/polling.log';
\$telegram_polling_worker_log_file = __DIR__ . '/logs/polling.worker.log';
\$telegram_polling_slow_panel_ms = 3000;
?>
EOF
}

set_bot_permissions() {
    local bot_dir="$1"
    mkdir -p "$bot_dir/logs" "$bot_dir/storage/cache" "$bot_dir/storage/logs"
    chown -R www-data:www-data "$bot_dir"
    chmod -R 755 "$bot_dir"
    chmod -R 775 "$bot_dir/logs" "$bot_dir/storage" "$bot_dir/cronbot" 2>/dev/null || true
}

configure_apache_vhost() {
    local domain="$1"
    local bot_dir="$2"
    local ssl_port="${3:-443}"
    local site_conf="/etc/apache2/sites-available/${MIRZA_APACHE_SITE}.conf"

    if [[ "$ssl_port" == "443" ]]; then
        cat > "$site_conf" <<EOF
<VirtualHost *:80>
    ServerName ${domain}
    DocumentRoot ${bot_dir}
    <Directory ${bot_dir}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/mirza_bot_error.log
    CustomLog \${APACHE_LOG_DIR}/mirza_bot_access.log combined
</VirtualHost>
EOF
        a2dissite 000-default.conf 2>/dev/null || true
        a2ensite "${MIRZA_APACHE_SITE}.conf"
        a2enmod rewrite ssl headers 2>/dev/null || true
        systemctl reload apache2
        certbot --apache --agree-tos --non-interactive --redirect -d "$domain" || die "Certbot failed for ${domain}"
    else
        # Marzban: Apache HTTPS on alternate port (e.g. 88)
        cat > "$site_conf" <<EOF
<VirtualHost *:80>
    ServerName ${domain}
    DocumentRoot ${bot_dir}
    <Directory ${bot_dir}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF
        a2ensite "${MIRZA_APACHE_SITE}.conf"
        a2enmod rewrite ssl headers 2>/dev/null || true
        systemctl reload apache2
        certbot --apache --agree-tos --non-interactive --https-port "$ssl_port" --no-redirect -d "$domain" || die "Certbot failed on port ${ssl_port}"

        local ssl_conf="/etc/apache2/sites-available/${MIRZA_APACHE_SITE}-ssl.conf"
        cat > "$ssl_conf" <<EOF
<IfModule mod_ssl.c>
<VirtualHost *:${ssl_port}>
    ServerName ${domain}
    DocumentRoot ${bot_dir}
    <Directory ${bot_dir}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/${domain}/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/${domain}/privkey.pem
    ErrorLog \${APACHE_LOG_DIR}/mirza_bot_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/mirza_bot_ssl_access.log combined
</VirtualHost>
</IfModule>
EOF
        a2ensite "${MIRZA_APACHE_SITE}-ssl.conf"
        a2dissite 000-default.conf 2>/dev/null || true
        apache2ctl configtest || die "Apache config test failed"
        systemctl reload apache2
    fi
}

resolve_git_repo_url() {
    local prompt="${1:-Git repository URL}"
    local git_url
    read -r -p "${prompt} [${MIRZA_GIT_REPO}]: " git_url
    git_url="${git_url:-$MIRZA_GIT_REPO}"
    [[ -n "$git_url" ]] || die "No git repository URL configured."
    echo "$git_url"
}

clone_mirza_repo() {
    local git_url="$1"
    local temp="/tmp/mirza_clone_$$"
    rm -rf "$temp"
    echo -e "\033[36mCloning ${git_url} ...\033[0m" >&2
    git clone --depth 1 "$git_url" "$temp" || die "git clone failed for ${git_url}"
    echo "$temp"
}

deploy_bot_files() {
    local dest="$1"
    local source=""

    if [[ -f "${SCRIPT_DIR}/index.php" && -f "${SCRIPT_DIR}/function.php" ]]; then
        if prompt_yes_no "Install from local source at ${SCRIPT_DIR}?" "n"; then
            source="$SCRIPT_DIR"
        fi
    fi

    if [[ -z "$source" ]]; then
        local git_url temp
        git_url="$(resolve_git_repo_url)"
        temp="$(clone_mirza_repo "$git_url")"
        source="$temp"
    fi

    mkdir -p "$dest"
    if [[ "$(readlink -f "$source")" == "$(readlink -f "$dest")" ]]; then
        echo -e "\033[33mSource and install directory are the same — keeping existing files.\033[0m"
        return 0
    fi

    rsync -a --delete \
        --exclude 'config.php' \
        --exclude '.git' \
        --exclude 'logs/' \
        --exclude 'storage/cache/' \
        "$source/" "$dest/" || die "Failed to copy bot files to ${dest}"

    if [[ "$source" == /tmp/mirza_clone_* ]]; then
        rm -rf "$source"
    fi
}

install_system_packages() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y || true
    apt-get install -y software-properties-common git unzip curl wget jq rsync ufw supervisor || die "Failed to install base packages"

    if ! dpkg -s apache2 &>/dev/null; then
        apt-get install -y apache2 libapache2-mod-php || die "Failed to install Apache"
    fi

  apt-get install -y php php-mysql php-mbstring php-zip php-gd php-curl php-soap php-ssh2 libssh2-1-dev libssh2-1 || die "Failed to install PHP extensions"

    if ! dpkg -s mysql-server &>/dev/null; then
        apt-get install -y mysql-server || die "Failed to install MySQL"
    fi

    apt-get install -y certbot python3-certbot-apache || die "Failed to install Certbot"

    a2enmod rewrite ssl headers 2>/dev/null || true
    systemctl enable apache2 mysql supervisor 2>/dev/null || true
    systemctl start apache2 mysql 2>/dev/null || true

    ufw allow OpenSSH 2>/dev/null || true
    ufw allow 80/tcp 2>/dev/null || true
    ufw allow 443/tcp 2>/dev/null || true
}

ensure_mysql_root_credentials() {
    if [[ -f "${MIRZA_CONF_DIR}/dbrootmirza.txt" ]]; then
        return 0
    fi
    mkdir -p "$MIRZA_CONF_DIR"
    local randomdbpasstxt
    randomdbpasstxt=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-12)
    cat > "${MIRZA_CONF_DIR}/dbrootmirza.txt" <<EOF
\$user = 'root';
\$pass = '${randomdbpasstxt}';
EOF
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${randomdbpasstxt}'; FLUSH PRIVILEGES;" 2>/dev/null || true
}

get_mysql_root_password() {
    grep '\$pass' "${MIRZA_CONF_DIR}/dbrootmirza.txt" | cut -d"'" -f2
}

setup_telegram_webhook() {
    local bot_token="$1"
    local domain="$2"
    local url
    url="$(https_base_url "$domain")/index.php"
    echo -e "\033[36mSetting webhook → ${url}\033[0m"
    local resp
    resp=$(curl -sS -X POST "https://api.telegram.org/bot${bot_token}/setWebhook" \
        -d "url=${url}" \
        -d 'allowed_updates=["message","callback_query","channel_post","pre_checkout_query","inline_query","chat_member","my_chat_member"]')
    echo "$resp" | grep -q '"ok":true' || die "setWebhook failed: ${resp}"
}

remove_telegram_webhook() {
    local bot_token="$1"
    curl -sS -X POST "https://api.telegram.org/bot${bot_token}/deleteWebhook" >/dev/null || true
}

setup_polling_supervisor() {
    local bot_dir="$1"
    local php_bin
    php_bin="$(command -v php)"

    cat > "/etc/supervisor/conf.d/${SUPERVISOR_PROG}.conf" <<EOF
[program:${SUPERVISOR_PROG}]
command=${php_bin} ${bot_dir}/polling.php
directory=${bot_dir}
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
redirect_stderr=true
stdout_logfile=${bot_dir}/logs/supervisor-polling.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
EOF

    supervisorctl reread
    supervisorctl update
    supervisorctl restart "${SUPERVISOR_PROG}" 2>/dev/null || supervisorctl start "${SUPERVISOR_PROG}"
}

stop_polling_supervisor() {
    if [[ -f "/etc/supervisor/conf.d/${SUPERVISOR_PROG}.conf" ]]; then
        supervisorctl stop "${SUPERVISOR_PROG}" 2>/dev/null || true
        rm -f "/etc/supervisor/conf.d/${SUPERVISOR_PROG}.conf"
        supervisorctl reread 2>/dev/null || true
        supervisorctl update 2>/dev/null || true
    fi
}

run_table_setup() {
    local domain="$1"
    local url
    url="$(https_base_url "$domain")/table.php"
    echo -e "\033[36mRunning database setup → ${url}\033[0m"
    curl -fsS "$url" >/dev/null || die "table.php failed — check Apache, PHP, and database credentials"
}

send_install_notification() {
    local bot_token="$1"
    local chat_id="$2"
    local domain="$3"
    local mode="$4"
    curl -sS -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" \
        -d "chat_id=${chat_id}" \
        -d "text=✅ Mirza Bot installed (${mode}) on ${domain}. Send /start to test." >/dev/null || true
}

link_installer_command() {
    local installer="${SCRIPT_DIR}/install.sh"
    [[ -f "$installer" ]] || installer="$(resolve_bot_dir)/install.sh"
    [[ -f "$installer" ]] || return 0
    cp -f "$installer" /root/install.sh
    chmod +x /root/install.sh
    ln -sfn /root/install.sh /usr/local/bin/mirza
}

# ---------------------------------------------------------------------------
# Status / menu
# ---------------------------------------------------------------------------

check_ssl_status() {
    local config domain
    config="$(get_config_path)"
    if [[ ! -f "$config" ]]; then
        echo -e "\033[33m⚠️ Cannot check SSL: config not found\033[0m"
        return
    fi
    domain="$(get_domain_from_config "$config")"
    if [[ -n "$domain" && -f "/etc/letsencrypt/live/${domain}/cert.pem" ]]; then
        local expiry_date current_date expiry_timestamp days_remaining
        expiry_date=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/${domain}/cert.pem" | cut -d= -f2)
        current_date=$(date +%s)
        expiry_timestamp=$(date -d "$expiry_date" +%s)
        days_remaining=$(( (expiry_timestamp - current_date) / 86400 ))
        if [[ $days_remaining -gt 0 ]]; then
            echo -e "\033[32m✅ SSL: ${days_remaining} days left (${domain})\033[0m"
        else
            echo -e "\033[31m❌ SSL expired (${domain})\033[0m"
        fi
    else
        echo -e "\033[33m⚠️ SSL not found for ${domain}\033[0m"
    fi
}

check_bot_status() {
    local bot_dir config
    bot_dir="$(resolve_bot_dir)"
    config="${bot_dir}/config.php"
    if [[ -f "$config" ]]; then
        echo -e "\033[32m✅ Bot installed at ${bot_dir}\033[0m"
        if config_uses_polling "$config"; then
            echo -e "\033[36m   Mode: polling\033[0m"
            supervisorctl status "${SUPERVISOR_PROG}" 2>/dev/null || echo -e "\033[33m   Supervisor: not running\033[0m"
        else
            echo -e "\033[36m   Mode: webhook\033[0m"
        fi
        check_ssl_status
    else
        echo -e "\033[31m❌ Bot not installed\033[0m"
    fi
}

show_logo() {
    clear
    echo -e "\033[1;34m"
    echo "================================================================================="
    echo "  Mirza Bot Installer (Pro fork)"
    echo "================================================================================="
    echo -e "\033[0m"
    echo -e "\033[1;36mVersion:\033[0m \033[33m$(get_app_version)\033[0m"
    echo -e "\033[1;36mInstall path:\033[0m ${MIRZA_BOT_DIR}"
    echo ""
    echo -e "\033[1;36mStatus:\033[0m"
    check_bot_status
    echo ""
}

show_menu() {
    show_logo
    echo -e "\033[1;36m1)\033[0m Install Mirza Bot"
    echo -e "\033[1;36m2)\033[0m Update Mirza Bot"
    echo -e "\033[1;36m3)\033[0m Remove Mirza Bot"
    echo -e "\033[1;36m4)\033[0m Export Database"
    echo -e "\033[1;36m5)\033[0m Import Database"
    echo -e "\033[1;36m6)\033[0m Configure Automated Backup"
    echo -e "\033[1;36m7)\033[0m Renew SSL Certificates"
    echo -e "\033[1;36m8)\033[0m Change Domain"
    echo -e "\033[1;36m9)\033[0m Switch Telegram mode (webhook ↔ polling)"
    echo -e "\033[1;36m10)\033[0m Exit"
    echo ""
    read -r -p "Select an option [1-10]: " option
    case "$option" in
        1) install_bot ;;
        2) update_bot ;;
        3) remove_bot ;;
        4) export_database ;;
        5) import_database ;;
        6) auto_backup ;;
        7) renew_ssl ;;
        8) change_domain ;;
        9) switch_telegram_mode ;;
        10) echo -e "\033[32mExiting...\033[0m"; exit 0 ;;
        *) echo -e "\033[31mInvalid option.\033[0m"; show_menu ;;
    esac
}

# ---------------------------------------------------------------------------
# Marzban detection
# ---------------------------------------------------------------------------

check_marzban_installed() {
    [[ -f "/opt/marzban/docker-compose.yml" ]]
}

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

prompt_bot_credentials() {
    while [[ ! "${YOUR_BOT_TOKEN:-}" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]]; do
        read -r -p "Bot token: " YOUR_BOT_TOKEN
        [[ "${YOUR_BOT_TOKEN}" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]] || echo -e "\033[31mInvalid token format.\033[0m"
    done
    while [[ ! "${YOUR_CHAT_ID:-}" =~ ^-?[0-9]+$ ]]; do
        read -r -p "Admin Telegram user ID: " YOUR_CHAT_ID
        [[ "${YOUR_CHAT_ID}" =~ ^-?[0-9]+$ ]] || echo -e "\033[31mInvalid chat ID.\033[0m"
    done
    while [[ -z "${YOUR_BOTNAME:-}" ]]; do
        read -r -p "Bot username (without @): " YOUR_BOTNAME
        [[ -n "${YOUR_BOTNAME}" ]] || echo -e "\033[31mUsername required.\033[0m"
    done
}

prompt_domain() {
    while [[ ! "${DOMAIN_NAME:-}" =~ ^[a-zA-Z0-9.-]+$ ]]; do
        read -r -p "Domain (e.g. bot.example.com): " DOMAIN_NAME
        [[ "${DOMAIN_NAME}" =~ ^[a-zA-Z0-9.-]+$ ]] || echo -e "\033[31mInvalid domain.\033[0m"
    done
}

create_app_database() {
    local root_pass="$1"
    local dbname="${2:-$DEFAULT_DB_NAME}"
    local dbuser dbpass

    dbuser=$(openssl rand -base64 8 | tr -dc 'a-zA-Z' | head -c8)
    dbpass=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c12)

    read -r -p "Database name [${dbname}]: " input_db
    dbname="${input_db:-$dbname}"
    read -r -p "Database user [${dbuser}]: " input_user
    dbuser="${input_user:-$dbuser}"
    read -r -s -p "Database password [auto]: " input_pass
    echo
    dbpass="${input_pass:-$dbpass}"

    mysql -u root -p"${root_pass}" -e "CREATE DATABASE IF NOT EXISTS \`${dbname}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -u root -p"${root_pass}" -e "CREATE USER IF NOT EXISTS '${dbuser}'@'localhost' IDENTIFIED BY '${dbpass}';"
    mysql -u root -p"${root_pass}" -e "GRANT ALL PRIVILEGES ON \`${dbname}\`.* TO '${dbuser}'@'localhost'; FLUSH PRIVILEGES;"

    DB_NAME="$dbname"
    DB_USER="$dbuser"
    DB_PASS="$dbpass"
}

install_bot() {
    echo -e "\033[32mInstalling Mirza Bot...\033[0m\n"

    if check_marzban_installed; then
        echo -e "\033[33mMarzban detected — using Marzban-compatible install (Apache HTTPS on port 88).\033[0m"
        install_bot_with_marzban
        return
    fi

    read -r -p "Install directory [${MIRZA_BOT_DIR}]: " input_dir
    MIRZA_BOT_DIR="${input_dir:-$MIRZA_BOT_DIR}"
    local BOT_DIR="$MIRZA_BOT_DIR"

    run_step "Installing system packages" install_system_packages
    run_step "Preparing MySQL root credentials" ensure_mysql_root_credentials
    local root_pass
    root_pass="$(get_mysql_root_password)"

    if [[ -d "$BOT_DIR" && -f "$BOT_DIR/config.php" ]]; then
        prompt_yes_no "Existing installation found. Remove and reinstall?" || die "Aborted."
        stop_polling_supervisor
        rm -rf "$BOT_DIR"
    fi

    run_step "Deploying bot files" deploy_bot_files "$BOT_DIR"

    prompt_domain
    ask_telegram_update_mode
    ask_optional_telegram_proxy
    prompt_bot_credentials
    run_step "Creating database" create_app_database "$root_pass"
    run_step "Writing config.php" write_mirza_config "$BOT_DIR/config.php" \
        "$YOUR_BOT_TOKEN" "$YOUR_CHAT_ID" "$YOUR_BOTNAME" \
        "localhost" "$DB_NAME" "$DB_USER" "$DB_PASS" \
        "$DOMAIN_NAME" "$TELEGRAM_POLLING_MODE"

    set_bot_permissions "$BOT_DIR"
    configure_apache_vhost "$DOMAIN_NAME" "$BOT_DIR" 443
    run_table_setup "$DOMAIN_NAME"

    if [[ "$TELEGRAM_POLLING_MODE" == "true" ]]; then
        remove_telegram_webhook "$YOUR_BOT_TOKEN"
        setup_polling_supervisor "$BOT_DIR"
    else
        stop_polling_supervisor
        setup_telegram_webhook "$YOUR_BOT_TOKEN" "$DOMAIN_NAME"
    fi

    send_install_notification "$YOUR_BOT_TOKEN" "$YOUR_CHAT_ID" "$DOMAIN_NAME" "$TELEGRAM_MODE_LABEL"
    link_installer_command

    echo ""
    echo -e "\033[32m✅ Installation complete!\033[0m"
    echo -e "  Bot URL:    $(https_base_url "$DOMAIN_NAME")"
    echo -e "  Panel:      $(https_base_url "$DOMAIN_NAME")/panel/"
    echo -e "  Mode:       ${TELEGRAM_MODE_LABEL}"
    echo -e "  Path:       ${BOT_DIR}"
    echo -e "  Database:   ${DB_NAME} / ${DB_USER}"
}

install_bot_with_marzban() {
    prompt_yes_no "Install alongside Marzban? This uses Apache on port 88 for HTTPS." || exit 0

    local BOT_DIR="$MIRZA_BOT_DIR"
    local MARZ_SSL_PORT=88
    local DOMAIN_WITH_PORT

    if ss -tuln | grep -q ':88 '; then
        die "Port 88 is already in use (required for Apache HTTPS with Marzban)."
    fi

    run_step "Installing packages" install_system_packages
    ufw allow 88/tcp 2>/dev/null || true

    if [[ -d "$BOT_DIR" && -f "$BOT_DIR/config.php" ]]; then
        prompt_yes_no "Existing installation found. Remove and reinstall?" || exit 0
        stop_polling_supervisor
        rm -rf "$BOT_DIR"
    fi

    run_step "Deploying bot files" deploy_bot_files "$BOT_DIR"

    prompt_domain
    DOMAIN_WITH_PORT="${DOMAIN_NAME}:${MARZ_SSL_PORT}"
    ask_telegram_update_mode
    ask_optional_telegram_proxy

    # Marzban MySQL via Docker
    local env_file="/opt/marzban/.env"
    [[ -f "$env_file" ]] || die "Marzban .env not found"
    local mysql_root
    mysql_root=$(grep -E '^MYSQL_ROOT_PASSWORD=' "$env_file" | cut -d '=' -f2- | tr -d '" \n\r')
    [[ -n "$mysql_root" ]] || die "MYSQL_ROOT_PASSWORD missing in Marzban .env"

    local mysql_container
    mysql_container=$(docker ps -q --filter "name=mysql" --no-trunc | head -1)
    [[ -n "$mysql_container" ]] || die "Marzban MySQL container not running"

    local dbuser dbpass
    dbuser=$(openssl rand -base64 8 | tr -dc 'a-zA-Z' | head -c8)
    dbpass=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c12)
    DB_NAME="$DEFAULT_DB_NAME"
    read -r -p "Database user [${dbuser}]: " input_user
    dbuser="${input_user:-$dbuser}"
    read -r -s -p "Database password [auto]: " input_pass
    echo
    dbpass="${input_pass:-$dbpass}"

    docker exec "$mysql_container" bash -c "mysql -u root -p'${mysql_root}' -e \"CREATE DATABASE IF NOT EXISTS ${DB_NAME}; CREATE USER IF NOT EXISTS '${dbuser}'@'%' IDENTIFIED BY '${dbpass}'; GRANT ALL ON ${DB_NAME}.* TO '${dbuser}'@'%'; FLUSH PRIVILEGES;\""

    prompt_bot_credentials

    write_mirza_config "$BOT_DIR/config.php" \
        "$YOUR_BOT_TOKEN" "$YOUR_CHAT_ID" "$YOUR_BOTNAME" \
        "127.0.0.1" "$DB_NAME" "$dbuser" "$dbpass" \
        "$DOMAIN_WITH_PORT" "$TELEGRAM_POLLING_MODE"

    set_bot_permissions "$BOT_DIR"

    # Apache listens on 80 + 88
    cat > /etc/apache2/ports.conf <<EOF
Listen 80
Listen ${MARZ_SSL_PORT}
EOF
    configure_apache_vhost "$DOMAIN_NAME" "$BOT_DIR" "$MARZ_SSL_PORT"
    run_table_setup "$DOMAIN_WITH_PORT"

    if [[ "$TELEGRAM_POLLING_MODE" == "true" ]]; then
        remove_telegram_webhook "$YOUR_BOT_TOKEN"
        setup_polling_supervisor "$BOT_DIR"
    else
        stop_polling_supervisor
        setup_telegram_webhook "$YOUR_BOT_TOKEN" "$DOMAIN_WITH_PORT"
    fi

    send_install_notification "$YOUR_BOT_TOKEN" "$YOUR_CHAT_ID" "$DOMAIN_WITH_PORT" "$TELEGRAM_MODE_LABEL"
    link_installer_command

    echo -e "\033[32m✅ Marzban-compatible install complete (HTTPS port ${MARZ_SSL_PORT}).\033[0m"
}

# ---------------------------------------------------------------------------
# Update / remove
# ---------------------------------------------------------------------------

update_bot() {
    local BOT_DIR CONFIG_PATH TEMP_CONFIG
    BOT_DIR="$(resolve_bot_dir)"
    CONFIG_PATH="${BOT_DIR}/config.php"
    [[ -f "$CONFIG_PATH" ]] || die "Bot not installed at ${BOT_DIR}"

    TEMP_CONFIG="/root/mirza_config_backup.php"
    cp "$CONFIG_PATH" "$TEMP_CONFIG"

    if [[ -f "${SCRIPT_DIR}/index.php" ]] && prompt_yes_no "Update from local source ${SCRIPT_DIR}?" "y"; then
        rsync -a --delete --exclude 'config.php' --exclude 'logs/' --exclude 'storage/cache/' \
            "${SCRIPT_DIR}/" "${BOT_DIR}/"
    else
        local git_url temp
        git_url="$(resolve_git_repo_url "Git repository URL for update")"
        temp="$(clone_mirza_repo "$git_url")"
        rsync -a --delete --exclude 'config.php' --exclude 'logs/' --exclude 'storage/cache/' \
            "${temp}/" "${BOT_DIR}/"
        rm -rf "$temp"
    fi

    mv "$TEMP_CONFIG" "$CONFIG_PATH"
    set_bot_permissions "$BOT_DIR"
    cp -f "${BOT_DIR}/install.sh" /root/install.sh 2>/dev/null || cp -f "$SCRIPT_DIR/install.sh" /root/install.sh
    chmod +x /root/install.sh

    local domain
    domain=$(get_domain_from_config "$CONFIG_PATH")
    run_table_setup "$domain"

    if config_uses_polling "$CONFIG_PATH"; then
        remove_telegram_webhook "$(grep '^\$APIKEY' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")"
        setup_polling_supervisor "$BOT_DIR"
    else
        stop_polling_supervisor
        setup_telegram_webhook "$(grep '^\$APIKEY' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")" "$domain"
    fi

    systemctl reload apache2
    echo -e "\033[32m✅ Update complete.\033[0m"
}

remove_bot() {
    local BOT_DIR
    BOT_DIR="$(resolve_bot_dir)"
    [[ -d "$BOT_DIR" ]] || die "Nothing to remove."

    prompt_yes_no "Remove Mirza Bot from ${BOT_DIR}? (does not remove MySQL/Apache)" || exit 0

    stop_polling_supervisor
    rm -rf "$BOT_DIR"
    a2dissite "${MIRZA_APACHE_SITE}.conf" 2>/dev/null || true
    a2dissite "${MIRZA_APACHE_SITE}-ssl.conf" 2>/dev/null || true
    systemctl reload apache2 2>/dev/null || true
    echo -e "\033[32m✅ Bot files removed.\033[0m"
}

# ---------------------------------------------------------------------------
# Database tools
# ---------------------------------------------------------------------------

extract_db_credentials() {
    local CONFIG_PATH
    CONFIG_PATH="$(get_config_path)"
    [[ -f "$CONFIG_PATH" ]] || die "config.php not found"
    DB_USER=$(grep '^\$usernamedb' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
    DB_PASS=$(grep '^\$passworddb' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
    DB_NAME=$(grep '^\$dbname' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
    DB_HOST=$(grep '^\$dbhost' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
    TELEGRAM_TOKEN=$(grep '^\$APIKEY' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
    TELEGRAM_CHAT_ID=$(grep '^\$adminnumber' "$CONFIG_PATH" | sed -E "s/.*'([^']+)'.*/\1/")
}

export_database() {
    extract_db_credentials
    check_marzban_installed && die "Use docker mysqldump for Marzban MySQL."
    local backup="/root/${DB_NAME}_backup.sql"
    mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$backup"
    echo -e "\033[32mBackup: ${backup}\033[0m"
}

import_database() {
    extract_db_credentials
    check_marzban_installed && die "Use docker mysql import for Marzban MySQL."
    local backup
    read -r -p "Backup file [/root/${DB_NAME}_backup.sql]: " backup
    backup="${backup:-/root/${DB_NAME}_backup.sql}"
    [[ -f "$backup" ]] || die "File not found: ${backup}"
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$backup"
    echo -e "\033[32m✅ Database imported.\033[0m"
}

auto_backup() {
  extract_db_credentials
    local cron_line="0 3 * * * mysqldump -h ${DB_HOST} -u ${DB_USER} -p'${DB_PASS}' ${DB_NAME} > /root/${DB_NAME}_backup_\$(date +\\%F).sql"
    (crontab -l 2>/dev/null | grep -v "${DB_NAME}_backup"; echo "$cron_line") | crontab -
    echo -e "\033[32mDaily backup cron added (03:00).\033[0m"
}

renew_ssl() {
    systemctl stop apache2
    certbot renew
    systemctl start apache2
    echo -e "\033[32m✅ SSL renewal attempted.\033[0m"
}

change_domain() {
    local new_domain config bot_token ssl_port base_domain bot_dir
    config="$(get_config_path)"
    bot_dir="$(resolve_bot_dir)"
    [[ -f "$config" ]] || die "config.php not found"

    while [[ ! "${new_domain:-}" =~ ^[a-zA-Z0-9.-]+(:[0-9]+)?$ ]]; do
        read -r -p "New domain (use host:88 with Marzban): " new_domain
    done

    cp "$config" "${config}.$(date +%s).bak"
    sed -i "s/^\$domainhosts = .*/\$domainhosts = '${new_domain}';/" "$config"

    bot_token=$(grep '^\$APIKEY' "$config" | sed -E "s/.*'([^']+)'.*/\1/")
    if [[ "$new_domain" == *:* ]]; then
        base_domain="${new_domain%%:*}"
        ssl_port="${new_domain##*:}"
    else
        base_domain="$new_domain"
        ssl_port="443"
    fi

    configure_apache_vhost "$base_domain" "$bot_dir" "$ssl_port"

    if config_uses_polling "$config"; then
        remove_telegram_webhook "$bot_token"
        setup_polling_supervisor "$bot_dir"
    else
        setup_telegram_webhook "$bot_token" "$new_domain"
    fi

    echo -e "\033[32m✅ Domain updated to ${new_domain}\033[0m"
}

switch_telegram_mode() {
    local config bot_dir bot_token domain
    config="$(get_config_path)"
    bot_dir="$(resolve_bot_dir)"
    [[ -f "$config" ]] || die "Bot not installed"

    bot_token=$(grep '^\$APIKEY' "$config" | sed -E "s/.*'([^']+)'.*/\1/")
    domain=$(get_domain_from_config "$config")

    if config_uses_polling "$config"; then
        echo -e "Current: \033[33mpolling\033[0m → switching to \033[32mwebhook\033[0m"
        sed -i 's/^\$telegram_polling_mode = .*/\$telegram_polling_mode = false;/' "$config"
        stop_polling_supervisor
        setup_telegram_webhook "$bot_token" "$domain"
    else
        echo -e "Current: \033[33mwebhook\033[0m → switching to \033[32mpolling\033[0m"
        sed -i 's/^\$telegram_polling_mode = .*/\$telegram_polling_mode = true;/' "$config"
        remove_telegram_webhook "$bot_token"
        setup_polling_supervisor "$bot_dir"
    fi
    echo -e "\033[32m✅ Mode switched.\033[0m"
}

# ---------------------------------------------------------------------------
# CLI entry
# ---------------------------------------------------------------------------

process_arguments() {
    case "${1:-}" in
        -v*)
            install_bot ;;
        -beta|--beta)
            install_bot ;;
        -update)
            update_bot ;;
        "")
            show_menu ;;
        *)
            show_menu ;;
    esac
}

process_arguments "$@"
