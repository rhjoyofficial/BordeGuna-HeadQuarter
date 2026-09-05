# Backup sidecar: pg_dump/pg_restore from the same major version as the database.
# Local dumps only (docs/28). No offsite tooling is installed; OFFSITE_HOOK in backup.sh is the extension point.
FROM postgres:17-alpine
RUN apk add --no-cache bash curl coreutils
COPY backup.sh /backup.sh
COPY restore.sh /restore.sh
RUN chmod +x /backup.sh /restore.sh
ENTRYPOINT ["/bin/bash", "/backup.sh"]
