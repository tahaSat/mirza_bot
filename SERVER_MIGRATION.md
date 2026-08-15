# Full migration: old server → new server

Move the live bot (same domain, same token, same folder name) from:

| | IP |
| --- | --- |
| Old (keep as rollback) | `168.222.43.253` |
| New | `176.65.151.209` |

Plan: freeze the old bot with **development mode**, run `install.sh` on the new server, import the old database and runtime files (no logs), then unfreeze.

Do not delete anything on `168.222.43.253`. If the new box fails, point DNS back and set `$development_mode = false` on the old `config.php`.

---

## Development mode

Flag in `config.php` (not in the database):

```php
$development_mode = true;   // freeze
$development_mode = false;  // normal
```

When it is `true`:

- Any Telegram message or button shows:  
  `ربات در حالت توسعه و بروز رسانی میباشد. لطفا پس از مدتی مجددا تلاش نمایید.`
- Inline button clicks get that text as an alert; no purchase / panel / user write runs
- `cronbot/*` exits immediately (no disable/expire/gift/backup)
- Web panel, Mini App API, payment callbacks, and `sub/` return HTTP 503
- Agent sell-bots (`vpnbot/…`) show the same Telegram message
- `table.php` and `polling.php` still run (install / schema / poller)

Toggle only by editing `config.php` over SSH. The admin bot is frozen too on purpose.

This code must exist on the **old** server **before** you turn the flag on. Upload at least:

- `development_mode.php` (new file)
- `function.php`
- `vpnbot/Default/index.php`
- `vpnbot/update/index.php`
- `install.sh`

---

## 0. Variables

SSH to both servers and paste:

```bash
OLD_HOST=root@168.222.43.253
NEW_HOST=root@176.65.151.209
CERTBOT_EMAIL=you@example.com    # Let's Encrypt notices
```

Domain, bot token, folder, and DB name are copied from the old `config.php`. Do not type a new domain or a test bot token.

---

## Phase 1 — Inventory the old server

```bash
ssh $OLD_HOST
```

```bash
# find the app
ls -d /var/www/mirza_bot /var/www/mirza_pro /var/www/html/mirzabotconfig 2>/dev/null
BOT_DIR=/var/www/mirza_bot          # <-- set to the path that has config.php
echo "$BOT_DIR"

grep -E '^\$dbhost|^\$dbname|^\$usernamedb|^\$domainhosts|^\$usernamebot|^\$telegram_polling_mode|^\$telegram_proxy|^\$adminnumber' "$BOT_DIR/config.php"

php -v
apache2ctl -S
crontab -l
crontab -u www-data -l 2>/dev/null || true
ls -1 "$BOT_DIR/vpnbot" | grep -vE '^(Default|update|index.php)$' || true
```

Write down:

- `BOT_DIR` (use the **same path** on the new server)
- `$domainhosts` (same hostname; no `https://`)
- `$usernamebot`, `$adminnumber`
- `$telegram_polling_mode` (this guide assumes `false` / webhook)
- `$telegram_proxy` if set

Do not paste `$APIKEY` or DB passwords into chat.

If `$domainhosts` contains `:88`, Marzban is on the same machine — Apache TLS is on port 88. See the note at the end.

---

## Phase 2 — Put development mode on the old server and freeze

From your laptop (this repo), copy the new freeze files. Keep the old `config.php`.

```bash
OLD_HOST=root@168.222.43.253
BOT_DIR=/var/www/mirza_bot          # same path as Phase 1

rsync -avz \
  development_mode.php \
  function.php \
  install.sh \
  vpnbot/Default/index.php \
  vpnbot/update/index.php \
  $OLD_HOST:$BOT_DIR/
```

If `vpnbot/Default/index.php` on the server is a generated agent copy, the freeze still applies through `vpnbot/update/index.php` only after those instance folders are rebuilt. Instance folders under `vpnbot/{id}{username}/` are copies of `Default/`. After this rsync, copy the halt into live agent bots:

