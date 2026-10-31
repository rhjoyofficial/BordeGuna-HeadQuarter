# 144. Photos, videos and files in chat: decision record (Q3, 31 October 2026)

## Question

How do photos, videos and files get into Ask HQ safely, per business, without HQ reading or acting on them?

## Default chosen

- **Upload** `POST /ask/media` (flag `connectors.media_upload`, permission `hq.ask.use`, 30 a minute). 404 while the flag is off. Files are stored as
  `MediaAsset` with untrusted provenance (third party) behind a `ChatAttachment` row that carries the business, the uploader, a name and an expiry.
- **The bytes decide** (`AttachmentGuard`): an allowlist read from the file's leading bytes, never the name or the browser's claimed type. Accepted:
  JPEG, PNG, WebP (10 MB), MP4, WebM, MOV (60 MB), PDF (15 MB), plain text and CSV (2 MB), and audio for the voice screen (10 MB). Refused: executables,
  scripts, PHP, HTML and SVG (they can run in a browser), archives, and any PDF with active content (`/JavaScript`, `/JS`, `/Launch`,
  `/EmbeddedFile`, `/RichMedia`, `/XFA`). Limits are configuration (`hq.media.limits`).
- **Virus scanning** is a second layer behind an interface (`AttachmentScanner`): ClamAV over `clamd` when `hq.media.scanner=clamd` (integrate, do not
  hand-roll; docs/96), otherwise "not scanned", which is recorded and never called clean; an unreachable scanner also answers "not scanned". An infected
  file is refused. `hq.media.scan_required=true` refuses unscanned files too. Default false because no scanner is installed yet: Joy's call once ClamAV
  is on the VPS (recommended).
- **Per-business scope, fail closed**: a person limited to some businesses must name one of them and may name no other; an unrestricted person may leave
  it unset (an HQ-level file). A file is opened only by its uploader and by people who may see its business; a no-business file only by its uploader.
  Served as a download with `nosniff`, `Cache-Control: private, no-store` and `Content-Security-Policy: default-src 'none'; sandbox`; only images and
  audio inline; PDFs, video and text are attachments. HQ never renders a file as a page.
- **Retention**: every file expires (`hq.media.retention_days`, default 90). `MediaRetention::purgeExpired()` marks it purged and deletes the bytes only when
  nothing else refers to them (the store de-duplicates by content, so two businesses uploading the same bytes share one asset and one expiring must not
  remove the other's). The purge is not scheduled by this change: a person files the job (a scheduler tool for it is a follow-up; the class and tests exist).
- **The agent does not read attachments.** They are attached to the question for people. No vision or document model is called; a later design (docs/145 or
  ChatGPT) decides whether and how an agent may read one.
- Audit: `chat_attachment.stored`, `chat_attachment.rejected_infected`, `chat_attachment.purged`.

## Questions for ChatGPT

1. Should an agent ever read an attachment, and under which provenance and consent rules? (Default: no.)
2. Is 90 days right, or should a file expire with its conversation?
3. Require a virus scanner before enabling uploads? (Default: recommended, not required.)
