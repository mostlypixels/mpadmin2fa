# Docker quick start for PrestaShop 1.7.8

Use this stack to run a disposable local shop for module development.

## Before you start

You need **Docker Engine** and the **Docker Compose plug-in**.

| Service | Address |
| --- | --- |
| **Shop** | http://localhost:8201/ |
| **Secure shop** | https://localhost:8202/ |
| **Back office** | https://localhost:8202/admin-dev/ |
| **Database** | `localhost:3326` |
| **Test mail** | Not available |

Local administrator: `demo@prestashop.com` / `Pr3st4Sh0P`

**Use this account only in the local test shop.**

## Start the shop

1. Open a WSL terminal in the PrestaShop 1.7 worktree root.
2. Start the containers:

```bash
docker compose -f docker-compose.mpadmin2fa.yml up -d --build
```

3. Check their status:

```bash
docker compose -f docker-compose.mpadmin2fa.yml ps
```

4. Open https://localhost:8202/admin-dev/ and sign in.

**Expected result:** the application and database containers show as running. Your browser might ask you to accept the local certificate.

## Common tasks

Run module tests:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'cd modules/mpadmin2fa && composer test'
```

Clear the cache after changing a route or service:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'rm -rf var/cache/dev/* var/cache/prod/*'
```

List module routes:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git php bin/console debug:router mpadmin2fa
```

Show recent application errors:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'tail -n 200 var/logs/dev.log'
```

Show recent container messages:

```bash
docker compose -f docker-compose.mpadmin2fa.yml logs --tail 200 prestashop-git
```

## Stop or remove the shop

| Goal | Command | Database kept? |
| --- | --- | --- |
| **Stop for later** | `docker compose -f docker-compose.mpadmin2fa.yml stop` | Yes |
| **Remove containers** | `docker compose -f docker-compose.mpadmin2fa.yml down` | Yes |
| **Start again** | `docker compose -f docker-compose.mpadmin2fa.yml up -d` | Yes |

## Delete all local test data

> [!WARNING]
> This command permanently deletes the local test database.

```bash
docker compose -f docker-compose.mpadmin2fa.yml down --volumes
```

Run the start command again to create a clean shop.