```bash
ssh $OLD_HOST
BOT_DIR=/var/www/mirza_bot

python3 - <<'PY'
from pathlib import Path
root = Path("/var/www/mirza_bot/vpnbot")  # <-- BOT_DIR/vpnbot
needle = """if (!checktelegramip())
    die("Unauthorized access");
"""
insert = """if (!checktelegramip())
    die("Unauthorized access");
if (function_exists('mirza_halt_if_development_mode')) {
    mirza_halt_if_development_mode();
}
"""
for path in root.glob("*/index.php"):
    if path.parent.name in ("Default", "update"):
        continue
    text = path.read_text(encoding="utf-8")
    if "mirza_halt_if_development_mode" in text:
        print("ok", path)
        continue
    if needle not in text:
        print("SKIP", path)
        continue
    path.write_text(text.replace(needle, insert, 1), encoding="utf-8")
    print("patched", path)
PY

chown www-data:www-data "$BOT_DIR/development_mode.php" "$BOT_DIR/function.php"
```

Turn freeze **on**:

```bash
BOT_DIR=/var/www/mirza_bot
grep -q '^\$development_mode' "$BOT_DIR/config.php" \
  && sed -i 's/^\$development_mode = .*/\$development_mode = true;/' "$BOT_DIR/config.php" \
  || sed -i '/^\$telegram_polling_mode/a $development_mode = true;' "$BOT_DIR/config.php"

grep '^\$development_mode' "$BOT_DIR/config.php"
```

Send any message or tap any button on the **production** bot. You must see the Persian maintenance text. Cron URLs should return `development_mode`:

```bash
DOMAIN=$(grep '^\$domainhosts' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
curl -sS "https://${DOMAIN}/cronbot/statusday.php"; echo
```

Stop old crons so they cannot hit the new IP after DNS moves:

```bash
crontab -l > /root/crontab.root.bak 2>/dev/null || true
crontab -u www-data -l > /root/crontab.www-data.bak 2>/dev/null || true
crontab -r 2>/dev/null || true
crontab -u www-data -r 2>/dev/null || true
```

The old database and files stay on this server. Leave MySQL and Apache running.

---

## Phase 3 — Backup old server (no logs)

Still on the old server:

```bash
BOT_DIR=/var/www/mirza_bot
mkdir -p /root/mirza-migrate

DB_NAME=$(grep '^\$dbname' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
DB_USER=$(grep '^\$usernamedb' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
DB_PASS=$(grep '^\$passworddb' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
DB_HOST=$(grep '^\$dbhost' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")

mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction --routines --triggers --no-tablespaces \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip > /root/mirza-migrate/${DB_NAME}.sql.gz

tar -C "$(dirname "$BOT_DIR")" -czf /root/mirza-migrate/bot-files.tgz \
  --exclude='logs' \
  --exclude='logs/*' \
  --exclude='cronbot/error_log' \
  --exclude='cronbot/log.txt' \
  "$(basename "$BOT_DIR")"

cp "$BOT_DIR/config.php" /root/mirza-migrate/config.php.bak
ls -lh /root/mirza-migrate/
```

Copy to the new server (from your laptop):

```bash
OLD_HOST=root@168.222.43.253
NEW_HOST=root@176.65.151.209

ssh $NEW_HOST 'mkdir -p /root/mirza-migrate'
scp $OLD_HOST:/root/mirza-migrate/bot-files.tgz \
    $OLD_HOST:/root/mirza-migrate/*.sql.gz \
    $OLD_HOST:/root/mirza-migrate/config.php.bak \
    $NEW_HOST:/root/mirza-migrate/
```

---

## Phase 4 — Point DNS at the new IP

`$domainhosts` must resolve to `176.65.151.209` **before** `install.sh` (Certbot and webhook both need it).

Lower TTL if you can, then set the A record to `176.65.151.209`.

```bash
# on your laptop
DOMAIN=your.domain.com    # from old $domainhosts, without :port
dig +short "$DOMAIN"
# must become 176.65.151.209
```

Until this is true, do not run `install.sh`. Users still see the freeze message on the old server while DNS has not flipped.

---

## Phase 5 — `install.sh` on the new server

```bash
ssh $NEW_HOST
```

Use the **same** `BOT_DIR`, domain, bot token, admin id, and bot username as the old `config.php.bak`.

```bash
# Ubuntu 22/24, PHP 8.1+
export CERTBOT_EMAIL=you@example.com
# optional: same path as old
export MIRZA_BOT_DIR=/var/www/mirza_bot

# if this laptop repo is already on the new box:
#   bash /path/to/install.sh
# otherwise fetch installer from the repo you actually use, then:
bash install.sh
```

In the menu choose **1) Install Mirza Bot**.

When prompted:

- Install directory = same as old `BOT_DIR`
- Domain = old `$domainhosts` (hostname only, or `host:88` with Marzban)
- Mode = webhook (unless old was polling)
- Proxy = copy old `$telegram_proxy` if the old server needed it
- Bot token / admin id / username = **production values from old config**

