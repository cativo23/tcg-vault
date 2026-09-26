# syntax=docker/dockerfile:1

# ---- Stage 1: build front-end assets (Tailwind CSS + the small amount of JS this app ships) ----
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources/ ./resources/
COPY vite.config.js ./
COPY tailwind.config.js ./
COPY postcss.config.js ./
RUN npm run build
# -> /app/public/build

# ---- Stage 2: production vendor (composer, no dev deps) ----
FROM serversideup/php:8.4-cli AS vendor
USER root
WORKDIR /app
COPY . .
# --no-scripts skips post-autoload-dump (package:discover), which needs a
# real APP_KEY/.env that doesn't exist at build time.
RUN composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader --no-scripts

# ---- Stage 3: runtime ----
FROM serversideup/php:8.4-fpm-nginx AS runtime
# serversideup defaults: user www-data, docroot /var/www/html/public, HTTP on :8080

# intl isn't in the base image's default extension set. App\Support\Money
# (used on the public gallery/set/card views to render prices) calls
# Illuminate\Support\Number::currency(), which throws a RuntimeException
# without it — found live in production 2026-09-15 as a 500 on /{username}
# once real priced cards existed to render. install-php-extensions is
# bundled by serversideup images for exactly this.
USER root
RUN install-php-extensions intl
# exiftool strips location and other metadata from uploaded photos without
# re-encoding them (ExiftoolPhotoMetadataStripper). Perl is already in the
# base image; this adds only the exiftool package.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libimage-exiftool-perl \
    && rm -rf /var/lib/apt/lists/*
# Drop back to the base image's own non-root user (www-data, uid 33) —
# installing packages and extensions needs root, but nothing after it
# does. Leaving USER root set here would make the whole runtime stage (and
# therefore the container nginx/php-fpm actually serves requests as) run as root,
# a real regression caught by this repo's automated commit review.
USER www-data

COPY --chown=www-data:www-data . /var/www/html/
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Clear the bootstrap cache so the package manifest regenerates from the
# no-dev vendor at boot — dev-only providers (Pail, Pint-as-a-package,
# Sail) would otherwise crash artisan on first request. This must run
# BEFORE any artisan invocation below: the cache copied in from the local
# dev tree still references dev-only providers, and artisan storage:link
# would crash trying to load them.
RUN rm -f /var/www/html/bootstrap/cache/packages.php /var/www/html/bootstrap/cache/services.php

# The public/storage symlink itself never changes — only the target's
# contents do, and those live on a mounted volume (docker/prod/compose.prod.yml,
# Task 4) — so bake the symlink at build time, not as a runtime AUTORUN step.
RUN php artisan storage:link

# Warm caches at boot (env vars exist then; they don't at build time),
# but NEVER auto-migrate — migrations are always a deliberate manual step.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=false \
    AUTORUN_LARAVEL_CONFIG_CACHE=true \
    AUTORUN_LARAVEL_ROUTE_CACHE=true \
    AUTORUN_LARAVEL_VIEW_CACHE=true \
    AUTORUN_LARAVEL_EVENT_CACHE=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    PHP_OPCACHE_ENABLE=1
