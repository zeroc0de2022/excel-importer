#!/usr/bin/env bash
# Build and roll out the production stack. Run on the server from the project directory.
set -euo pipefail

COMPOSE="docker compose -f compose.prod.yaml"

# 1. Build new images while the old containers keep serving traffic
$COMPOSE build --pull

# 2. Recreate only the containers whose image or config changed.
#    Queue workers get SIGTERM and finish their current job first (stop_grace_period).
$COMPOSE up -d --wait --remove-orphans

# 3. Schema changes. Migrations are written to be backwards compatible (additive first),
#    so the brief window where new code runs before migrate is safe.
$COMPOSE exec -T app php artisan migrate --force

# 4. Tell any long-running worker to reload the code after its current job
$COMPOSE exec -T app php artisan queue:restart

# 5. Remove dangling images from previous builds
docker image prune -f >/dev/null

$COMPOSE ps
