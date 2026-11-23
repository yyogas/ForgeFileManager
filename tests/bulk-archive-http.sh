#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -p "$work/files/target" "$work/files/conflict"
printf 'a' > "$work/files/a.txt"
printf 'b' > "$work/files/b.txt"
printf 'do not replace' > "$work/files/conflict/b.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
hash="$(php -r 'echo password_hash("test-strong-password", PASSWORD_DEFAULT);')"
FFM_USERNAME=hosting_tester FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$work/files" FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
 php -S "127.0.0.1:$port" -t public public/router.php > "$work/server.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 50); do curl -sS -o /dev/null "$base?action=me" && break; sleep .1; done
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
  -d '{"username":"hosting_tester","password":"test-strong-password"}' "$base?action=login" > "$work/login"
csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["csrf"];' "$work/login")"
code="$(curl -sS -b "$work/cookies" -o "$work/no-csrf" -w '%{http_code}' -H 'Content-Type: application/json' \
  -d '{"paths":["a.txt","b.txt"],"directory":"target","mode":"copy"}' "$base?action=bulk-transfer")"
[[ "$code" == 403 ]] || { echo "Missing CSRF unexpectedly returned HTTP $code"; exit 1; }
code="$(curl -sS -b "$work/cookies" -o "$work/invalid" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
  -d '{"paths":["a.txt","a.txt"],"directory":"target","mode":"copy"}' "$base?action=bulk-transfer")"
[[ "$code" == 400 ]] || { echo "Duplicate bulk request returned HTTP $code"; exit 1; }
curl -fsS -b "$work/cookies" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
  -d '{"paths":["a.txt","b.txt"],"directory":"target","mode":"copy"}' "$base?action=bulk-transfer" > "$work/bulk"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["data"]["success"]??null)!==2||($j["data"]["failed"]??null)!==0)exit(1);' "$work/bulk"
[[ "$(cat "$work/files/target/a.txt")" == 'a' && "$(cat "$work/files/a.txt")" == 'a' ]]
# Valid request with per-item move failure (existing destination) and success.
curl -fsS -b "$work/cookies" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
  -d '{"paths":["a.txt","b.txt"],"directory":"conflict","mode":"move"}' "$base?action=bulk-transfer" > "$work/mixed"
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["data"]["success"]??null)!==1||($j["data"]["failed"]??null)!==1)exit(1);' "$work/mixed"
[[ ! -e "$work/files/a.txt" && -e "$work/files/b.txt" && "$(cat "$work/files/conflict/b.txt")" == 'do not replace' ]]
echo 'PASS: HTTP bulk transfer, CSRF, validation, partial results, no overwrite'

if php -r 'exit(class_exists("ZipArchive")?0:1);'; then
  php -r '$z=new ZipArchive();$z->open($argv[1],ZipArchive::CREATE);$z->addFromString("dir/file.txt","ok");$z->close();' "$work/files/test.zip"
  curl -fsS -b "$work/cookies" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
    -d '{"path":"test.zip","directory":"target"}' "$base?action=extract" > "$work/extract"
  php -r '$j=json_decode(file_get_contents($argv[1]),true);if(($j["data"]["path"]??null)!=="target/test (extracted)"||($j["data"]["bytes"]??null)!==2)exit(1);' "$work/extract"
  [[ "$(cat "$work/files/target/test (extracted)/dir/file.txt")" == ok ]]
  echo 'PASS: HTTP ZIP extraction behind session and CSRF'
else
  code="$(curl -sS -b "$work/cookies" -o "$work/zip-disabled" -w '%{http_code}' -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" \
   -d '{"path":"a.zip","directory":"target"}' "$base?action=extract")"
  [[ "$code" == 501 ]] || { echo "Missing zip support should return 501, got $code"; exit 1; }
  echo 'SKIP: HTTP ZIP extraction (ext-zip not installed here)'
fi
