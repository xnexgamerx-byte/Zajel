#!/usr/bin/env bash
#
# أيكفي هذا الخادم النظامَ؟ يقرأ ولا يغيّر شيئاً: لا يوقف حاوية ولا يكتب ملفاً.
# من مجلّد deploy على الخادم:
#
#   sudo ./check-server.sh
#
# يطبع الموارد (معالج، ذاكرة، swap، قرص)، وما يستهلكه النظام الآن، وحجم القاعدة وسرعة نموّها،
# وحكماً في آخره: ✓ يكفي، أو ⚠ يكفي بحذر (وسببه وما يُفعل)، أو ✗ لا يكفي.
# ويعمل بلا Docker أو بلا نظامٍ مثبّت: يفحص ما يقدر عليه ويقول ما فاته.
#
# الحدّ المعتمد: ما في deploy/README.md — معالجان و٤ غيغابايت ذاكرة و٤٠ غيغابايت قرص «تكفي
# شركةً بآلاف الشحنات يومياً». ودونها يعمل النظام على حذر، والبناء عند كل تحديث هو أثقل لحظة
# (npm وvite وcomposer على الخادم نفسه) وأول ما يفشل في ذاكرةٍ صغيرة.

set -uo pipefail
cd "$(dirname "$0")"

bad=()
warn=()
note=()

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }
num() { [[ "${1:-}" =~ ^[0-9]+$ ]] && echo "$1" || echo 0; }
envget() { [ -f .env ] && grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- || true; }

# ── ١. الموارد ──
say "الموارد"

cores=$(num "$(nproc 2> /dev/null)")
mem_mb=$(num "$(awk '/MemTotal/ {print int($2 / 1024)}' /proc/meminfo 2> /dev/null)")
avail_mb=$(num "$(awk '/MemAvailable/ {print int($2 / 1024)}' /proc/meminfo 2> /dev/null)")
swap_mb=$(num "$(awk '/SwapTotal/ {print int($2 / 1024)}' /proc/meminfo 2> /dev/null)")
load15=$(awk '{print $3}' /proc/loadavg 2> /dev/null || echo 0)

# القرص الذي تُبنى عليه الصور وتُحفظ القاعدة: جذر Docker إن وُجد، وإلا الجذر
root_dir=/
if command -v docker > /dev/null 2>&1; then
    d=$(docker info -f '{{.DockerRootDir}}' 2> /dev/null || true)
    [ -n "$d" ] && [ -d "$d" ] && root_dir=$d
fi
read -r disk_total_mb disk_free_mb < <(df -Pm "$root_dir" 2> /dev/null | awk 'NR==2 {print $2, $4}')
disk_total_mb=$(num "${disk_total_mb:-}")
disk_free_mb=$(num "${disk_free_mb:-}")

printf '  معالجات:   %s\n' "$cores"
printf '  الذاكرة:   %s ميغابايت (المتاح الآن %s)\n' "$mem_mb" "$avail_mb"
printf '  swap:      %s ميغابايت\n' "$swap_mb"
printf '  القرص:     %s ميغابايت، الحرّ %s (%s)\n' "$disk_total_mb" "$disk_free_mb" "$root_dir"
printf '  حِمل 15 د: %s\n' "$load15"

if (( mem_mb == 0 )); then
    note+=("لم أستطع قراءة الذاكرة: شغّله على الخادم نفسه لا على جهازك.")
elif (( mem_mb < 1500 )); then
    bad+=("الذاكرة ${mem_mb}MB دون ١٫٥ غيغابايت: MySQL وPHP وCaddy وحدها تقارب ذلك، وبناء التحديث (npm وvite) يتجاوزه — يفشل غالباً بـ«Killed».")
elif (( mem_mb < 3000 )); then
    warn+=("الذاكرة ${mem_mb}MB دون ٣ غيغابايت: يعمل لحجمٍ صغير، لكنّ المرجع ٤ غيغابايت (عمّال PHP قد يبلغون نحو ٦٠٠MB في الذروة، وMySQL يحجز ما في DB_BUFFER_POOL، والبناء فوقهما).")
fi

if (( mem_mb > 0 && mem_mb < 3500 && swap_mb < 1024 )); then
    warn+=("لا swap كافٍ (${swap_mb}MB) على ذاكرةٍ ${mem_mb}MB: التحديث (البناء) يُقتل عند أول ذروة. أضف ٢ غيغابايت (الأمر أدناه).")
fi

if (( disk_free_mb > 0 )); then
    if (( disk_free_mb < 6000 )); then
        bad+=("القرص الحرّ ${disk_free_mb}MB دون ٦ غيغابايت: كل بناءٍ يترك صوراً قديمة (٢ غيغابايت أو أكثر)، والنسخ الاحتياطية تتراكم.")
    elif (( disk_free_mb < 12000 )); then
        warn+=("القرص الحرّ ${disk_free_mb}MB: يكفي اليوم، لكنّ البناء والنسخ الاحتياطية تأكله؛ راقبه كل شهر.")
    fi
fi

(( cores == 1 )) && warn+=("معالجٌ واحد: يعمل، لكنّ البناء عند التحديث بطيء (دقائق) ويُبطئ الموقع أثناءه.")

if awk -v l="$load15" -v c="$cores" 'BEGIN { exit !(c > 0 && l > c) }'; then
    warn+=("الحِمل في آخر ١٥ دقيقة ($load15) فوق عدد المعالجات ($cores): الخادم مشغولٌ دائماً الآن.")
fi

if (( mem_mb > 0 && avail_mb > 0 && avail_mb < 300 )); then
    warn+=("المتاح الآن ${avail_mb}MB فقط: الخادم على حافّة الذاكرة.")
fi

