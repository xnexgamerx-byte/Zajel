#!/usr/bin/env bash
#
# تحديث النظام لآخر نسخة: نسخةٌ احتياطية أولاً، ثم الشيفرة، ثم الصور.
# الترحيلات تجري وحدها حين تبدأ حاوية التطبيق.
#
#   sudo ./update.sh

set -euo pipefail
cd "$(dirname "$0")"

./backup.sh
git -C .. pull --ff-only
docker compose up -d --build --wait
docker image prune -f > /dev/null
docker compose ps
