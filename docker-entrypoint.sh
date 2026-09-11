#!/bin/bash
set -e

# Wait for MySQL to be ready, then import the database schema if empty
if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    echo "Waiting for MySQL at $DB_HOST..."
    for i in $(seq 1 30); do
        if mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" --silent 2>/dev/null; then
            echo "MySQL is up."
            break
        fi
        echo "  ...attempt $i/30"
        sleep 2
    done

    # Import schema if the elections table doesn't exist
    TABLE_EXISTS=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "SHOW TABLES FROM \`$DB_NAME\` LIKE 'elections'" 2>/dev/null | wc -l)
    if [ "$TABLE_EXISTS" -le 1 ]; then
        echo "Importing database schema..."
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < /var/www/html/database/election.sql 2>/dev/null || true
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < /var/www/html/database/migration_otp.sql 2>/dev/null || true
        echo "Schema imported."
    else
        echo "Schema already present, skipping import."
    fi
fi

exec "$@"
