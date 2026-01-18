#!/bin/sh
set -e

# Wait for database to be ready
echo "Waiting for database connection..."
MAX_RETRIES=30
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    if php artisan db:monitor --databases=default > /dev/null 2>&1; then
        echo "Database is ready!"
        break
    fi

    RETRY_COUNT=$((RETRY_COUNT + 1))
    echo "Database not ready, attempt $RETRY_COUNT/$MAX_RETRIES..."
    sleep 2
done

if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
    echo "Warning: Could not verify database connection, proceeding anyway..."
fi

# Run migrations if AUTO_MIGRATE is enabled
if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction
    echo "Migrations complete!"
fi

# Clear and cache config for production
if [ "${APP_ENV}" = "production" ]; then
    echo "Optimizing for production..."
    php artisan config:cache
    php artisan route:cache
fi

# Execute the main command
exec "$@"
