#!/usr/bin/env bash
# Verifies that public demo credentials cannot access live hosting files and that locale catalogs are delivered.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -p "$work/private-hosting"
printf '%s' 'DO NOT LEAK HOSTING FILES' > "$work/private-hosting/secret.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
FFM_DEMO_MODE=1 FFM_USERNAME=hosting_admin FFM_PASSWORD_HASH=invalid FFM_STORAGE_ROOT="$work/private-hosting" \
 FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 php -S "127.0.0.1:$port" -t public public/router.php > "$work/php.log" 2>&1 &
pid=$!
cleanup() { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port"
for _ in $(seq 1 50); do if curl -fsS "$base/" -o "$work/index.html" 2>/dev/null; then break; fi; sleep 0.1; done
[[ -s "$work/index.html" ]] || { cat "$work/php.log"; exit 1; }
grep -q 'value="demo"' "$work/index.html"
grep -q 'value="demo123"' "$work/index.html"
grep -q 'id="login-language"' "$work/index.html"
grep -q 'id="header-language"' "$work/index.html"
echo 'PASS: demo login presented and language selectors rendered'
for lang in en fr; do
  curl -fsS "$base/languages/$lang.json" -o "$work/$lang.json"
  php -r '$v=json_decode(file_get_contents($argv[1]),true);if(!is_array($v)||!isset($v["Connexion sécurisée"]))exit(1);' "$work/$lang.json"
done
php -r '$v=json_decode(file_get_contents($argv[1]),true);if($v["Connexion sécurisée"]!=="Secure sign in")exit(1);' "$work/en.json"
echo 'PASS: English/French catalogs available'
status="$(curl -sS -o "$work/unauth" -w '%{http_code}' "$base/api.php?action=list&path=")"
[[ "$status" == '401' ]] || exit 1
status="$(curl -sS -b "$work/session" -c "$work/session" -H 'Content-Type: application/json' \
 -d '{"username":"demo","password":"wrong"}' -o "$work/wrong" -w '%{http_code}' "$base/api.php?action=login")"
[[ "$status" == '401' ]] || exit 1
curl -fsS -b "$work/session" -c "$work/session" -H 'Content-Type: application/json' \
 -d '{"username":"demo","password":"demo123"}' "$base/api.php?action=login" -o "$work/login"
csrf="$(php -r '$v=json_decode(file_get_contents($argv[1]),true);echo $v["csrf"];' "$work/login")"
[[ ${#csrf} -eq 64 ]] || exit 1
curl -fsS -b "$work/session" "$base/api.php?action=me" -o "$work/me"
php -r '$v=json_decode(file_get_contents($argv[1]),true);if(($v["demo"]??false)!==true || ($v["capabilities"]["readOnly"]??false)!==true || ($v["capabilities"]["chmod"]??true)!==false)exit(1);' "$work/me"
echo 'PASS: demo/demo123 is isolated and advertises read-only capabilities'
curl -fsS -b "$work/session" "$base/api.php?action=list&path=" -o "$work/list"
php -r '$v=json_decode(file_get_contents($argv[1]),true);$names=array_column($v["data"]["items"],"name");if(in_array("secret.txt",$names,true)||!in_array("public_html",$names,true))exit(1);' "$work/list"
status="$(curl -sS -o "$work/secret" -w '%{http_code}' -b "$work/session" "$base/api.php?action=read&path=secret.txt")"
[[ "$status" == '404' ]] || { echo "Real hosting read returned $status"; exit 1; }
status="$(curl -sS -o "$work/traversal" -w '%{http_code}' -b "$work/session" "$base/api.php?action=read&path=..%2Fsecret.txt")"
[[ "$status" == '404' ]] || exit 1
echo 'PASS: demo cannot read or traverse into real hosting files'
for action in create save chmod rename copy move zip delete upload; do
  status="$(curl -sS -o "$work/reject" -w '%{http_code}' -b "$work/session" -H "X-CSRF-Token: $csrf" \
    -H 'Content-Type: application/json' -d '{"path":"secret.txt","name":"attack","kind":"file","mode":"777"}' "$base/api.php?action=$action")"
  [[ "$status" == '403' ]] || { echo "Demo operation $action unexpectedly returned $status"; exit 1; }
done
[[ "$(cat "$work/private-hosting/secret.txt")" == 'DO NOT LEAK HOSTING FILES' ]] || exit 1
echo 'PASS: all writes denied; hosting data unchanged'
status="$(curl -sS -o "$work/download" -w '%{http_code}' -b "$work/session" "$base/api.php?action=download&path=secret.txt")"
[[ "$status" == '403' ]] || exit 1
curl -fsS -b "$work/session" "$base/api.php?action=read&path=README.txt" -o "$work/readme"
php -r '$v=json_decode(file_get_contents($argv[1]),true);if(!str_contains($v["data"]["content"],"virtual read-only sandbox"))exit(1);' "$work/readme"
echo 'PASS: virtual sample readable, all real downloads blocked'
