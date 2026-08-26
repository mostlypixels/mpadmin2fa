# Local compatibility matrix

The three supported PrestaShop lines run as isolated Docker Compose projects.
They use separate application ports, database ports, networks, and volumes, so
they can remain online at the same time.

| Line | PrestaShop baseline | Module branch | Store | Back office | MySQL |
| --- | --- | --- | --- | --- | --- |
| 3.x | 9.x development tree | `main` | https://localhost:9002/ | https://localhost:9002/admin-dev/ | localhost:3396 |
| 2.x | 8.2.8 | `2.x-ps8` | https://localhost:8102/ | https://localhost:8102/admin-dev/ | localhost:3316 |
| 1.x | 1.7.8.11 | `1.x-ps17` | https://localhost:8202/ | https://localhost:8202/admin-dev/ | localhost:3326 |

The 9.x stack also exposes HTTP on port 9001 and MailDev on
http://localhost:9080/. The compatibility stacks expose HTTP on ports 8101 and
8201. The local HTTPS endpoints use the development certificate installed for
`localhost`.

## Worktrees

- PrestaShop 9: `/home/user/projects/prestashop`
- PrestaShop 8: `/home/user/projects/prestashop-worktrees/ps8`
- PrestaShop 1.7: `/home/user/projects/prestashop-worktrees/ps17`

The module directories inside the 8.x and 1.7 worktrees are module-repository
worktrees. Uncommitted 3.x work was copied into them as a starting point without
committing or altering the original module branch.

## Starting and stopping

Run these commands from WSL:

```sh
docker compose -f /home/user/projects/prestashop-worktrees/ps8/docker-compose.mpadmin2fa.yml up -d --build
docker compose -f /home/user/projects/prestashop-worktrees/ps17/docker-compose.mpadmin2fa.yml up -d --build
```

Stop a compatibility stack without deleting its database:

```sh
docker compose -f /home/user/projects/prestashop-worktrees/ps8/docker-compose.mpadmin2fa.yml stop
docker compose -f /home/user/projects/prestashop-worktrees/ps17/docker-compose.mpadmin2fa.yml stop
```

Use `down` instead of `stop` to remove containers and networks. Do not add
`--volumes` unless the corresponding test database should be erased.

The generated demo administrator credentials are:

- Email: `demo@prestashop.com`
- Password: `Pr3st4Sh0P`

## Current compatibility baseline

- PrestaShop 8 (`2.x-ps8`):
  - Declared support is PrestaShop 8.0.0 through 8.2.x, with PHP 8.1 or newer.
  - CI exercises PrestaShop 8.0.5 / PHP 8.1, PrestaShop 8.2.8 /
    PHP 8.1, and PrestaShop 8.2.8 / PHP 8.4.
  - Symfony 4.4 adapters use legacy admin-controller annotations, token storage,
    the password encoder, and the interactive-login event.
  - PHPUnit 10 passes 72 tests / 319 assertions against the production
    dependency sets from both PrestaShop 8.0.5 and 8.2.8.
  - On 8.2.8, cache compilation, all 18 module routes, and all four maintenance
    commands succeed.
- PrestaShop 1.7 (`1.x-ps17`):
  - Declared support is PrestaShop 1.7.8.0 through 1.7.8.x, with a PHP 7.2.5
    runtime floor.
  - CI exercises PrestaShop 1.7.8.0 / PHP 7.2, PrestaShop 1.7.8.11 /
    PHP 7.2, and PrestaShop 1.7.8.11 / PHP 7.4.
  - Symfony 3.4 and DBAL 2 adapters use explicit legacy services, command tags,
    form options, grid namespaces, and result methods.
  - PHPUnit 8 passes 72 tests / 321 assertions against the production
    dependency sets from both PrestaShop 1.7.8.0 and 1.7.8.11.
  - On 1.7.8.11, cache compilation, all 18 module routes, and all four
    maintenance commands succeed.
  - The scoped release package passes a full PHP 7.2 syntax scan and a live
    TOTP-generation smoke test. Release tooling runs separately on PHP 8.1-8.4
    and removes PHP 8-only SensitiveParameter metadata from scoped dependencies
    without changing their cryptographic behavior.