# قتل المنظومة لعمليةٍ لنفاد الذاكرة: أصدق دليلٍ على ضيق الذاكرة
oom=$(num "$( (dmesg 2> /dev/null || true) | grep -ci -E 'out of memory|oom-kill' || true)")
if (( oom > 0 )); then
    bad+=("سجلّ النواة فيه ${oom} حدثُ «نفاد ذاكرة» قُتلت فيه عمليات: الذاكرة لا تكفي فعلاً (dmesg | grep -i oom).")
fi

# ── ٢. النظام نفسه ──
say "النظام"

users=0 total=0 m30=0 age=0 dbmb=0

if ! command -v docker > /dev/null 2>&1; then
    note+=("Docker غير مثبّت هنا: لا فحص للنظام نفسه.")
elif [ ! -f .env ]; then
    note+=("لا .env في هذا المجلّد: النظام غير مثبّت هنا (setup.sh).")
elif [ -z "$(docker compose ps -q 2> /dev/null)" ]; then
    warn+=("النظام مثبّتٌ ولا حاويات تعمل الآن (docker compose ps).")
else
    printf '  ذاكرة الحاويات الآن:\n'
    docker stats --no-stream --format '    {{.Name}}: {{.MemUsage}}' 2> /dev/null

    pool=$(envget DB_BUFFER_POOL)
    printf '  ذاكرة قاعدة البيانات المحجوزة: %s\n' "${pool:-؟}"

    stats=$(docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N zajel' 2> /dev/null << 'SQL'
SELECT
  (SELECT COUNT(*) FROM shipments),
  (SELECT COUNT(*) FROM shipments WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY),
  (SELECT IFNULL(DATEDIFF(UTC_TIMESTAMP(), MIN(created_at)), 0) FROM shipments),
  (SELECT IFNULL(ROUND(SUM(data_length + index_length) / 1048576), 0) FROM information_schema.tables WHERE table_schema = 'zajel'),
  (SELECT COUNT(*) FROM users WHERE is_active = 1);
SQL
    )

    if [ -n "$stats" ]; then
        read -r total m30 age dbmb users <<< "$stats"
        total=$(num "$total") m30=$(num "$m30") age=$(num "$age") dbmb=$(num "$dbmb") users=$(num "$users")

        per_day=$(( m30 / 30 ))
        age_days=$(( age < 30 ? 30 : age ))
        grow_mb=$(( dbmb * 30 / age_days ))

        printf '  المستخدمون الفعّالون:   %s\n' "$users"
        printf '  الشحنات كلّها:          %s\n' "$total"
        printf '  آخر ٣٠ يوماً:           %s (نحو %s في اليوم)\n' "$m30" "$per_day"
        printf '  حجم القاعدة:            %s ميغابايت\n' "$dbmb"
        printf '  نموّها التقديري:        نحو %s ميغابايت في الشهر → بعد سنة نحو %s\n' "$grow_mb" "$(( dbmb + grow_mb * 12 ))"

        # ما تضيفه النسخ الاحتياطية: كل ليلة قاعدةٌ مضغوطة، لأيامٍ تُحفظ
        keep=$(num "$(envget BACKUP_KEEP_DAYS)"); (( keep == 0 )) && keep=14
        bdir=$(envget BACKUP_DIR); bdir=${bdir:-/var/backups/zajel}
        if [ -d "$bdir" ]; then
            bmb=$(num "$(du -sm "$bdir" 2> /dev/null | cut -f1)")
            printf '  النسخ الاحتياطية هنا:   %s ميغابايت في %s (تُحفظ %s يوماً)\n' "$bmb" "$bdir" "$keep"
            (( disk_free_mb > 0 && bmb * 2 > disk_free_mb )) \
                && warn+=("النسخ الاحتياطية ${bmb}MB والحرّ ${disk_free_mb}MB: تنمو فوق ما يتّسع. انقلها خارج الخادم (offsite-backup.sh) وقلّل BACKUP_KEEP_DAYS.")
        fi

        # بعد سنة: أيبقى في القرص متّسع؟
        year_mb=$(( grow_mb * 12 ))
        (( disk_free_mb > 0 && year_mb * 3 > disk_free_mb )) \
            && warn+=("نموّ القاعدة التقديري ($year_mb MB في سنة) مع نسخها يقترب من الحرّ ($disk_free_mb MB).")
    else
        note+=("لم أستطع قراءة القاعدة (الحاوية db أو كلمة السرّ).")
    fi

    printf '  مساحة Docker:\n'
    docker system df 2> /dev/null | sed 's/^/    /'
fi

# ── ٣. الحكم ──
say "الحكم"

if (( ${#bad[@]} )); then
    for m in "${bad[@]}"; do printf '  \033[31m✗\033[0m %s\n' "$m"; done
fi
if (( ${#warn[@]} )); then
    for m in "${warn[@]}"; do printf '  \033[33m⚠\033[0m %s\n' "$m"; done
fi
if (( ${#note[@]} )); then
    for m in "${note[@]}"; do printf '  · %s\n' "$m"; done
fi

if (( ${#bad[@]} )); then
    verdict="✗ لا يكفي"
elif (( ${#warn[@]} )); then
    verdict="⚠ يكفي بحذر"
else
    verdict="✓ يكفي"
fi
printf '\n  \033[1m%s\033[0m\n' "$verdict"

if (( mem_mb > 0 && mem_mb < 3500 && swap_mb < 1024 )); then
    cat << 'EOF'

  لإضافة ٢ غيغابايت swap (يُنفَّذ مرّةً واحدة، آمن، ويبقى بعد إعادة التشغيل):

    sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile \
      && sudo mkswap /swapfile && sudo swapon /swapfile \
      && echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
EOF
fi

(( ${#bad[@]} )) && exit 2
exit 0
