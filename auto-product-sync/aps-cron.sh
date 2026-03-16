#!/bin/bash
# APS Cron Wrapper Script - Fixed paths

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
WP_ROOT="$SCRIPT_DIR/../../../.."
WP_CONFIG="$WP_ROOT/wp-config.php"

echo "APS Cron started at $(date)"
echo "Script directory: $SCRIPT_DIR"
echo "Looking for wp-config.php at: $WP_CONFIG"

# Check if wp-config.php exists
if [ ! -f "$WP_CONFIG" ]; then
    echo "Error: Cannot find wp-config.php at $WP_CONFIG"
    exit 1
fi

# Extract database credentials using sed instead of cut
MYSQL_HOST=$(grep "define.*DB_HOST" "$WP_CONFIG" | sed "s/.*['\"]DB_HOST['\"]\s*,\s*['\"]//;s/['\"].*//" | head -1)
MYSQL_USER=$(grep "define.*DB_USER" "$WP_CONFIG" | sed "s/.*['\"]DB_USER['\"]\s*,\s*['\"]//;s/['\"].*//" | head -1)
MYSQL_PASS=$(grep "define.*DB_PASSWORD" "$WP_CONFIG" | sed "s/.*['\"]DB_PASSWORD['\"]\s*,\s*['\"]//;s/['\"].*//" | head -1)
MYSQL_DB=$(grep "define.*DB_NAME" "$WP_CONFIG" | sed "s/.*['\"]DB_NAME['\"]\s*,\s*['\"]//;s/['\"].*//" | head -1)
TABLE_PREFIX=$(grep '\$table_prefix' "$WP_CONFIG" | sed "s/.*['\"]//;s/['\"].*//" | head -1)

echo "Database: $MYSQL_DB"
echo "Host: $MYSQL_HOST"
echo "Table prefix: $TABLE_PREFIX"

# Verify we got the credentials
if [ -z "$MYSQL_HOST" ] || [ -z "$MYSQL_USER" ] || [ -z "$MYSQL_DB" ]; then
    echo "Error: Could not extract database credentials"
    exit 1
fi

# Get site URL from database
SITE_URL=$(mysql -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -sN -e "SELECT option_value FROM ${TABLE_PREFIX}options WHERE option_name='siteurl' LIMIT 1" 2>&1)

if [ $? -ne 0 ]; then
    echo "Error querying database for site URL"
    echo "$SITE_URL"
    exit 1
fi

# Get cron key from database
CRON_KEY=$(mysql -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASS" "$MYSQL_DB" -sN -e "SELECT option_value FROM ${TABLE_PREFIX}options WHERE option_name='aps_cron_secret_key' LIMIT 1" 2>&1)

if [ $? -ne 0 ]; then
    echo "Error querying database for cron key"
    echo "$CRON_KEY"
    exit 1
fi

if [ -z "$SITE_URL" ] || [ -z "$CRON_KEY" ]; then
    echo "Error: Missing site URL or cron key"
    echo "Site URL: $SITE_URL"
    echo "Cron Key: [hidden]"
    exit 1
fi

# Build URL
CRON_URL="${SITE_URL}/?aps_cron=1&key=${CRON_KEY}"

echo "Calling cron endpoint..."

# Try curl first
if command -v curl >/dev/null 2>&1; then
    RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" -m 300 "$CRON_URL")
    HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | sed 's/HTTP_CODE://')
    BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")
    
    echo "HTTP Status: $HTTP_CODE"
    echo "Response:"
    echo "$BODY"
    echo ""
    echo "Completed at $(date)"
    
    if [ "$HTTP_CODE" = "200" ]; then
        exit 0
    else
        exit 1
    fi
fi

# Try wget if curl not available
if command -v wget >/dev/null 2>&1; then
    RESPONSE=$(wget -q -O - "$CRON_URL" 2>&1)
    echo "Response:"
    echo "$RESPONSE"
    echo ""
    echo "Completed at $(date)"
    exit 0
fi

echo "Error: Neither curl nor wget available"
exit 1
