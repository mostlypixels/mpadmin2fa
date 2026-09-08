#!/usr/bin/env bash
set -euo pipefail
: "${MP2FA_PS_ROOT:?Point MP2FA_PS_ROOT at a disposable installed shop}"
[[ "${MP2FA_INTEGRATION:-}" == 1 ]] || { echo 'Set MP2FA_INTEGRATION=1'; exit 1; }
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
installed="$(realpath "$MP2FA_PS_ROOT/modules/mpadmin2fa")"
[[ "$installed" != "$module_root" && ! -L "$MP2FA_PS_ROOT/modules/mpadmin2fa" ]] || exit 1
(cd "$installed" && sha256sum --check --quiet SHA256SUMS)
for forbidden in .git .ai .codex tests tools prestashop .phpunit.result.cache; do
  [[ ! -e "$installed/$forbidden" ]] || { echo "Forbidden package content: $forbidden"; exit 1; }
done
export MP2FA_LIFECYCLE_RUNTIME
MP2FA_LIFECYCLE_RUNTIME="$(mktemp -d /tmp/mp2fa-lifecycle.XXXXXX)"
chmod 700 "$MP2FA_LIFECYCLE_RUNTIME"
export MP2FA_CLEAN_SHAPE="$MP2FA_LIFECYCLE_RUNTIME/clean-shape.json"
state() { php "$module_root/tests/Integration/lifecycle_state.php" "$@"; }
inject() { php "$module_root/tests/Integration/failure_injection.php" "$@"; }
module() { php "$MP2FA_PS_ROOT/bin/console" prestashop:module "$@" mpadmin2fa --no-interaction; }
trap 'inject restore' EXIT

state prepare-profile
module install
state verify-install
php "$module_root/tests/Integration/installation_shape.php" snapshot
state verify-repeated-install
state verify-reconciliation
state verify-install
php "$MP2FA_PS_ROOT/bin/console" cache:clear --env=prod --no-debug
php "$MP2FA_PS_ROOT/bin/console" debug:router --env=prod --no-debug | grep mpadmin2fa
php "$MP2FA_PS_ROOT/bin/console" debug:event-dispatcher kernel.request --env=prod --no-debug | grep -F 'AdminMfaSubscriber::onKernelRequest'
php "$MP2FA_PS_ROOT/bin/console" debug:event-dispatcher security.interactive_login --env=prod --no-debug | grep -F 'AdminMfaSubscriber::onLoginSuccess'
commands="$(php "$MP2FA_PS_ROOT/bin/console" list --raw --env=prod --no-debug)"
for command in mpadmin2fa:key:health mpadmin2fa:key:rotate mpadmin2fa:audit:prune mpadmin2fa:factor:reset; do
  grep -F "$command" <<< "$commands"
done
bash "$module_root/tests/Integration/run_requests.sh"
for iteration in $(seq 1 10); do
  echo "Database integration repetition $iteration / 10"
  php "$module_root/vendor/bin/phpunit" -c "$module_root/tests/Integration/phpunit.xml"
done
php "$module_root/tests/Integration/alert_checks.php"
state prepare-partial-uninstall
module uninstall
state verify-cleanup

for stage in before-parent after-schema after-configuration after-hooks after-tabs; do
  inject prepare "$stage"
  output="$(module install 2>&1)" || true
  if ! grep -q "injected mp2fa $stage failure" <<< "$output"; then
    printf '%s\n' "$output"
    echo "The $stage fixture did not reach the expected installation stage."
    exit 1
  fi
  inject verify
  state verify-cleanup
  module install
  state verify-install
  module uninstall
  state verify-cleanup
  echo "PASS: $stage rollback and immediate reinstall"
done
(cd "$installed" && sha256sum --check --quiet SHA256SUMS)
