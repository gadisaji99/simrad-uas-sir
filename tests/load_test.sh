#!/bin/sh
# Uji beban sederhana dengan ApacheBench (ab). Tersedia di XAMPP macOS: /Applications/XAMPP/xamppfiles/bin/ab
# Pakai (XAMPP Mac):
#   AB=/Applications/XAMPP/xamppfiles/bin/ab BASE=http://localhost/simrad/public/api.php sh tests/load_test.sh
# Hasil ditambahkan ke docs/hasil_beban.txt. Skrip hanya MEMBACA data (tidak mengubah basis data).
AB="${AB:-ab}"; BASE="${BASE:-http://localhost/simrad/public/api.php}"; N="${N:-300}"
USERNAME="${USERNAME_UJI:-d001}"; PASS="${PASS_UJI:-simrad2026}"
CJ="$(mktemp)"; OUT="$(dirname "$0")/../docs/hasil_beban.txt"; mkdir -p "$(dirname "$OUT")"
curl -s -c "$CJ" -X POST "$BASE?r=login" -d "{\"username\":\"$USERNAME\",\"password\":\"$PASS\"}" >/dev/null
SID="$(awk '$6=="PHPSESSID"{print $7}' "$CJ")"
[ -n "$SID" ] || { echo "Login gagal: PHPSESSID tidak ditemukan. Periksa BASE dan kata sandi."; exit 1; }
{
echo "=== Uji beban SIMRAD: $(date '+%Y-%m-%d %H:%M:%S') ==="
echo "Lingkungan: $(uname -sm); PHP: $(${PHPBIN:-$([ -x /Applications/XAMPP/xamppfiles/bin/php ] && echo /Applications/XAMPP/xamppfiles/bin/php || echo php)} -r 'echo PHP_VERSION;' 2>/dev/null || echo '-'); alat: $($AB -V 2>&1 | head -1 | sed 's/This is //')"
echo "Target: $BASE; permintaan per skenario: $N; satu sesi login ($USERNAME) dipakai bersama"
printf "%-12s %5s %9s %12s %8s %8s %8s %7s\n" endpoint conc req/detik "rerata(ms)" "p50(ms)" "p95(ms)" "maks(ms)" gagal
for R in metrics orders patients; do
  for C in 1 10 25; do
    T="$(mktemp)"
    $AB -l -q -n "$N" -c "$C" -C "PHPSESSID=$SID" "$BASE?r=$R" > "$T" 2>&1
    rps="$(awk '/Requests per second/{print $4}' "$T")"; mean="$(awk '/Time per request.*\(mean\)$/{print $4; exit}' "$T")"
    p50="$(awk '$1=="50%"{print $2}' "$T")"; p95="$(awk '$1=="95%"{print $2}' "$T")"; mx="$(awk '$1=="100%"{print $2}' "$T")"
    fail="$(awk '/Failed requests/{print $3}' "$T")"; non="$(awk '/Non-2xx responses/{print $3}' "$T")"
    printf "%-12s %5s %9s %12s %8s %8s %8s %7s\n" "$R" "$C" "${rps:--}" "${mean:--}" "${p50:--}" "${p95:--}" "${mx:--}" "$((${fail:-0} + ${non:-0}))"
    rm -f "$T"
  done
done
echo
} | tee -a "$OUT"
rm -f "$CJ"
