# Deploying Home Pantry with Docker / Portainer

The app ships as a single container: Apache + PHP 8.4, SQLite for the
database, and a queue worker (under supervisord) that downloads product photos.
The database and `storage/` (photos, logs, sessions) live on two named Docker
volumes so they survive restarts and image upgrades.

Every push of a `vX.Y.Z` tag builds and publishes a multi-arch image
(amd64 + arm64) to GitHub Container Registry via
`.github/workflows/docker-publish.yml`.

## 1. Generate an APP_KEY

Laravel requires a stable encryption key. Generate one once and keep it
somewhere safe (a password manager, or Portainer's environment variables).
Reuse the same key on every redeploy.

```bash
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

## 2. Deploy

### Option A: pull the prebuilt image (recommended)

The image is public at `ghcr.io/daezerwin/home-inventory`, so no registry
credentials are needed.

1. In Portainer, go to **Stacks → Add stack** and choose **Web editor**.
2. Paste in the contents of [`docker-compose.prod.yml`](docker-compose.prod.yml).
3. Under **Environment variables**, add:
   - `APP_KEY` = the `base64:...` value from step 1
   - `APP_URL` = e.g. `http://your-server-ip:8100`
4. Click **Deploy the stack**.

To pin a release instead of tracking `latest`, change the `image:` line to
e.g. `ghcr.io/daezerwin/home-inventory:v1.0.0`.

### Option B: build from source

In Portainer choose **Repository** instead, point it at
`https://github.com/daezerwin/home-inventory`, and use
[`docker-compose.yml`](docker-compose.yml) with the same environment variables.
Or over SSH:

```bash
git clone https://github.com/daezerwin/home-inventory.git
cd home-inventory
export APP_KEY="base64:...(from step 1)"
export APP_URL="http://your-server-ip:8100"
docker compose up -d --build
```

## 3. First start

On the very first start the container:

1. creates the SQLite database and runs migrations,
2. imports the bundled `inventory.csv` (about 14,000 products) into the empty
   catalog, and
3. queues a photo download for every product with an image URL.

The worker then downloads the photos in the background. Each is resized to
600px and stored as WebP, so the full catalog takes roughly 300–400 MB in the
storage volume. Progress shows on **Manage inventory**. The catalog works right
away and products show a placeholder until their photo arrives.

The bundled CSV is imported only once. Restarts and upgrades never overwrite
products you've edited or imported since.

## 4. Accounts

**Open the app right after deploying.** The first visitor is asked to create
the administrator account. After that, sign-up is closed. Add other household
members from the container:

```bash
docker exec -it <container-name> php artisan inventory:user someone@example.com
docker exec -it <container-name> php artisan inventory:user someone@example.com --admin
```

## 5. Ports and reverse proxy

Both compose files publish host port **8100** (`8100:80`), so this runs
alongside Baby Tracker (8070) and Task Tracker (8099). Point your reverse proxy
(Nginx Proxy Manager, Traefik, Caddy, …) at `http://<server-ip>:8100`.

## 6. Optional settings

| Variable                   | Default | Meaning                                                          |
|----------------------------|---------|------------------------------------------------------------------|
| `INVENTORY_IMAGE_HOSTS`    | empty   | Comma-separated hosts photos may come from. Empty = any public host. |
| `INVENTORY_IMAGE_MAX_SIZE` | `600`   | Longest side of stored product photos in px. `0` keeps the original files. |
| `INVENTORY_RECIPE_IMAGE_MAX_SIZE` | `1600` | Longest side of stored recipe photos (they also fill the chef page heroes). |
| `APP_TIMEZONE`             | `Asia/Manila` | Timezone for dates shown in the app.                       |

## 7. Backing up your data

Everything that matters lives in two named volumes (Portainer prefixes them
with the stack name):

- `home_inventory_database`: the SQLite database
- `home_inventory_storage`: downloaded photos, logs and sessions

```bash
docker run --rm -v home_inventory_database:/data -v $(pwd):/backup alpine \
    tar czf /backup/home-inventory-database.tar.gz -C /data .
```

Photos can always be downloaded again (**Manage inventory → Retry missing
photos**), so the database volume is the one to back up.

## 8. Updating to a new version

**Pulling (Option A):** in Portainer, open the stack and click **Pull and
redeploy** (or `docker compose pull && docker compose up -d` over SSH).

**Building (Option B):**

```bash
git pull
docker compose up -d --build
```

Migrations run automatically on every start and are safe to repeat.
