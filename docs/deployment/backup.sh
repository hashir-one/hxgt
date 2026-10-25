#!/usr/bin/env bash
# Run daily from an operator-owned systemd timer. No secrets in command arguments.
# Configure PG service credentials via PGSERVICE/PGSERVICEFILE and .pgpass (0600).
# RESTIC_REPOSITORY must be an off-server repository; RESTIC_PASSWORD_FILE must be
# separately backed up with APP_KEY. Initialize the repository once as an operator.
set -euo pipefail
umask 077
: "${PGSERVICE:?Set a pg_service.conf service for the gateway database}"
: "${RESTIC_REPOSITORY:?Set an encrypted off-server restic repository}"
: "${RESTIC_PASSWORD_FILE:?Set the protected restic password file}"
case "$RESTIC_REPOSITORY" in
  s3:*|sftp:*|rest:*|b2:*|azure:*|gs:*) ;;
  *) echo 'An off-server repository is required.' >&2; exit 1 ;;
esac
command -v pg_dump >/dev/null
command -v restic >/dev/null
pg_dump --format=custom --no-owner --no-acl | restic backup --stdin --stdin-filename gateway.pgdump --tag hx-pay-database
restic check
# Restore into an isolated PostgreSQL instance and run gateway:check-integrity
# regularly. This script intentionally does not prune historical backups.
