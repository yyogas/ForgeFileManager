#!/usr/bin/env bash
# Regression: a file manager hosted below a URL prefix must never use domain-root assets/API URLs.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -p "$work/docroot/ForgeFileManager" "$work/storage"
cp -a src public "$work/docroot/ForgeFileManager/"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
hash="$(php -r 'echo password_hash("nested-secret-pass", PASSWORD_DEFAULT);')"
FFM_USERNAME=hosting_nested FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$work/storage" FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
    php -S "127.0.0.1:$port" -t "$work/docroot" > "$work/server.log" 2>&1 &
pid=$!
cleanup() { kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/ForgeFileManager/public"
for _ in $(seq 1 50); do curl -fsS "$base/" > "$work/index.html" && break; sleep .1; done
[[ -s "$work/index.html" ]] || { cat "$work/server.log"; exit 1; }
grep -Eq 'href="assets/app\.css\?v=[0-9]+\.[0-9]+\.[0-9]+"' "$work/index.html"
grep -Eq 'src="assets/app\.js\?v=[0-9]+\.[0-9]+\.[0-9]+"' "$work/index.html"
if grep -Eq '(src|href)="/(assets/|api\.php)' "$work/index.html"; then echo 'Unexpected domain-root URLs'; exit 1; fi
echo 'PASS: HTML uses relative assets under a URL prefix'
curl -fsS "$base/assets/app.css" > "$work/app.css"
curl -fsS "$base/assets/app.js" > "$work/app.js"
grep -q "new URL('api.php', document.baseURI)" "$work/app.js"
if grep -q '/api.php?action' "$work/app.js"; then echo 'Unexpected domain-root API URL'; exit 1; fi
echo 'PASS: CSS/JS available and API routing is prefix-safe'
status="$(curl -sS -o "$work/unauth.json" -D "$work/headers.txt" -w '%{http_code}' "$base/api.php?action=me")"
[[ "$status" == '401' ]] || { echo "Unexpected unauthenticated HTTP $status"; exit 1; }
grep -qi 'path=/ForgeFileManager/public/' "$work/headers.txt"
echo 'PASS: unauthenticated API blocked and session cookie path scoped'
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
 -d '{"username":"hosting_nested","password":"nested-secret-pass"}' "$base/api.php?action=login" > "$work/login.json"
curl -fsS -b "$work/cookies" "$base/api.php?action=me" > "$work/me.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);if(($d["username"]??"")!=="hosting_nested")exit(1);' "$work/me.json"
echo 'PASS: login persists across prefix-scoped session'
