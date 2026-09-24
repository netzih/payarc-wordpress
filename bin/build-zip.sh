#!/usr/bin/env bash
# Build the installable plugin zip: tracked files only, production Composer
# dependencies (the bundled library copied in, not symlinked), no tests.
# Usage: bin/build-zip.sh [output-dir]   -> <output-dir>/payarc-payments-<version>.zip
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-"$root/build"}
version=$(sed -n 's/^ \* Version: *//p' "$root/payarc-payments.php" | head -1)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/payarc-payments" "$out"
git -C "$root" archive HEAD | tar -x -C "$stage/payarc-payments"
(
  cd "$stage/payarc-payments"
  COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
  # composer.lock records the path repository as a symlink; ship a copy.
  lib=vendor/chabadrichmond/payarc-php
  if [ -L "$lib" ]; then rm "$lib" && cp -R lib/payarc-php "$lib"; fi
  rm -rf tests phpunit.xml.dist bin lib/payarc-php/tests lib/payarc-php/phpunit.xml.dist vendor/chabadrichmond/payarc-php/tests vendor/chabadrichmond/payarc-php/phpunit.xml.dist
  find . -name '.DS_Store' -delete
)
if find "$stage" -type l | grep -q .; then
  echo "symlinks left in the build:" >&2; find "$stage" -type l >&2; exit 1
fi
zip="$out/payarc-payments-$version.zip"
rm -f "$zip"
(cd "$stage" && zip -qr "$zip" payarc-payments)
echo "$zip"
