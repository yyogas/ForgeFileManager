#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
root="$work/hosting"
mkdir -p "$root"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
pass='test-password-1234'
hash="$(php -r 'echo password_hash("test-password-1234",PASSWORD_DEFAULT);')"
FFM_USERNAME=testuser FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$root" FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
  php -S "127.0.0.1:$port" -t public public/router.php >"$work/server.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port"
for _ in $(seq 1 40); do if curl -s "$base/" >/dev/null 2>&1; then break; fi; sleep 0.15; done
http="$(curl -s -o "$work/unauth.json" -w '%{http_code}' "$base/api.php?action=list&path=")"
[[ "$http" == "401" ]] || { echo "Unauthenticated request returned $http"; exit 1; }
echo 'PASS: unauthenticated API blocked'
http="$(curl -s -o "$work/login-fail.json" -w '%{http_code}' -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' -d '{"username":"testuser","password":"bad"}' "$base/api.php?action=login")"
[[ "$http" == '401' ]] || exit 1
echo 'PASS: bad credentials rejected'
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
  -d '{"username":"testuser","password":"test-password-1234"}' "$base/api.php?action=login" > "$work/login.json"
csrf="$(php -r '$v=json_decode(file_get_contents($argv[1]),true);echo $v["csrf"];' "$work/login.json")"
[[ ${#csrf} == 64 ]] || exit 1
echo 'PASS: authenticated session issues CSRF'
http="$(curl -s -o "$work/csrf.json" -w '%{http_code}' -b "$work/cookies" -H 'Content-Type: application/json' -d '{"path":"","name":"bad","kind":"file"}' "$base/api.php?action=create")"
[[ "$http" == '403' ]] || exit 1
echo 'PASS: CSRF enforcement'
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"path":"","name":"newfile.txt","kind":"file"}' "$base/api.php?action=create" > "$work/create.json"
[[ -f "$root/newfile.txt" ]] || exit 1
echo 'PASS: create file through authenticated API'
curl -fsS -b "$work/cookies" "$base/api.php?action=list&path=" > "$work/list.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);if(count($d["data"]["items"])!==1)exit(1);' "$work/list.json"
echo 'PASS: list API'
http="$(curl -s -o "$work/traversal.json" -w '%{http_code}' -b "$work/cookies" "$base/api.php?action=list&path=..%2F..%2Fetc")"
[[ "$http" == '400' ]] || { echo "Traversal returned $http";exit 1; }
echo 'PASS: traversal rejected via HTTP'
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"path":"newfile.txt","mode":"640"}' "$base/api.php?action=chmod" > "$work/chmod.json"
[[ "$(stat -c %a "$root/newfile.txt")" == '640' ]] || exit 1
echo 'PASS: CHMOD through API'
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
  -d '{"path":"newfile.txt"}' "$base/api.php?action=delete" > "$work/delete.json"
[[ ! -e "$root/newfile.txt" ]] || exit 1
echo 'PASS: delete file'
echo 'HTTP smoke tests passed.'