This installs Apache, PHP, MySQL, Certbot, empty DB, vhost, TLS, and sets webhook to `https://DOMAIN/index.php`.

**Immediately freeze the new bot** so nobody buys against the empty database:

```bash
BOT_DIR=/var/www/mirza_bot
grep -q '^\$development_mode' "$BOT_DIR/config.php" \
  && sed -i 's/^\$development_mode = .*/\$development_mode = true;/' "$BOT_DIR/config.php" \
  || echo '$development_mode = true;' >> "$BOT_DIR/config.php"

# if install.sh cloned code without development_mode.php, unpack it from the old tarball:
tar -tzf /root/mirza-migrate/bot-files.tgz | grep development_mode.php && \
  tar -C /var/www -xzf /root/mirza-migrate/bot-files.tgz --wildcards '*/development_mode.php' '*/function.php' || true

grep '^\$development_mode' "$BOT_DIR/config.php"
```

Tap a button on the production bot. You should still see the maintenance message (now served from `176.65.151.209`).

---

## Phase 6 — Import old database and overlay files

On the **new** server. Keep the **new** `config.php` DB user/password that `install.sh` created.

```bash
BOT_DIR=/var/www/mirza_bot

NEW_DB_NAME=$(grep '^\$dbname' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
NEW_DB_USER=$(grep '^\$usernamedb' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
NEW_DB_PASS=$(grep '^\$passworddb' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
NEW_DB_HOST=$(grep '^\$dbhost' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")

# wipe the empty schema install.sh created, then load production data
gunzip -c /root/mirza-migrate/*.sql.gz | mysql -h "$NEW_DB_HOST" -u "$NEW_DB_USER" -p"$NEW_DB_PASS" "$NEW_DB_NAME"
mysql -h "$NEW_DB_HOST" -u "$NEW_DB_USER" -p"$NEW_DB_PASS" -e "SHOW TABLES;" "$NEW_DB_NAME" | head
```

Overlay runtime files from the old tarball **without** replacing the new `config.php` and **without** logs:

```bash
cd /tmp
rm -rf mirza-old && mkdir mirza-old
tar -xzf /root/mirza-migrate/bot-files.tgz -C /tmp/mirza-old
OLD_TREE=$(find /tmp/mirza-old -maxdepth 1 -mindepth 1 -type d | head -1)

rsync -a \
  --exclude 'config.php' \
  --exclude 'logs/' \
  --exclude 'cronbot/error_log' \
  --exclude 'cronbot/log.txt' \
  "$OLD_TREE"/ "$BOT_DIR"/
```

That restores `vpnbot/{id}{username}/`, `api/hash.txt`, `storage/`, cron queue files, and the rest of the tree.

Merge proxy settings from the old config if the new one is missing them:

```bash
# compare by eye
grep -E 'telegram_proxy|telegram_proxies|telegram_polling' \
  /root/mirza-migrate/config.php.bak "$BOT_DIR/config.php"
```

Copy any `$telegram_proxy` / `$telegram_proxies` lines from the bak file into the live `config.php`. Leave the **new** `$dbhost` `$dbname` `$usernamedb` `$passworddb`. Keep `$APIKEY` `$domainhosts` `$usernamebot` `$adminnumber` the same as production. Keep `$development_mode = true` until Phase 7.

```bash
mkdir -p "$BOT_DIR/logs" "$BOT_DIR/storage/cache" "$BOT_DIR/storage/logs" "$BOT_DIR/storage/sessions/panel"
chown -R www-data:www-data "$BOT_DIR"
chmod -R 755 "$BOT_DIR"
chmod -R 775 "$BOT_DIR/logs" "$BOT_DIR/storage" "$BOT_DIR/cronbot"
chown -R root:root "$BOT_DIR/.git" 2>/dev/null || true
```

Schema sync (safe on an imported DB). `table.php` is allowed in development mode:

```bash
DOMAIN=$(grep '^\$domainhosts' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
curl -fsS "https://${DOMAIN}/table.php" && echo OK
```

Re-point agent sell-bot webhooks to this same domain (no-op if the hostname did not change, still recommended):

