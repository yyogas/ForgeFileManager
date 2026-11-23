#!/usr/bin/env bash
# Public demo removed. Known credentials work only on an explicit private local filesystem sandbox.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -m 700 "$work/sandbox" "$work/test-trash" "$work/real-hosting"
mkdir -p "$work/sandbox/public_html"
printf '%s' 'LOCAL EXAMPLE FILE' > "$work/sandbox/public_html/example.txt"
printf '%s' 'REAL HOSTING SECRET' > "$work/real-hosting/secret.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
env -u FFM_USERNAME -u FFM_PASSWORD_HASH -u FFM_STORAGE_ROOT -u FFM_TRASH_ROOT \
 FFM_TEST_SANDBOX=1 FFM_TEST_STORAGE_ROOT="$work/sandbox" FFM_TEST_TRASH_ROOT="$work/test-trash" \
 FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 php -S "127.0.0.1:$port" -t public public/router.php > "$work/php.log" 2>&1 &
pid=$!
cleanup() { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port"
for _ in $(seq 1 45); do if curl -fsS "$base/" -o "$work/index.html" 2>/dev/null; then break; fi; sleep .12; done
[[ -s "$work/index.html" ]] || { cat "$work/php.log"; exit 1; }
grep -q '<code>demo</code>' "$work/index.html"
grep -q '<code>demo123</code>' "$work/index.html"
! grep -q 'virtual read-only' "$work/index.html"
echo 'PASS: login retains demo/demo123 hint without old virtual demonstration'
for lang in en fr; do
  curl -fsS "$base/languages/$lang.json" > "$work/$lang.json"
  php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||!isset($j["Corbeille"]))exit(1);' "$work/$lang.json"
done
echo 'PASS: English and French translations include recycle-bin terms'
code="$(curl -sS -o "$work/unauth" -w '%{http_code}' "$base/api.php?action=list&path=")"
[[ "$code" == '401' ]] || { echo "Expected 401, got $code"; exit 1; }
code="$(curl -sS -o "$work/wrong" -w '%{http_code}' -b "$work/cookies" -c "$work/cookies" \
  -H 'Content-Type: application/json' -d '{"username":"demo","password":"wrong"}' "$base/api.php?action=login")"
[[ "$code" == '401' ]] || exit 1
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
 -d '{"username":"demo","password":"demo123"}' "$base/api.php?action=login" > "$work/login"
csrf="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["csrf"];' "$work/login")"
curl -fsS -b "$work/cookies" "$base/api.php?action=me" > "$work/me"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["capabilities"]["testSandbox"]??false)!==true||($j["capabilities"]["trash"]??false)!==true||($j["capabilities"]["readOnly"]??false)===true||isset($j["demo"]))exit(1);' "$work/me"
echo 'PASS: explicit local sandbox uses real filesystem operations, not virtual demo'
curl -fsS -b "$work/cookies" "$base/api.php?action=list&path=public_html" > "$work/list"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["data"]["items"][0]["name"]??"")!=="example.txt")exit(1);' "$work/list"
code="$(curl -sS -o "$work/leak" -w '%{http_code}' -b "$work/cookies" "$base/api.php?action=read&path=secret.txt")"
[[ "$code" == '404' ]] || exit 1
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"path":"","name":"sandbox-created.txt","kind":"file"}' "$base/api.php?action=create" > "$work/create"
[[ -f "$work/sandbox/sandbox-created.txt" ]] || exit 1
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"path":"sandbox-created.txt"}' "$base/api.php?action=delete" > "$work/recycled"
[[ ! -e "$work/sandbox/sandbox-created.txt" ]] || exit 1
curl -fsS -b "$work/cookies" "$base/api.php?action=trash-list&page=1" > "$work/trash-list"
id="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["data"]["items"][0]["id"];' "$work/trash-list")"
[[ "${#id}" == 32 ]] || exit 1
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d "{\"id\":\"$id\"}" "$base/api.php?action=trash-restore" > "$work/restore"
[[ -f "$work/sandbox/sandbox-created.txt" ]] || exit 1
[[ "$(cat "$work/real-hosting/secret.txt")" == 'REAL HOSTING SECRET' ]] || exit 1
echo 'PASS: create/recycle/restore works exclusively in the test sandbox, real hosting remains untouched'
