#!/usr/bin/env bash
set -euo pipefail

module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
expected="\$this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.2.99'];"

while read -r version revision; do
  source="$(git -C "$module_root" show "${revision}:mpadmin2fa.php")"
  grep -Fqx "        $expected" <<< "$source"
  printf 'Historical %s source %s is scoped to PrestaShop 8.0.0-8.2.99.\n' "$version" "$revision"
done <<'PINS'
0.2.7 9334de7296f98d4248af4b7b541038ad34da22cc
0.2.8 bdd0ff970b327f8fea2c07eef6e5573e8fc33bf9
PINS

echo 'Genuine native historical upgrades are applicable to the PS8 release line only.'
