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
[[ "$(cat "$MP2FA_HISTORICAL_OUTPUT/SOURCE_COMMIT")" == 9334de7296f98d4248af4b7b541038ad34da22cc ]]
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
module upgrade
state verify-install
php "$module_root/tests/Integration/historical_upgrade_state.php" verify
php "$module_root/tests/Integration/historical_upgrade_state.php" repair
php "$module_root/tests/Integration/historical_upgrade_state.php" repeat
state verify-install
module uninstall
state verify-cleanup
echo 'Historical 0.2.7 package upgrade and cleanup passed.'
