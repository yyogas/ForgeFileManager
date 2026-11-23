#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
work="$(mktemp -d)"
root="$work/customer"
mkdir -m 700 -p "$root" "$work/neighbor"
printf 'private data' > "$work/neighbor/secret"
printf 'initial contents' > "$root/readme.txt"
chmod 640 "$root/readme.txt"
touch -d '2 hours ago' "$root/readme.txt"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
hash="$(php -r 'echo password_hash("secure-test-password",PASSWORD_DEFAULT);')"
FFM_USERNAME=tenant_a FFM_PASSWORD_HASH="$hash" FFM_STORAGE_ROOT="$root" FFM_ALLOW_HTTP_DEV=1 FFM_ALLOW_ROOT_DEV=1 \
    php -S "127.0.0.1:$port" -t public public/router.php > "$work/php.log" 2>&1 &
pid=$!
cleanup(){ kill "$pid" 2>/dev/null || :; wait "$pid" 2>/dev/null || :; rm -rf "$work"; }
trap cleanup EXIT
base="http://127.0.0.1:$port/api.php"
for _ in $(seq 1 50); do curl -sS -o /dev/null "$base?action=me" 2>/dev/null && break; sleep .1; done
curl -fsS -b "$work/cookies" -c "$work/cookies" -H 'Content-Type: application/json' \
 -d '{"username":"tenant_a","password":"secure-test-password"}' "$base?action=login" > "$work/login.json"
csrf="$(php -r '$d=json_decode(file_get_contents($argv[1]),true);echo $d["csrf"];' "$work/login.json")"
[[ ${#csrf} -eq 64 ]]
code="$(curl -sS -b "$work/cookies" -o "$work/traversal" -w '%{http_code}' "$base?action=read&path=..%2Fneighbor%2Fsecret")"
[[ "$code" == 400 ]] || { echo "Traversal returned $code"; exit 1; }
ln -s "$work/neighbor/secret" "$root/shortcut"
code="$(curl -sS -b "$work/cookies" -o "$work/symlink" -w '%{http_code}' "$base?action=download&path=shortcut")"
[[ "$code" == 403 ]] || { echo "Symlink download returned $code"; exit 1; }
ln "$work/neighbor/secret" "$root/hardlink"
code="$(curl -sS -b "$work/cookies" -o "$work/hardlink" -w '%{http_code}' "$base?action=read&path=hardlink")"
[[ "$code" == 403 ]] || { echo "Hardlink read returned $code"; exit 1; }
echo 'PASS: account isolation via HTTP (traversal, symlinks and hardlinks)'
curl -fsS -b "$work/cookies" "$base?action=read&path=readme.txt" > "$work/read.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);if(!isset($d["data"]["modified"],$d["data"]["hash"]))exit(1);' "$work/read.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);echo json_encode(["path"=>"readme.txt","content"=>"updated contents","hash"=>$d["data"]["hash"]]);' "$work/read.json" > "$work/save.json"
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 --data-binary "@$work/save.json" "$base?action=save" > "$work/saved.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);if(($d["data"]["mode"]??"")!=="640"||!isset($d["data"]["modified"]))exit(1);' "$work/saved.json"
[[ "$(cat "$root/readme.txt")" == 'updated contents' && "$(stat -c %a "$root/readme.txt")" == '640' ]]
code="$(curl -sS -b "$work/cookies" -o "$work/stale" -w '%{http_code}' -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data-binary "@$work/save.json" "$base?action=save")"
[[ "$code" == 409 ]] || { echo "Stale edit returned $code"; exit 1; }
echo 'PASS: bounded edit, optimistic conflict, preserved mode, modified timestamp'
curl -fsS -b "$work/cookies" "$base?action=download&path=readme.txt" > "$work/download.txt"
[[ "$(cat "$work/download.txt")" == 'updated contents' ]]
curl -fsS -b "$work/cookies" "$base?action=checksum&path=readme.txt" > "$work/checksum.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);if(($d["data"]["checksum"]??null)!==hash("sha256","updated contents"))exit(1);' "$work/checksum.json"
printf '%s' 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' | base64 -d > "$root/thumbnail.gif"
curl -fsS -b "$work/cookies" "$base?action=preview&path=thumbnail.gif" > "$work/preview.gif"
cmp "$root/thumbnail.gif" "$work/preview.gif"
echo 'PASS: verified-handle download, checksum and image preview'
curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
 -d '{"path":"readme.txt","mode":"604"}' "$base?action=chmod" > "$work/chmod.json"
[[ "$(stat -c %a "$root/readme.txt")" == '604' ]]
code="$(curl -sS -b "$work/cookies" -o "$work/world" -w '%{http_code}' -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' -d '{"path":"readme.txt","mode":"777"}' "$base?action=chmod")"
[[ "$code" == 403 ]]
curl -fsS -b "$work/cookies" "$base?action=list&path=" > "$work/list.json"
php -r '$d=json_decode(file_get_contents($argv[1]),true);$rows=$d["data"]["items"];foreach($rows as $row){if($row["name"]==="readme.txt"){if($row["mode"]!=="604"||!isset($row["modifiedIso"]))exit(1);exit(0);}}exit(1);' "$work/list.json"
echo 'PASS: CHMOD and last-modified metadata through API'
if php -r 'exit(class_exists("ZipArchive")?0:1);'; then
    mkdir "$root/assets"
    printf 'archive data' > "$root/assets/file.txt"
    touch -d '2 hours ago' "$root/assets/file.txt"
    curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
     -d '{"path":"assets"}' "$base?action=zip" > "$work/zip.json"
    [[ -f "$root/assets.zip" ]]
    curl -fsS -b "$work/cookies" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
     -d '{"path":"assets.zip","directory":""}' "$base?action=extract" > "$work/extract.json"
    [[ "$(cat "$root/assets (extracted)/assets/file.txt")" == 'archive data' ]]
    original="$(stat -c %Y "$root/assets/file.txt")"
    restored="$(stat -c %Y "$root/assets (extracted)/assets/file.txt")"
    (( restored >= original - 2 && restored <= original + 2 ))
    echo 'PASS: ZIP create/extract and last modification through API'
else
    echo 'SKIP: live ZIP HTTP checks (extension not installed on local PHP)'
fi
