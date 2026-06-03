#!/usr/bin/env bash
# Pull code from server into this project. Keeps your local config.php (not overwritten).
#
# Usage:
#   export DEPLOY_HOST=user@your-server-ip
#   ./pull-from-server.sh
#
# Dry run:
#   DEPLOY_HOST=user@host ./pull-from-server.sh --dry-run

set -euo pipefail

REMOTE_PATH="${DEPLOY_PATH:-/var/www/mirza_pro}"
LOCAL_PATH="$(cd "$(dirname "$0")" && pwd)"

if [[ -z "${DEPLOY_HOST:-}" ]]; then
  echo "Set DEPLOY_HOST, e.g.: export DEPLOY_HOST=root@your-server"
  exit 1
fi

DRY=()
[[ "${1:-}" == "--dry-run" ]] && DRY=(--dry-run -n)

echo "Remote: $DEPLOY_HOST:$REMOTE_PATH/"
echo "Local:  $LOCAL_PATH/"
echo "Excluded from pull: /config.php (your local config.php is kept)"
echo ""

rsync -avz "${DRY[@]}" \
  --exclude '/config.php' \
  "$DEPLOY_HOST:$REMOTE_PATH/" "$LOCAL_PATH/"

echo ""
if [[ "${#DRY[@]}" -gt 0 ]]; then
  echo "Dry run finished. Run without --dry-run to apply."
else
  echo "Done. Local config.php was not changed."
fi
