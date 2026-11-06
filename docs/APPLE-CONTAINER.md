# Apple Container installation

Open Pastebin NG ships one application image with Nginx, PHP-FPM and
supervisord, plus a separate official MariaDB container. There is no Compose
equivalent: `deploy/apple-container/start.sh` creates the network, volumes and
both containers, and waits for the healthcheck.

## Prerequisites

- Apple silicon Mac (M1/M2/M3/M4).
- macOS 26 or newer for the Apple Container networking commands.
- Apple `container` CLI installed and its services running:

  ```bash
  container system start
  container system status
  ```

- 4 GB of RAM and 10 GB of free disk as a minimum; more for large databases.
- A TLS reverse proxy (Caddy/Nginx) for public use. The default publish is
  `127.0.0.1:8080`, not the LAN or the internet.

## 1. Configure secrets

```bash
cd /path/to/opng
cp deploy/apple-container/opng.env.example deploy/apple-container/opng.env
chmod 600 deploy/apple-container/opng.env
```

Edit `deploy/apple-container/opng.env`:

- Set a unique `OPNG_DB_PASS` and `OPNG_DB_ROOT_PASSWORD` (at least 12 bytes,
  preferably generated with `openssl rand -base64 32`).
- Keep `OPNG_DB_HOST=opng-db`.
- Set the first administrator values. The entrypoint creates the account only
  when all three are present and no administrator exists.
- Leave `OPNG_FORCE_HTTPS` empty while testing on `http://127.0.0.1:8080`.
  Set it to `1` only when a TLS proxy terminates HTTPS in front of the app;
  otherwise the session cookie is marked `Secure` and login fails.
- Set `OPNG_PUBLIC_URL` to the URL users actually open.
- `OPNG_AUTO_MIGRATE=1` runs the idempotent installer on start: it creates the\n  baseline schema on a fresh volume and applies pending migrations on later\n  boots. Set a separate `OPNG_INSTALL_DB_*` account if you do not want the\n  application account to keep DDL privileges.

The file contains secrets. It is excluded from the image context
(`.containerignore`/`.dockerignore`) and must never be committed. Remove
`OPNG_ADMIN_PASSWORD` after the first successful start.

## 2. Build and start

```bash
./deploy/apple-container/start.sh --build
```

The script:

1. Creates the private `opng-net` network.
2. Creates the `opng-db-data` and `opng-backups` volumes (20 GB each).
3. Starts `mariadb:11` with the database/user from the environment file. Port
   3306 is **not** published to the host.
4. Builds `opng-app:1.0.0` from `deploy/apple-container/Containerfile` when it
   is missing or `--build` is given.
5. Starts the app with `--read-only` and tmpfs for `/tmp`, `/run`,
   `/var/lib/nginx` and `/var/lib/php/sessions`, publishing only
   `127.0.0.1:8080`.
6. Waits up to 60 seconds for `/healthz.php`.

Open <http://127.0.0.1:8080>, sign in with the configured administrator, create
a paste and reply to it.

Useful options:

```bash
./deploy/apple-container/start.sh --env /secure/opng.env --image opng-app:1.0.1
```

## 3. Verify

```bash
container exec opng-app /usr/local/bin/healthcheck.sh
curl -fsS http://127.0.0.1:8080/healthz.php
container logs opng-app
container logs opng-db
```

The health endpoint returns `200 {"status":"ok","schema":"0004_entry_threads"}`
when the database, schema and storage headroom are usable. It returns `503`
without leaking credentials or internals.

On plain `http://127.0.0.1:8080`, `php tools/doctor.php` intentionally reports
the HTTPS check as a failure because TLS is not terminated yet. Re-run it with
the real HTTPS `OPNG_PUBLIC_URL` once the reverse proxy is in place; the other
checks (database, schema, password, error display) must still pass.

## 4. First administrator

The environment file drives provisioning on first boot:

```dotenv
OPNG_ADMIN_USERNAME=admin
OPNG_ADMIN_EMAIL=admin@example.com
OPNG_ADMIN_PASSWORD=replace-with-a-long-unique-password
```

After the first successful start, remove `OPNG_ADMIN_PASSWORD` from the file and
recreate the app container when convenient:

```bash
./deploy/apple-container/stop.sh --keep-db --remove
./deploy/apple-container/start.sh
```

To create an additional administrator interactively:

```bash
container exec -it opng-app php tools/create_admin.php
```

`tools/create_admin.php` refuses to add another administrator unless `--force`
is explicit. `--if-empty` is idempotent and used by the entrypoint.

## 5. Backups and restores

```bash
./deploy/apple-container/backup.sh
./deploy/apple-container/backup.sh --name before-upgrade.sql
```

Backups are written to the `opng-backups` volume at `/backups`, with a
`.sha256` sidecar. Copy them off the host regularly:

