#!/usr/bin/env bash
# Regression: public demo disabled and irreversible deletion impossible without private trash.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -m 700 "$work/hosting"
printf '%s' 'preserve me' > "$work/hosting/important.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
hash="$(php -r 'echo password_hash("real-test-password-2026", PASSWORD_DEFAULT);')"
FFM_USERNAME=realuser FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$work/hosting" \
 FFM_TEST_SANDBOX=0 FFM_DEMO_MODE=0 FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
 php -S "127.0.0.1:$port" -t public public/router.php > "$work/php.log" 2>&1 &
pid=$!
cleanup() { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 40); do if curl -sS "$base?action=me" > /dev/null 2>&1; then break; fi; sleep .1; done
code="$(curl -sS -o "$work/demo-login" -w '%{http_code}' -H 'Content-Type: application/json' \
 -d '{"username":"demo","password":"demo123"}' "$base?action=login")"
[[ "$code" == '401' ]] || { echo "FAIL: public demo login returned $code"; exit 1; }
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
 -d '{"username":"realuser","password":"real-test-password-2026"}' "$base?action=login" > "$work/login"
csrf="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["csrf"];' "$work/login")"
curl -fsS -b "$work/cookies" "$base?action=me" > "$work/me"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["capabilities"]["trash"]??true)!==false)exit(1);' "$work/me"
code="$(curl -sS -o "$work/rejected" -w '%{http_code}' -b "$work/cookies" -H "X-CSRF-Token: $csrf" \
 -H 'Content-Type: application/json' -d '{"path":"important.txt"}' "$base?action=delete")"
[[ "$code" == '503' && "$(cat "$work/hosting/important.txt")" == 'preserve me' ]] || { echo "FAIL: missing private trash returned $code"; exit 1; }
echo 'PASS: public demo credentials rejected, missing private trash blocks deletion without data loss'
