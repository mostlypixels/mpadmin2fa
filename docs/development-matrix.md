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

- PrestaShop 8.2.8 / PHP 8.1:
  - The 2.x branch declares support for PrestaShop 8.x and installs successfully.
  - Symfony 4.4 adapters use the legacy admin controller, employee security user,
    token storage, password encoder, interactive-login event, and command names.
  - Cache compilation, module routes, controller registration, and all four
    maintenance commands succeed.
  - PHPUnit 10 passes 13 tests / 60 assertions.
- PrestaShop 1.7.8.11 / PHP 7.4 with a PHP 7.2.5 Composer platform:
  - The 1.x branch declares support for PrestaShop 1.7.8 and installs successfully.
  - The production lock resolves Google2FA 8.x, BaconQrCode 2.x, Defuse 2.4, and
    PHPUnit 8 while remaining installable for PHP 7.2.5.
  - PHP 8-only runtime syntax was backported, and Symfony 3.4 / legacy DBAL
    adapters use explicit service definitions and tags.
  - Cache compilation, module routes, all four maintenance commands, and
    encryption-key health succeed.
  - PHPUnit 8 passes 13 tests / 60 assertions.
  - PHP-Scoper is isolated in `tools/composer.json` and requires PHP 8.1-8.4;
    run `composer build:tools` before `composer build:scoped`.
