#!/bin/bash
# RMS Database Backup Script
# Backs up the database to a timestamped file

# Configuration
DB_NAME="rms_db"
DB_USER="root"
DB_PASS=""
BACKUP_DIR="backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/rms_db_backup_${TIMESTAMP}.sql"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Export database
echo "Backing up database: $DB_NAME"
mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"

# Check if backup was successful
if [ $? -eq 0 ]; then
    echo "✅ Backup successful: $BACKUP_FILE"
    echo "File size: $(du -h "$BACKUP_FILE" | cut -f1)"

    # Optional: Compress the backup
    gzip "$BACKUP_FILE"
    echo "✅ Compressed: ${BACKUP_FILE}.gz"
else
    echo "❌ Backup failed!"
    exit 1
fi
