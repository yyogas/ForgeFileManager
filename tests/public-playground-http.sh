#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
chmod 700 "$work"
mkdir -m 700 "$work/playground"
php bin/prepare-public-playground.php "$work/playground" > "$work/setup.log"
printf 'CONFIDENTIAL HOSTING FILE\n' > "$work/secret.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
env -u FFM_USERNAME -u FFM_PASSWORD_HASH -u FFM_STORAGE_ROOT -u FFM_TRASH_ROOT -u FFM_PUBLIC_SHOWCASE -u FFM_PUBLIC_SHOWCASE_ROOT \
  FFM_PUBLIC_PLAYGROUND=1 FFM_PUBLIC_PLAYGROUND_ROOT="$work/playground" FFM_ALLOW_HTTP_DEV=1 \
  php -S "127.0.0.1:$port" -t public public/router.php >"$work/server.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 60); do curl -fsS "http://127.0.0.1:$port/" -o "$work/index.html" 2>/dev/null && break; sleep .1; done
[[ -s "$work/index.html" ]] || { cat "$work/server.log"; exit 1; }
grep -q 'demo123' "$work/index.html"
# A/B must never share a workspace even with the same known credentials.
for user in A B; do
 curl -fsS -c "$work/cookie$user" -b "$work/cookie$user" -H 'Content-Type: application/json' \
 -d '{"username":"demo","password":"demo123"}' "$base?action=login" > "$work/login$user"
 curl -fsS -b "$work/cookie$user" "$base?action=me" > "$work/me$user"
 php -r '$j=json_decode(file_get_contents($argv[1]),true); $c=$j["capabilities"]??[];if(($c["readOnly"]??true)!==false||($c["publicPlayground"]??false)!==true||($c["upload"]??false)!==true||($c["chmod"]??false)!==true||($c["trash"]??false)!==true)exit(1);' "$work/me$user"
done
csrfA="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["csrf"]??"";' "$work/loginA")"
[[ ${#csrfA} -ge 32 ]]
code="$(curl -sS -o "$work/no-csrf" -w '%{http_code}' -b "$work/cookieA" -H 'Content-Type: application/json' -d '{"path":"","name":"invalid.txt","kind":"file"}' "$base?action=create")"
[[ "$code" == '403' ]] || { echo "No-CSRF status=$code"; exit 1; }
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d '{"path":"","name":"userA.txt","kind":"file"}' "$base?action=create" > "$work/create"
curl -fsS -b "$work/cookieA" "$base?action=read&path=userA.txt" > "$work/initial"
sha="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["data"]["hash"]??"";' "$work/initial")"
[[ ${#sha} == 64 ]] || { cat "$work/initial"; exit 1; }
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d "{\"path\":\"userA.txt\",\"content\":\"Hello from A\",\"hash\":\"$sha\"}" "$base?action=save" > "$work/saved"
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d '{"path":"userA.txt","mode":"640"}' "$base?action=chmod" > "$work/chmod"
code="$(curl -sS -o "$work/b-private" -w '%{http_code}' -b "$work/cookieB" "$base?action=read&path=userA.txt")"
[[ "$code" == '404' ]] || { echo "B accessed A: $code"; exit 1; }
code="$(curl -sS -o "$work/escape" -w '%{http_code}' -b "$work/cookieA" --get --data-urlencode 'action=read' --data-urlencode 'path=../../secret.txt' "$base")"
[[ "$code" == '400' || "$code" == '403' || "$code" == '404' ]] || exit 1
printf 'sample file for upload\n' > "$work/upload.txt"
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -F path= -F file=@"$work/upload.txt" "$base?action=upload" > "$work/upload-result"
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d '{"path":"userA.txt"}' "$base?action=delete" > "$work/trashed"
curl -fsS -b "$work/cookieA" "$base?action=trash-list&page=1" > "$work/trash-list"
item="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);echo $j["data"]["items"][0]["id"]??"";' "$work/trash-list")"
[[ ${#item} == 32 ]] || { cat "$work/trash-list"; exit 1; }
curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d "{\"id\":\"$item\"}" "$base?action=trash-restore" > "$work/restore"
curl -fsS -b "$work/cookieA" "$base?action=download&path=userA.txt" > "$work/download"
[[ "$(cat "$work/download")" == 'Hello from A' ]]
[[ "$(cat "$work/secret.txt")" == 'CONFIDENTIAL HOSTING FILE' ]]
if php -r 'exit(class_exists("ZipArchive")?0:1);';then
 curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d '{"path":"userA.txt"}' "$base?action=zip" > "$work/zip"
 curl -fsS -b "$work/cookieA" -H "X-CSRF-Token: $csrfA" -H 'Content-Type: application/json' -d '{"path":"userA.txt.zip","directory":""}' "$base?action=extract" > "$work/extract"
 echo 'PASS: ZIP compression and extraction in private demo workspace'
else
 echo 'NOTICE: local PHP lacks ext-zip; ZIP checks require CI with ext-zip'
fi
for user in A B;do
 n="$(find "$work/playground/sessions" -mindepth 1 -maxdepth 1 -type d | wc -l)"
 [[ "$n" == '2' ]] || exit 1
done
echo 'PASS: A and B isolated, editor/CHMOD/upload/trash/restoration work with public demo credentials'
echo 'PASS: CSRF and path traversal denied; external hosting-like file unchanged'
