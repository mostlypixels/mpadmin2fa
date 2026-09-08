#!/usr/bin/env bash
set -euo pipefail

# Pinned original 0.2.7 revision. Never substitute current source.
revision=9334de7296f98d4248af4b7b541038ad34da22cc
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
: "${MP2FA_HISTORICAL_OUTPUT:?Set an output directory outside the module source}"
output="$(realpath -m "$MP2FA_HISTORICAL_OUTPUT")"
case "$output/" in "$module_root/"*) echo 'Historical output must be outside the module source'; exit 1;; esac
mkdir -p "$output"
[[ ! -e "$output/mpadmin2fa" ]] || { echo 'Historical output already exists'; exit 1; }
build_origin() {
  local version="$1" revision="$2"
  local stage="$output/source-$version"
  mkdir -p "$stage/mpadmin2fa"
  git -C "$module_root" archive "$revision" | tar -x -C "$stage/mpadmin2fa"
  composer install --working-dir="$stage/mpadmin2fa" --no-dev --prefer-dist --no-interaction --no-progress
  printf '%s\n' "$revision" > "$output/SOURCE_COMMIT-$version"
  (cd "$stage" && zip -qr "$output/mpadmin2fa-$version.zip" mpadmin2fa)
  (cd "$output" && sha256sum "mpadmin2fa-$version.zip" > "mpadmin2fa-$version.zip.sha256")
  echo "Historical $version package built from $revision"
}
[[ ! -e "$output/source-0.2.7" && ! -e "$output/source-0.2.8" ]] || { echo 'Historical output already exists'; exit 1; }
build_origin 0.2.7 "$revision"
build_origin 0.2.8 bdd0ff970b327f8fea2c07eef6e5573e8fc33bf9
