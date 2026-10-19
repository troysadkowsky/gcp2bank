#!/bin/bash
# Deploys tracked files in this repo to the live site over FTPS (explicit TLS).
# Only uploads files `git ls-files` knows about — never touches db_config.php,
# admin_secret.php, or /data/, which are gitignored and live only on the server.
set -euo pipefail
cd "$(dirname "$0")"

FTP_HOST="ftp.datascientists.com"
FTP_USER="gcp2@gcp2.datascientists.com"
REMOTE_DIR="${REMOTE_DIR:-}"   # set if the account doesn't land in the web root, e.g. REMOTE_DIR=public_html

# gitignored — holds `FTP_PASSWORD=...` so you aren't prompted every run
[ -f "deploy_ftp.env" ] && source deploy_ftp.env

if [ -z "${FTP_PASSWORD:-}" ]; then
  read -r -s -p "FTP password for $FTP_USER: " FTP_PASSWORD
  echo
fi

files=$(git ls-files)
count=$(echo "$files" | wc -l | tr -d ' ')
echo "Uploading $count tracked file(s) to $FTP_HOST${REMOTE_DIR:+/$REMOTE_DIR}..."

failed=()
while IFS= read -r f; do
  remote_path="${REMOTE_DIR:+$REMOTE_DIR/}$f"
  # --insecure: this host's FTPS cert is GoDaddy's shared *.prod.sin2.secureserver.net
  # wildcard, which never matches ftp.datascientists.com, so curl can't verify it —
  # the connection is still encrypted, just not identity-checked.
  # --ftp-ssl-control (not --ftp-ssl): encrypts the login, but leaves data transfers
  # in plain FTP — this server's TLS data channel drops larger files (451 mid-transfer,
  # seen on index.html) even though small files go through fine over it.
  if curl -s -S --insecure --ftp-ssl-control --ftp-create-dirs \
    --user "$FTP_USER:$FTP_PASSWORD" \
    -T "$f" \
    "ftp://$FTP_HOST/$remote_path"; then
    echo "  ok    $f"
  else
    echo "  FAILED $f"
    failed+=("$f")
  fi
done <<< "$files"

if [ "${#failed[@]}" -gt 0 ]; then
  echo
  echo "Done with ${#failed[@]} failure(s):"
  printf '  %s\n' "${failed[@]}"
  exit 1
fi

echo "Done — all $count file(s) uploaded."
