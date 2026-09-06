#!/usr/bin/env bash
set -euo pipefail

: "${MP2FA_PS_ROOT:?Point MP2FA_PS_ROOT at a disposable installed shop}"
: "${MP2FA_HISTORICAL_OUTPUT:?Build the pinned historical package first}"
[[ "${MP2FA_INTEGRATION:-}" == 1 ]] || { echo 'Set MP2FA_INTEGRATION=1'; exit 1; }
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
shop="$(realpath "$MP2FA_PS_ROOT")"
installed="$shop/modules/mpadmin2fa"
[[ ! -L "$installed" && "$(realpath "$installed")" == "$installed" && "$installed" != "$module_root" ]] || {
  echo 'The installed module must be a separate, non-symlink package directory'; exit 1;
}
state() { php "$module_root/tests/Integration/lifecycle_state.php" "$@"; }
module() { php "$shop/bin/console" prestashop:module "$@" mpadmin2fa --no-interaction; }
runtime="$(mktemp -d /tmp/mp2fa-upgrade.XXXXXX)"
chmod 700 "$runtime"
export MP2FA_UPGRADE_SNAPSHOT="$runtime/snapshot.json"
cp -a "$installed" "$runtime/current"
(cd "$runtime/current" && sha256sum --check --quiet SHA256SUMS)
(cd "$MP2FA_HISTORICAL_OUTPUT" && sha256sum --check --quiet mpadmin2fa-0.2.7.zip.sha256)
[[ "$(cat "$MP2FA_HISTORICAL_OUTPUT/SOURCE_COMMIT")" == 96a0a0d15a1247d90825b800b9d8a41936d21383 ]]
restore_package() {
  # Resolved above: only the separate disposable shop's module is replaced.
  rm -rf -- "$installed"
  cp -a "$runtime/current" "$installed"
}
trap restore_package EXIT
state verify-cleanup
rm -rf -- "$installed"
unzip -q "$MP2FA_HISTORICAL_OUTPUT/mpadmin2fa-0.2.7.zip" -d "$shop/modules"
module install
php "$module_root/tests/Integration/historical_upgrade_state.php" snapshot
restore_package
php "$shop/bin/console" cache:clear --env=prod --no-warmup
php "$shop/bin/console" cache:clear --env=dev --no-warmup
php "$module_root/tests/Integration/historical_upgrade_state.php" inject-failure
output="$(module upgrade 2>&1)" || true
if ! grep -q 'injected mp2fa upgrade failure after schema' <<< "$output"; then
  printf '%s\n' "$output"
  echo 'The upgrade failure fixture did not reach schema migration.'
  exit 1
fi
restore_package
php "$module_root/tests/Integration/historical_upgrade_state.php" verify-failed
module upgrade
state verify-install
php "$module_root/tests/Integration/historical_upgrade_state.php" verify
php "$module_root/tests/Integration/historical_upgrade_state.php" repair
php "$module_root/tests/Integration/historical_upgrade_state.php" repeat
php "$module_root/tests/Integration/installation_shape.php" verify
state verify-install
module uninstall
state verify-cleanup
echo 'Historical 0.2.7 package upgrade and cleanup passed.'
