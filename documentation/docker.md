# Use Docker for PrestaShop 8

This branch uses an isolated PrestaShop 8 Docker stack.
The stack has a separate database and separate host ports.

## Prerequisites

Install Docker Engine with the Docker Compose plug-in.
Make sure that the following ports are free:

- Store HTTP: http://localhost:8101/
- Store HTTPS: https://localhost:8102/
- Back office: https://localhost:8102/admin-dev/
- Database: localhost:3316
- Test mail: Not available in this stack

The local administrator account has these values:

- Email: `demo@prestashop.com`
- Password: `Pr3st4Sh0P`

Use this account only in the local test shop.

## Start the stack

1. Open a WSL terminal.
2. Go to the PrestaShop 8 worktree root.
3. Build and start the containers:

```bash
docker compose -f docker-compose.mpadmin2fa.yml up -d --build
```

4. Show the container status:

```bash
docker compose -f docker-compose.mpadmin2fa.yml ps
```

5. Open https://localhost:8102/admin-dev/ in a browser.
6. Accept the local development certificate if the browser asks you.
7. Sign in with the local administrator account.

The application container uses PHP 8.1.
The database container supports PrestaShop 8.2.8.

## Run module tests

1. Keep the stack active.
2. Run the unit tests as the web-server user:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'cd modules/mpadmin2fa && composer test'
```

3. Clear the PrestaShop cache after a service or route change:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'rm -rf var/cache/dev/* var/cache/prod/*'
```

4. Check the module routes:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git php bin/console debug:router mpadmin2fa
```

## Read logs

Show the current application log:

```bash
docker compose -f docker-compose.mpadmin2fa.yml exec --user www-data prestashop-git sh -lc 'tail -n 200 var/logs/dev.log'
```

Show the container log:

```bash
docker compose -f docker-compose.mpadmin2fa.yml logs --tail 200 prestashop-git
```

## Stop the stack

Stop the containers and keep the database:

```bash
docker compose -f docker-compose.mpadmin2fa.yml stop
```

Remove the containers and keep the database volume:

```bash
docker compose -f docker-compose.mpadmin2fa.yml down
```

## Reset the test shop

> [!WARNING]
> The next command deletes the local test database.

1. Confirm that you do not need the local test data.
2. Remove the stack and its volume:

```bash
docker compose -f docker-compose.mpadmin2fa.yml down --volumes
```

3. Start the stack again.
