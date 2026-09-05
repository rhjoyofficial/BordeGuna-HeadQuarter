# ADR-008: Shops move to a second VPS (commerce tier)

**Status:** proposed

## Context
Nikolahost shared hosting is being exited. The current KVM 2 (8 GB, no swap) already carries the ERP, n8n, two Postgres, content-render, Zeno and will carry HQ.

## Decision
Provision a second Hostinger VPS for LaLaDia, Bionic, Econic (WordPress or a third shop fork) and the two static sites, each in Docker behind its own Traefik. ERP + HQ + automation stay on the current box.

## Alternatives
- Upgrade the current box to KVM 4/8 and host everything: simpler DNS and one bill, but one kernel and one disk for payroll, ads automation and public shops. Acceptable fallback if budget forbids two boxes.

## Consequences
Two hosts to patch and back up; shops get a real queue worker and scheduler for the first time; the ERP's isolation guarantee survives. Detail in doc 33.