```bash
php -r '
require "/var/www/mirza_bot/config.php";
$q = $pdo->query("SELECT id_user, username, bot_token FROM botsaz");
while ($bot = $q->fetch(PDO::FETCH_ASSOC)) {
    $url = "https://{$domainhosts}/vpnbot/{$bot["id_user"]}{$bot["username"]}/index.php";
    $ch = curl_init("https://api.telegram.org/bot{$bot["bot_token"]}/setWebhook");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>["url"=>$url]]);
    echo $bot["username"], " ", curl_exec($ch), PHP_EOL;
    curl_close($ch);
}
'
```

Confirm webhook:

```bash
php -r '
require "/var/www/mirza_bot/config.php";
$ch = curl_init("https://api.telegram.org/bot{$APIKEY}/getWebhookInfo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
echo curl_exec($ch), PHP_EOL;
'
```

`url` must be `https://YOUR_DOMAIN/index.php` and `last_error_message` empty.

---

## Phase 7 — Unfreeze and enable crons

On the **new** server:

```bash
BOT_DIR=/var/www/mirza_bot
sed -i 's/^\$development_mode = .*/\$development_mode = false;/' "$BOT_DIR/config.php"
grep '^\$development_mode' "$BOT_DIR/config.php"
```

Send `/start` to the production bot. It must work normally.

Open the admin panel in the bot once (that registers crons), or install them:

```bash
cat >/tmp/www-cron <<EOF
45 23 * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.statusday.lock /usr/bin/php statusday.php
*/1 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.croncard.lock /usr/bin/php croncard.php
*/5 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.NoticationsService.lock /usr/bin/php NoticationsService.php
0 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.payment_expire.lock /usr/bin/php payment_expire.php
*/1 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.sendmessage.lock /usr/bin/php sendmessage.php
*/5 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.activeconfig.lock /usr/bin/php activeconfig.php
*/5 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.disableconfig.lock /usr/bin/php disableconfig.php
0 */5 * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.backupbot.lock /usr/bin/php backupbot.php
*/2 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.gift.lock /usr/bin/php gift.php
*/30 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.expireagent.lock /usr/bin/php expireagent.php
*/15 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.on_hold.lock /usr/bin/php on_hold.php
*/2 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.configtest.lock /usr/bin/php configtest.php
*/15 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.uptime_node.lock /usr/bin/php uptime_node.php
*/15 * * * * cd $BOT_DIR/cronbot && /usr/bin/flock -n /tmp/mirza.uptime_panel.lock /usr/bin/php uptime_panel.php
EOF
crontab -u www-data /tmp/www-cron
crontab -u www-data -l
```

If `crontab -u www-data` is denied, put the same lines in `root` crontab.

Quick checks:

- [ ] `/start` on the main bot
- [ ] Button clicks work (not the maintenance alert)
- [ ] `https://DOMAIN/panel/` login
- [ ] Mini App `https://DOMAIN/app/`
- [ ] An old subscription link `https://DOMAIN/sub/{invoice_id}`
- [ ] `php $BOT_DIR/cronbot/statusday.php` runs without a fatal error
- [ ] Old server still has `$development_mode = true` and **no** crontab

Keep `168.222.43.253` on for 24–48 hours. Do not start its crons.

---

## Rollback

1. On the old server set `$development_mode = false`
2. Restore crontab: `crontab /root/crontab.root.bak` and `crontab -u www-data /root/crontab.www-data.bak`
3. Point the domain A record back to `168.222.43.253`
4. Wait for DNS, then:

```bash
# on old server
BOT_DIR=/var/www/mirza_bot
TOKEN=$(grep '^\$APIKEY' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
DOMAIN=$(grep '^\$domainhosts' "$BOT_DIR/config.php" | sed -E "s/.*'([^']+)'.*/\1/")
curl -sS -X POST "https://api.telegram.org/bot${TOKEN}/setWebhook" \
  -d "url=https://${DOMAIN}/index.php" \
  -d 'allowed_updates=["message","callback_query","channel_post","pre_checkout_query","inline_query","chat_member","my_chat_member"]'
```

5. On the new server set `$development_mode = true` and `crontab -u www-data -r` so it cannot fight the old box

If users bought on the new DB after Phase 7, dump the new DB and import it on the old server before rollback, or those rows are lost.

---

## Marzban note (`$domainhosts` like `example.com:88`)

`install.sh` detects `/opt/marzban/docker-compose.yml` and puts Apache HTTPS on port 88. Open `88/tcp`. Dump/import MySQL inside the Marzban Docker MySQL container, not host `mysqldump`. Webhook URL is `https://DOMAIN:88/index.php`.