```bash
container cp opng-app:/backups/before-upgrade.sql .
container cp opng-app:/backups/before-upgrade.sql.sha256 .
```

Restore a local file (it is copied into `/backups` first):

```bash
./deploy/apple-container/restore.sh ./before-upgrade.sql
```

The restore tool verifies the checksum, rejects dumps containing
`CREATE DATABASE`/`USE` unless explicitly allowed, and asks you to type
`RESTORE`. `--force` is for automation only. Test restores in a disposable
environment first. See [BACKUP-RESTORE.md](BACKUP-RESTORE.md).

Suggested retention: daily backups for seven days, weekly for four weeks and
monthly for three months, with at least one off-host copy. Schedule
`backup.sh` with `launchd` or the host cron/CI you already operate; Apple
Container has no built-in scheduler.

## 6. Upgrade and rollback

```bash
# 1. Back up first.
./deploy/apple-container/backup.sh --name before-upgrade.sql

# 2. Pull or build the new image.
./deploy/apple-container/start.sh --build --image opng-app:1.0.1

# 3. The entrypoint applies pending migrations (OPNG_AUTO_MIGRATE=1) and the
#    healthcheck must pass.
container exec opng-app /usr/local/bin/healthcheck.sh
```

Because the app container keeps the same name, recreate it to change the image:

```bash
./deploy/apple-container/stop.sh --keep-db --remove
OPNG_IMAGE=opng-app:1.0.1 ./deploy/apple-container/start.sh
```

The database lives in `opng-db-data` and survives app container replacement.
Never delete that volume without a verified backup.

Rollback:

1. Stop and remove the app container.
2. Start the previous image tag.
3. If the migration was incompatible, restore the pre-upgrade dump with
   `restore.sh`.
4. Run the healthcheck and smoke test.

## 7. Security

- Default publish is loopback only. For public access put Caddy/Nginx with TLS
  in front and set `OPNG_FORCE_HTTPS=1` plus `OPNG_PUBLIC_URL`.
- Keep the image root filesystem read-only and the tmpfs mounts in place.
- `MARIADB_ROOT_PASSWORD` is only used to initialize the database; the app
  container never receives it.
- Pin images by digest for reproducible deployments once the release digest is
  published. Scan the image (Trivy/Grype) and keep the SBOM attached to the
  release.
- Do not run `--cap-add ALL`. If you add capability hardening, validate that
  Nginx can still bind port 8080 and drop to the `www-data` worker.
- Logs go to stdout/stderr. They do not include passwords, but treat backup and
  audit output as sensitive.
- See [SECURITY.md](SECURITY.md) for the full checklist.

## 8. Published image and portable archive

CI builds `linux/arm64` with Buildx and publishes to GHCR on tags:

```bash
container image pull ghcr.io/OWNER/opng:1.0.0
```

Create a `container image save` archive on an Apple silicon Mac:

```bash
container image save ghcr.io/OWNER/opng:1.0.0 opng-app-1.0.0-arm64.tar
sha256sum opng-app-1.0.0-arm64.tar > opng-app-1.0.0-arm64.tar.sha256
container image load < opng-app-1.0.0-arm64.tar
```

The release notes attach the classic artifacts and checksums. The container
workflow produces BuildKit SBOM/provenance attestations; the local
`container image save` archive is an additional convenience.

## 9. Troubleshooting

| Symptom | Check |
| --- | --- |
| `Apple Container services are not running` | `container system start` |
| Build plugin unavailable | The system plugin is loaded only after `container system start` |
| App exits at boot with a tmpfs error | Keep the four `--tmpfs` flags; the rootfs is read-only |
| `MariaDB did not become ready` | `container logs opng-db`; verify the password in `opng.env` matches the initialized volume |
| Healthcheck 503 | `container logs opng-app`; usually the DB, schema version or storage quota |
| Login loses the session | Remove `OPNG_FORCE_HTTPS` when serving plain loopback HTTP |
| `container volume delete opng-db-data` warning | Never run it without a tested backup |
| Restore asks for a password or client | The image includes `mariadb-client`; use the provided `restore.sh` |

## Acceptance checklist

- `container build` produces an arm64 image that starts in Apple Container.
- `http://127.0.0.1:8080` returns the login page; `/healthz.php` returns 200.
- Login and paste creation work.
- MariaDB data persists after stopping/starting the app and database
  containers.
- `backup.sh` produces a non-empty dump and `.sha256`; `restore.sh` restores it
  into a disposable database first.
- `--read-only` works with the documented tmpfs mounts and logs are clear.
- The image contains no `src/config.php`, no `opng.env` and no secrets.
- Upgrade from 0.9 to 1.0 applies migrations and rollback is documented.
