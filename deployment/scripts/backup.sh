#!/usr/bin/env bash
# ==============================================================================
# IQRA VIRTUAL SCHOOL — PRODUCTION ENCRYPTED DATABASE BACKUP SCRIPT
# ==============================================================================
# Schedule via root crontab:
#   0 2 * * * /var/www/iqra-billing/deployment/scripts/backup.sh >> /var/log/iqra-backup.log 2>&1
# ==============================================================================

set -euo pipefail

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/var/backups/iqra_billing"
BACKUP_FILE="${BACKUP_DIR}/iqra_billing_${TIMESTAMP}.sql.gz"
ENCRYPTED_FILE="${BACKUP_FILE}.enc"

# Read database credentials from secure .env without printing them
ENV_FILE="/var/www/iqra-billing/.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "[ERROR] .env file not found at ${ENV_FILE}" >&2
    exit 1
fi

DB_USER=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_PASS=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_NAME=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")

# Encryption passphrase file (must be created with 0400 permissions outside web root)
PASSPHRASE_FILE="/etc/iqra/backup_encryption.key"

mkdir -p "$BACKUP_DIR"
chmod 0700 "$BACKUP_DIR"

echo "[$(date)] Starting backup of ${DB_NAME}..."

# 1. Execute single-transaction consistent mysqldump compressed with gzip
MYSQL_PWD="${DB_PASS}" mysqldump \
    --host="${DB_HOST:-127.0.0.1}" \
    --port="${DB_PORT:-3306}" \
    --user="${DB_USER}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"

echo "[$(date)] Backup completed: ${BACKUP_FILE} ($(du -h "${BACKUP_FILE}" | cut -f1))"

# 2. Encrypt using AES-256-CBC if encryption key exists
if [[ -f "$PASSPHRASE_FILE" ]]; then
    openssl enc -aes-256-cbc -salt -pbkdf2 -iter 100000 \
        -in "${BACKUP_FILE}" \
        -out "${ENCRYPTED_FILE}" \
        -pass file:"${PASSPHRASE_FILE}"
    rm -f "${BACKUP_FILE}"
    chmod 0600 "${ENCRYPTED_FILE}"
    echo "[$(date)] Encrypted backup created: ${ENCRYPTED_FILE}"
    FINAL_FILE="${ENCRYPTED_FILE}"
else
    chmod 0600 "${BACKUP_FILE}"
    FINAL_FILE="${BACKUP_FILE}"
fi

# 3. Generate SHA-256 checksum for audit and integrity verification
sha256sum "${FINAL_FILE}" > "${FINAL_FILE}.sha256"
echo "[$(date)] SHA-256 Checksum: $(cat "${FINAL_FILE}.sha256")"

# 4. Prune local backups older than 30 days
find "$BACKUP_DIR" -type f -name "iqra_billing_*" -mtime +30 -delete
echo "[$(date)] Pruning completed. Retained last 30 days."
