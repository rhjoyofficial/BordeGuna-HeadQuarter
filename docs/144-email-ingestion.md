# 144. Email and PDF bill ingestion: decision record (Q4, 31 October 2026)

## Question

How does HQ read supplier bills from a mailbox and put them in front of a person, without acting on anything?

## Default chosen

- **Capability `mail.inbox.read`**, contract `MailboxConnector` with ONE method, `fetch(accountRef, since, limit)`. There is no send, reply, delete,
  label or mark-as-read method, so no implementation can be asked to write to a mailbox (a test holds the method list). Drivers: `GmailMailbox` (REST,
  `gmail.readonly`), `GraphMailbox` (Microsoft Graph, `Mail.Read`), `FakeMailbox`. An IMAP driver is another implementation of the same interface and is
  NOT built (it needs a package decision; docs/96): the interface does not change when it arrives.
- **Business-scoped**: the credential is per business (`gmail_mailbox_token` or `graph_mailbox_token`, account = business slug), the registry resolves
  it by business, and every row (`mail_messages`, `mail_attachments`, `ingested_documents`) carries the business. Mail already read (same business,
  connector, external id) is skipped, so polling twice writes nothing twice. The poll moves forward from the newest received mail.
- **Safe files** (shared with chat uploads): the byte-level allowlist, PDFs without active content, an optional virus scan; a file HQ will not store is recorded
  with the reason and not kept. Retention 365 days by default (`hq.ingestion.retention_days`).
- **Extraction** (`DocumentTextExtractor`): PDFs through `smalot/pdfparser` (a proven package), plain text and CSV as they are. A scanned image or a PDF with
  no text layer needs OCR, which is a separate provider decision: it is recorded as "no readable text", not guessed.
- **Structured extraction with citation to the source text** (`BillExtractor`, default `RuleBasedBillExtractor`): supplier, invoice number, invoice
  date, total (BDT unless the document says USD) and `description qty x unit total` lines. EVERY field carries a citation: the exact text and its byte
  offsets in the source. `ExtractionVerifier` re-checks each citation against the source and DROPS any field it cannot prove (and says so in the warnings),
  so a reviewer never sees a number nobody can point at. Lines are checked against quantity times unit price, and the lines against the total, in whole minor
  units; a disagreement is a warning, never a silent fix. The default is deterministic and has no model; a model-assisted extractor is a later swap behind
  the same interface and must pass the same verifier (and call `AiPause`).
- **Draft only.** The result is an `ingested_documents` row in `draft`. The review screen (`/ingestion`, `/ingestion/{uuid}`) shows each field beside its
  citation and the full source text; a person marks it reviewed or rejects it (audited, with a note). Nothing is sent to the ERP by reading mail. A reviewed
  document can be offered to the ERP as a draft bill, which is a separate R4 request with a second person's approval (`144-erp-write-contract.md`).
- Tool `mail.poll_inbox` (R3, permission `hq.tools.mail.ingest`, flag `connectors.mail_ingestion`, no scheduled job by default). Screen permission
  `hq.ingestion.review`.

## Unproven until a mailbox is connected

Gmail and Graph call shapes are the published ones; the first live call proves them. Token refresh is not built: Joy stores a short-lived access token, or an
OAuth refresh flow is added (a decision: the repository has an OAuth manager for other providers). Bangla-language and scanned bills need OCR.

## Questions for ChatGPT

1. A model-assisted extractor (through `ai.complete`) for messy bills, with the same citation verifier? (Default: rule-based only.)
2. OCR provider for scanned bills? (Default: none; recorded as unreadable.)
3. IMAP, or Gmail and Graph only? (Default: Gmail and Graph; IMAP only if a supplier mailbox needs it.)
