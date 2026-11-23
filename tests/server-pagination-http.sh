#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
mkdir -p "$work/files"
for i in $(seq 1 107); do printf -v name 'file-%04d.txt' "$i"; printf '%s\n' "$i" > "$work/files/$name"; done
printf 'secret' > "$work/files/.hidden.txt"
mkdir "$work/files/folderA"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
hash="$(php -r 'echo password_hash("sample-staging-password", PASSWORD_DEFAULT);')"
FFM_USERNAME=staging FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$work/files" FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
 php -S "127.0.0.1:$port" -t public public/router.php > "$work/server.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 50); do if curl -s -o /dev/null "$base?action=me"; then break; fi; sleep .1; done
status="$(curl -sS -o "$work/deny" -w '%{http_code}' "$base?action=list&path=&page=2")"
[[ "$status" == 401 ]] || { echo "Expected 401, got $status"; exit 1; }
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
 -d '{"username":"staging","password":"sample-staging-password"}' "$base?action=login" > "$work/login"
curl -fsS -b "$work/cookies" "$base?action=list&path=&page=1" > "$work/first"
curl -fsS -b "$work/cookies" "$base?action=list&path=&page=2" > "$work/second"
curl -fsS -b "$work/cookies" "$base?action=list&path=&page=5" > "$work/last"
curl -fsS -b "$work/cookies" "$base?action=list&path=&search=file-010&sort=name-desc&page=1" > "$work/search"
curl -fsS -b "$work/cookies" "$base?action=list&path=&hidden=1" > "$work/hidden"
php -r '
function data(string $path): array { $v=json_decode(file_get_contents($path), true); return $v["data"] ?? []; }
$a=data($argv[1]);$b=data($argv[2]);$c=data($argv[3]);$d=data($argv[4]);$h=data($argv[5]);
if ($a["total"]!==108 || count($a["items"])!==25 || $a["pages"]!==5 || $a["page"]!==1) exit(1);
if ($b["page"]!==2 || count($b["items"])!==25 || $a["items"][24]["name"]===$b["items"][0]["name"]) exit(2);
if ($c["page"]!==5 || count($c["items"])!==8) exit(3);
if ($d["total"]!==8 || $d["items"][0]["name"]!=="file-0107.txt") exit(4);
if ($h["total"]!==109 || !in_array(".hidden.txt", array_column($h["items"],"name"), true)) exit(5);
' "$work/first" "$work/second" "$work/last" "$work/search" "$work/hidden"
echo 'PASS: HTTP paginated 108 files across 5 pages, search, sort and hidden entries'
for query in 'page=0' 'page=-1' 'page=1000001' 'sort=../../' 'hidden=yes' 'search=%FF'; do
  code="$(curl -sS -b "$work/cookies" -o "$work/bad" -w '%{http_code}' "$base?action=list&path=&$query")"
  [[ "$code" == 400 ]] || { echo "FAIL: $query returned $code"; exit 1; }
done
echo 'PASS: invalid query values produce HTTP 400'
