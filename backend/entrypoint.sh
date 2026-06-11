#!/bin/bash
set -e

# Copy .env.example to .env if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Wait for MySQL database to be ready
echo "Waiting for MySQL database at $DB_HOST:$DB_PORT..."
php -r "
\$host = getenv('DB_HOST') ?: '127.0.0.1';
\$port = getenv('DB_PORT') ?: '3306';
\$user = getenv('DB_USERNAME') ?: 'root';
\$pass = getenv('DB_PASSWORD') ?: '';
for (\$i = 0; \$i < 30; \$i++) {
    try {
        new PDO(\"mysql:host=\$host;port=\$port\", \$user, \$pass);
        exit(0);
    } catch (PDOException \$e) {
        sleep(2);
    }
}
exit(1);
"

# Check database initialization status
if php database/check_db.php; then
    echo "Database is ready."
else
    echo "Initializing database..."
    php database/run_schema.php
fi

# Run the CMD (starts apache2-foreground)
exec "$@"
