# ADR-006: n8n stays as a transitional executor

**Status:** proposed

## Context
n8n runs the only live customer AI (Bionic Messenger bot) and proven content-stage integrations (OpenAI image, Facebook publish, HeyGen). It holds platform credentials and has no policy layer.

## Decision
Keep n8n running. Replace each workflow with an HQ tool/workflow only after HQ has run it in shadow mode for a week; then repoint the trigger, deactivate (not delete) the n8n workflow for a month, and finally remove the credential from n8n. Nothing new of business significance is built in n8n from now on except emergency fixes to the live bot.

## Consequences
Two systems during Phases 2-6; a `shadow_actions` log; a checklist per workflow migration in doc 30.
