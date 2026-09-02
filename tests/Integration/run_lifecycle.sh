#!/usr/bin/env bash
set -euo pipefail

: "${MP2FA_PS_ROOT:?Point MP2FA_PS_ROOT at a disposable installed shop}"
: "${MP2FA_INTEGRATION:?Set MP2FA_INTEGRATION=1 to enable destructive lifecycle tests}"
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
state() { php "$module_root/tests/Integration/lifecycle_state.php" "$@"; }
module() { php "$MP2FA_PS_ROOT/bin/console" prestashop:module "$@" mpadmin2fa --no-interaction; }

state prepare-profile
module install
state verify-install
state verify-repeated-install
php "$MP2FA_PS_ROOT/bin/console" cache:clear --env=prod --no-debug
php "$MP2FA_PS_ROOT/bin/console" debug:router --env=prod --no-debug | grep mpadmin2fa
php "$MP2FA_PS_ROOT/bin/console" debug:event-dispatcher kernel.request --env=prod --no-debug | grep -F 'AdminMfaSubscriber::onKernelRequest'
php "$MP2FA_PS_ROOT/bin/console" debug:event-dispatcher security.interactive_login --env=prod --no-debug | grep -F 'AdminMfaSubscriber::onLoginSuccess'
commands="$(php "$MP2FA_PS_ROOT/bin/console" list --raw --env=prod --no-debug)"
for command in mpadmin2fa:key:health mpadmin2fa:key:rotate mpadmin2fa:audit:prune mpadmin2fa:factor:reset; do
  grep -F "$command" <<< "$commands"
done
state prepare-upgrade
module upgrade
state verify-install
bash "$module_root/tests/Integration/run_requests.sh"
php "$module_root/vendor/bin/phpunit" --do-not-cache-result -c "$module_root/phpunit.xml.dist" \
  "$module_root/tests/Integration/AtomicRateLimitIntegrationTest.php"
module uninstall
state verify-cleanup

for stage in schema configuration hook tab access; do
  state prepare-failure "$stage"
  # Some PS8 console versions return zero even when the module manager returns false.
  output="$(module install 2>&1)" || true
  printf '%s\n' "$output"
  if ! grep -q "Cannot install module" <<< "$output" || ! grep -q "injected mp2fa $stage failure" <<< "$output"; then
    echo "The $stage fixture did not produce the expected installation failure."
    exit 1
  fi
  state verify-rollback
  # Every failed installation must be immediately recoverable.
  module install
  state verify-install
  module uninstall
  state verify-cleanup
done
