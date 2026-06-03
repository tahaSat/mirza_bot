#!/usr/bin/env bash
# Sync mirzabot to server. Skips only root config.php (server keeps its own DB/bot settings).
#
# Usage:
#   export DEPLOY_HOST=user@your-server-ip
#   ./deploy-to-server.sh
#
# Dry run:
#   DEPLOY_HOST=user@host ./deploy-to-server.sh --dry-run

set -euo pipefail

REMOTE_PATH="${DEPLOY_PATH:-/var/www/mirza_pro}"
LOCAL_PATH="$(cd "$(dirname "$0")" && pwd)"

if [[ -z "${DEPLOY_HOST:-}" ]]; then
  echo "Set DEPLOY_HOST, e.g.: export DEPLOY_HOST=root@your-server"
  exit 1
fi

DRY=()
[[ "${1:-}" == "--dry-run" ]] && DRY=(--dry-run -n)

echo "Local:  $LOCAL_PATH/"
echo "Remote: $DEPLOY_HOST:$REMOTE_PATH/"
echo "Excluded: /config.php only"
echo ""

rsync -avz --delete "${DRY[@]}" \
  --exclude '/config.php' \
  "$LOCAL_PATH/" "$DEPLOY_HOST:$REMOTE_PATH/"

echo ""
if [[ "${#DRY[@]}" -gt 0 ]]; then
  echo "Dry run finished. Run without --dry-run to apply."
else
  echo "Done. On server:"
  echo "  chown -R www-data:www-data $REMOTE_PATH"
  echo "  systemctl reload apache2"
fi
