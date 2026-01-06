#!/bin/bash

set -e

echo "=== CREATING STAGING-READY DATABASE BACKUP ==="

# Get timestamp for filename
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR="backups"
BACKUP_FILE="${BACKUP_DIR}/myeventlane-staging-${TIMESTAMP}.sql.gz"

# Create backups directory if it doesn't exist
mkdir -p "${BACKUP_DIR}"

echo "1. Exporting database..."
# Export database using DDEV (uncompressed for processing)
TEMP_EXPORT="${BACKUP_DIR}/temp-export-${TIMESTAMP}.sql"
ddev export-db --file="${TEMP_EXPORT}" --gzip=false

echo "2. Creating working copy for sanitization..."
# Create a working copy
TEMP_WORK="${BACKUP_DIR}/temp-work-${TIMESTAMP}.sql"
cp "${TEMP_EXPORT}" "${TEMP_WORK}"

echo "3. Applying basic sanitization to SQL file..."
# Basic sanitization: anonymize emails and passwords in SQL dump
# This is safer than modifying the live database
sed -i.bak \
  -e "s/\(mail\) = '[^']*@[^']*'/\\1 = 'user' || uid || '@example.com'/g" \
  -e "s/\(pass\) = '[^']*'/\\1 = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC\/.og\/at2.uheWG\/igi'/g" \
  "${TEMP_WORK}" 2>/dev/null || true

# Note: For full sanitization, you may want to import to a temp DB and use drush sql-sanitize
# But that requires more setup. This basic approach is safer for the live DB.

echo "4. Updating URLs in backup for staging..."
# Update URLs in the working SQL file

# Get current site URL from DDEV
CURRENT_URL=$(ddev describe | grep "https://" | head -1 | awk '{print $2}' || echo "https://myeventlane.ddev.site")
# Default staging URL (user should update this)
STAGING_URL="${STAGING_URL:-https://staging.myeventlane.com}"

echo "   Current URL: ${CURRENT_URL}"
echo "   Staging URL: ${STAGING_URL}"
echo "   (Set STAGING_URL environment variable to customize)"

# Update URLs in SQL dump
sed -i.bak \
  -e "s|${CURRENT_URL}|${STAGING_URL}|g" \
  -e "s|http://myeventlane.ddev.site|${STAGING_URL}|g" \
  -e "s|https://myeventlane.ddev.site|${STAGING_URL}|g" \
  -e "s|http://admin.myeventlane.ddev.site|${STAGING_URL}/admin|g" \
  -e "s|https://admin.myeventlane.ddev.site|${STAGING_URL}/admin|g" \
  -e "s|http://vendor.myeventlane.ddev.site|${STAGING_URL}/vendor|g" \
  -e "s|https://vendor.myeventlane.ddev.site|${STAGING_URL}/vendor|g" \
  "${TEMP_WORK}"

echo "5. Compressing final backup..."
# Compress the final backup
gzip -c "${TEMP_WORK}" > "${BACKUP_FILE}"

# Cleanup temporary files
rm -f "${TEMP_EXPORT}" "${TEMP_WORK}" "${TEMP_WORK}.bak"

echo ""
echo "=== BACKUP COMPLETE ==="
echo "File: ${BACKUP_FILE}"
echo "Size: $(du -h "${BACKUP_FILE}" | cut -f1)"
echo ""
echo "To import on staging:"
echo "  ddev import-db --file=${BACKUP_FILE}"
echo ""
echo "Or on remote staging server:"
echo "  gunzip < ${BACKUP_FILE} | drush sql-cli"
echo ""
