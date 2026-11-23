#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
chmod 700 "$work"
mkdir -m 700 "$work/showcase"
php bin/prepare-public-showcase.php "$work/showcase" > "$work/setup.log"
printf '%s' 'PRIVATE HOSTING DATA NEVER SHARED' > "$work/private-hosting-secret.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
env -u FFM_USERNAME -u FFM_PASSWORD_HASH -u FFM_STORAGE_ROOT -u FFM_TRASH_ROOT -u FFM_TEST_STORAGE_ROOT -u FFM_TEST_TRASH_ROOT \
 FFM_PUBLIC_SHOWCASE=1 FFM_PUBLIC_SHOWCASE_ROOT="$work/showcase" FFM_TEST_SANDBOX=0 FFM_DEMO_MODE=0 \
 FFM_ALLOW_HTTP_DEV=1 php -S "127.0.0.1:$port" -t public public/router.php > "$work/server.log" 2>&1 &
pid=$!
cleanup() { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:$port/" -o "$work/index.html" 2>/dev/null; then break; fi
  sleep .1
done
[[ -s "$work/index.html" ]] || { cat "$work/server.log"; exit 1; }
grep -q 'demo123' "$work/index.html"
code="$(curl -sS -o "$work/unauthorized" -w '%{http_code}' "$base?action=me")"
[[ "$code" == '401' ]] || { cat "$work/unauthorized"; echo "Expected 401; got $code"; exit 1; }
code="$(curl -sS -o "$work/bad-login" -w '%{http_code}' -c "$work/cookies" -b "$work/cookies" -H 'Content-Type: application/json' -d '{"username":"demo","password":"wrong"}' "$base?action=login")"
[[ "$code" == '401' ]]
curl -fsS -c "$work/cookies" -b "$work/cookies" -H 'Content-Type: application/json' -d '{"username":"demo","password":"demo123"}' "$base?action=login" > "$work/login"
csrf="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["csrf"]??"";' "$work/login")"
[[ ${#csrf} -ge 40 ]]
curl -fsS -b "$work/cookies" "$base?action=me" > "$work/me"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["username"]??"")!=="demo"||($j["capabilities"]["readOnly"]??null)!==true||($j["capabilities"]["publicShowcase"]??null)!==true||($j["capabilities"]["upload"]??true)!==false||($j["capabilities"]["chmod"]??true)!==false||($j["capabilities"]["trash"]??true)!==false)exit(1);' "$work/me"
curl -fsS -b "$work/cookies" "$base?action=list&path=public_html&page=1" > "$work/list"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["data"]["total"]??null)!==3)exit(1);' "$work/list"
curl -fsS -b "$work/cookies" "$base?action=read&path=public_html/index.html" > "$work/read"
grep -q 'Example website' "$work/read"
curl -fsS -b "$work/cookies" "$base?action=download&path=README.txt" > "$work/download"
grep -q 'PUBLIC PREVIEW' "$work/download"
for path in '.forge-public-showcase.json' '../private-hosting-secret.txt' 'private/secret.txt'; do
  status="$(curl -sS -o "$work/blocked" -w '%{http_code}' -b "$work/cookies" --get --data-urlencode "action=read" --data-urlencode "path=$path" "$base")"
  [[ "$status" == '403' || "$status" == '404' ]] || { echo "Unauthorized read $path returned $status"; exit 1; }
done
for action in create chmod delete upload zip extract bulk-transfer; do
  status="$(curl -sS -o "$work/write-reject" -w '%{http_code}' -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{"path":"README.txt","name":"new-file.txt","kind":"file","mode":"777"}' "$base?action=$action")"
  [[ "$status" == '403' ]] || { echo "$action returned $status"; cat "$work/write-reject"; exit 1; }
done
[[ ! -f "$work/showcase/new-file.txt" ]]
[[ "$(cat "$work/private-hosting-secret.txt")" == 'PRIVATE HOSTING DATA NEVER SHARED' ]]
echo 'PASS: public demo login and authorized read-only sample browsing'
echo 'PASS: all tested mutations rejected server-side, actual hosting data unchanged'
