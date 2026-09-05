#!/usr/bin/env bash
set -euo pipefail

# Last PS8 0.2.7 revision before the audit fixes. Never substitute current source.
revision=9334de7296f98d4248af4b7b541038ad34da22cc
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
: "${MP2FA_HISTORICAL_OUTPUT:?Set an output directory outside the module source}"
output="$(realpath -m "$MP2FA_HISTORICAL_OUTPUT")"
case "$output/" in "$module_root/"*) echo 'Historical output must be outside the module source'; exit 1;; esac
mkdir -p "$output"
[[ ! -e "$output/mpadmin2fa" ]] || { echo 'Historical output already exists'; exit 1; }
mkdir "$output/mpadmin2fa"
git -C "$module_root" archive "$revision" | tar -x -C "$output/mpadmin2fa"
# Use the historical production lock; do not run today's scoper on old code.
composer install --working-dir="$output/mpadmin2fa" --no-dev --prefer-dist --no-interaction --no-progress
printf '%s\n' "$revision" > "$output/SOURCE_COMMIT"
(cd "$output" && zip -qr mpadmin2fa-0.2.7.zip mpadmin2fa)
(cd "$output" && sha256sum mpadmin2fa-0.2.7.zip > mpadmin2fa-0.2.7.zip.sha256)
echo "Historical 0.2.7 package built from $revision"
