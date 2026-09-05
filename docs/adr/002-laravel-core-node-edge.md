# ADR-002: Laravel core, Node only at the realtime edge

**Status:** proposed

## Context
Agent frameworks are richest in Python and TypeScript. Every existing system here is Laravel + React; the operator and the implementing AI agent are fluent in it; the ERP already has provider drivers in PHP.

## Decision
HQ's business layer, tool gateway, policies, scheduler, workflows and UI are Laravel 12 + Inertia/React. A single Node service is added only for the realtime voice gateway (Phase 7) where bidirectional audio streaming makes PHP unsuitable.

## Alternatives
- Python (LangGraph, etc.) core: better agent tooling, but a third runtime, a second ORM, and no reuse of the ERP's drivers, RBAC or deploy pattern. The agent loop needed here (structured output + typed tools + policy) is simple enough to own.
- Node core: same argument; also splits the team's attention from the two Laravel products.

## Consequences
Provider SDKs: `openai-php/client` and `anthropic-ai/sdk` (already in the ERP). Realtime voice is isolated behind HTTP so it can be swapped or dropped.
