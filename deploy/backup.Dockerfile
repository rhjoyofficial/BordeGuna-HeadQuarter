# Backup sidecar: pg_dump from the same major version as the database, plus restic for
# encrypted offsite replication to Backblaze B2.
FROM postgres:17-alpine
RUN apk add --no-cache restic bash curl coreutils
COPY backup.sh /backup.sh
COPY restore.sh /restore.sh
RUN chmod +x /backup.sh /restore.sh
ENTRYPOINT ["/bin/bash", "/backup.sh"]
