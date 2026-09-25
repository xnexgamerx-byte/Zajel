#!/bin/bash
# Railway: خدمةٌ واحدة — Caddy على $PORT أمام PHP-FPM، والمجدوِل بجانبهما؛
# وRailway يتولّى HTTPS. خدمةٌ واحدة لا ثلاث: إعداداتٌ واحدة، وبناءٌ واحد.
set -euo pipefail

php-fpm8.5 --nodaemonize &
fpm=$!
caddy run --config /etc/caddy/Caddyfile --adapter caddyfile &
web=$!
runuser -u www-data -- php artisan schedule:work &
scheduler=$!

trap 'kill -TERM "$fpm" "$web" "$scheduler" 2>/dev/null; wait' TERM INT

# أيّها سقط أُسقطت الحاوية كلّها فيعيدها Railway، بدل ويبٍ بلا PHP أو
# نظامٍ بلا مهامّه الليلية لا يلاحظه أحد
wait -n "$fpm" "$web" "$scheduler"
exit 1
