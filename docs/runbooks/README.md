# Runbooks

Short, do-this-then-that pages for the things a person operates in HQ. Each names the command or
the screen, what it changes, what it never changes, and what to do when it goes wrong. They are
written for Joy and for whoever helps him; the design behind each is in the numbered docs they cite.

| Runbook | When you need it |
| --- | --- |
| [The inbox](inbox.md) | A customer wrote to a Page or an account HQ holds, and a person has to answer, hand over, note or remember |
| [The support agent's gate, P3-G2](p3-g2-support-gate.md) | Running the support agent's evaluation on the simulated conversations |
| [The Executive's shadow week](shadow-week.md) | Opening, watching, stopping and closing the seven-day A0 period |
| [Readiness and credentials](readiness.md) | Reading the readiness table, replacing a token, and what each state means |
| [The model provider is blocked](provider-blocked.md) | Every AI call fails with a credit, key or outage message |
| [HQ's AI and the Anthropic bill](anthropic-billing.md) | Reading the billing page, and the credit and spend limit HQ needs (state on 17 September) |
| [Alerts, dashboards and retention](alerts-and-retention.md) | An alert opened, you want the dashboards, or you want to know what old records become |
| [Ask HQ and scheduling in words](ask-hq-and-scheduling-in-words.md) | Asking an agent a question, or creating a job from a sentence (both switched off for now) |

Every command below runs on the VPS, inside the app container:

```
cd /opt/bordeguna-hq
docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec -T app php artisan <command>
```

Nothing in any runbook activates an agent, creates an autonomy grant, registers a send provider or
turns Meta inbound on. Those are decisions recorded elsewhere (`docs/77`, `docs/78`).
