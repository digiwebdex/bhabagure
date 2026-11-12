#!/usr/bin/env bash
# deploy/upload-agent-package.sh — on the developer's Windows PC, in Git Bash, after agent/build.ps1
# (docs/phase-7-hr-attendance-bonus-wallet.md §5.2):
#
#   bash deploy/upload-agent-package.sh          the version in agent/bhabaghure_attendance/__init__.py
#   bash deploy/upload-agent-package.sh 1.1.0    that version
#
# Puts the office attendance agent's install zip where the admin's Attendance card offers it for download (GET
# /api/v1/admin/attendance/agent-package): agent/dist/BhabaghureAttendanceAgent-<version>.zip goes to the server's
# api/storage/app/private/attendance-agent/, with latest.json beside it — version, file name, size, SHA-256 (computed
# here, and checked again on the server) and upload time. Both end up www-data's, mode 640; the folder is created
# (www-data, 750) if it is missing. The build is never committed (the repository is public), so no deploy carries it.
#
# Over ssh and scp with your key only (BatchMode: never a password prompt). It writes inside that one folder and deletes
# nothing: an earlier version's zip stays until someone removes it by hand. The zip arrives under a temporary name and is
# renamed into place once its SHA-256 matches, so a download never meets half a file; latest.json is renamed in last, so
# it never names a zip that isn't there.

set -euo pipefail

# The project's own VPS since 2026-11-06 (docs/deployment.md §8); ~/.ssh/config names its key.
SERVER=root@201.18.220.51
DIR=/var/www/Bhabagure/api/storage/app/private/attendance-agent
ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SSH_OPTS=(-o BatchMode=yes)

die() { printf 'STOPPED: %s\n' "$*" >&2; exit 1; }
say() { printf '== %s\n' "$*"; }

# Git Bash rewrites arguments that look like paths (/var/www/...) when it starts a Windows program, such as Windows' own
# ssh.exe; the server's paths must reach ssh as written. Local files are named relative to the repository for the same
# reason.
export MSYS_NO_PATHCONV=1
cd "$ROOT"

version=${1:-$(sed -n 's/^VERSION = "\([^"]*\)".*/\1/p' agent/bhabaghure_attendance/__init__.py)}
[[ $version =~ ^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}$ ]] || die "the version '$version' isn't like 1.1.0."
name="BhabaghureAttendanceAgent-$version.zip"
zip="agent/dist/$name"
[[ -f $zip ]] || die "$zip is missing. Build it first: in agent/, run .\\build.ps1 in PowerShell."
for tool in ssh scp sha256sum; do
  command -v "$tool" >/dev/null || die "$tool is missing; run this in Git Bash."
done

sha256=$(sha256sum "$zip" | cut -d' ' -f1)
bytes=$(wc -c < "$zip" | tr -d '[:space:]')
uploaded_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
[[ $sha256 =~ ^[0-9a-f]{64}$ ]] || die "couldn't compute the SHA-256 of $zip."
[[ $bytes =~ ^[0-9]+$ && $bytes -gt 0 ]] || die "$zip is empty."

say "Upload $name ($bytes bytes) to $SERVER:$DIR"
# The folder, if this is the first upload: www-data's, closed to everyone else. Its parent is the API's own storage.
ssh "${SSH_OPTS[@]}" "$SERVER" bash -s -- "$DIR" <<'REMOTE'
set -euo pipefail
dir=$1
[[ -d ${dir%/*} ]] || { echo "STOPPED: ${dir%/*} is missing on the server: is the API deployed there?" >&2; exit 1; }
if [[ ! -d $dir ]]; then
  install -d -m 750 -o www-data -g www-data "$dir"
  echo "   created $dir (www-data, 750)"
fi
REMOTE

scp "${SSH_OPTS[@]}" -q "$zip" "$SERVER:$DIR/.$name.part"

# On the server: check the zip arrived whole, write latest.json, hand both to www-data, then rename them into place.
ssh "${SSH_OPTS[@]}" "$SERVER" bash -s -- "$DIR" "$name" "$version" "$bytes" "$sha256" "$uploaded_at" <<'REMOTE'
set -euo pipefail
dir=$1 name=$2 version=$3 bytes=$4 sha256=$5 uploaded_at=$6
cd "$dir"
part=".$name.part"
[[ $(sha256sum "$part" | cut -d' ' -f1) == "$sha256" ]] || { echo "STOPPED: $name arrived changed (its SHA-256 differs); nothing was replaced." >&2; exit 1; }
printf '{"version": "%s", "filename": "%s", "bytes": %s, "sha256": "%s", "uploaded_at": "%s"}\n' \
  "$version" "$name" "$bytes" "$sha256" "$uploaded_at" > .latest.json.part
chown www-data:www-data "$part" .latest.json.part
chmod 640 "$part" .latest.json.part
mv -f "$part" "$name"
mv -f .latest.json.part latest.json
ls -l "$name" latest.json | sed 's/^/   /'
REMOTE

say "Uploaded: the Attendance card now offers it for download"
printf '   %-12s %s\n' version "$version" file "$name" bytes "$bytes" sha256 "$sha256" uploaded_at "$uploaded_at"
