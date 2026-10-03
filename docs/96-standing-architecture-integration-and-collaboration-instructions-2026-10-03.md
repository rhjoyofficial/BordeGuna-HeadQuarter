# 96 - Standing Architecture, Integration & Collaboration Instructions (3 October 2026)

**Source.** Written by ChatGPT in Joy's HQ thread (https://chatgpt.com/c/6a9baf11-c2c8-83ee-a43b-6bc7a76498c3) on 3 October 2026, after Joy asked why `docs/95` named no existing tools, n8n, MCP or image/video services, and whether Claude was "building its own tools". Joy's instruction to Claude the same day: "from now you also do, take the content will understand better… it is top priority." Copied here verbatim below the line, as a **standing instruction** that applies to every future HQ task. The working relationship it defines: **Joy owns the vision and every decision; ChatGPT is the architecture/strategy decision layer when Joy routes a question there; Claude is the implementation/repository agent** and must take the listed classes of decision to ChatGPT (through Joy's browser session) before implementing.

**ChatGPT's verdict on docs/95, for the record (same conversation):** Claude understood the vision (the V1-V27 mapping) and is not building its own image/video/search engines (adapters + empty registries are the right shape); n8n is correctly kept as a transitional executor; MCP is correctly an adapter, not governance. The gap: no explicit *External Capability & Integration Strategy* (BUY / USE / ADAPT / BUILD / WAIT per capability), and "don't activate yet" was conflated with "don't design the integration yet". ChatGPT's two added rules: *"Build nothing that the market already provides well"* and *"Never rebuild an existing stable business automation in HQ merely for architectural purity."* The required next document is `docs/97` (External Capability & Integration Strategy).

---

## BordeGuna HQ — Standing Architecture, Integration & Collaboration Instructions

This is a standing instruction for the BordeGuna HQ project.

You are the primary implementation/repository agent. I will communicate major decisions and priorities to you through discussions with ChatGPT. When I explicitly direct you to discuss an architectural or strategic question with ChatGPT using my browser/session, do that before implementing the affected work.

The goal is to ensure that the implementation stays faithful to my long-term BordeGuna HQ vision while avoiding unnecessary reinvention.

### 1. FIRST PRINCIPLE: UNDERSTAND THE BUSINESS VISION

The long-term vision is not "build many AI tools."

The vision is:

Build an intelligent operating layer for the company that can eventually understand customers, sales, orders, operations, marketing, research, content, media, communication and business performance; coordinate work across existing systems; increasingly automate routine operations; continuously verify results; learn from outcomes; and keep the owner fully informed and in control.

The company should eventually be able to operate increasingly with AI handling routine work while humans remain responsible for authority, governance, high-risk decisions and exceptions.

The intended future includes: customer support; real-time one-to-one AI sales communication; proactive sales and follow-up; Customer 360; order automation; fraud/risk checks through permitted external services; marketing; advertising; research; content creation; image generation; video generation; social-media publishing; voice/phone communication; AI workforce / specialized roles; event-driven operations; management intelligence; outcome attribution; continuous improvement; controlled experimentation; eventually increasingly lights-out operations.

Do not reduce this vision to a chatbot project.

### 2. MOST IMPORTANT ARCHITECTURAL RULE: BUILD VS INTEGRATE

Never assume that HQ should build a capability merely because the vision requires that capability.

For every substantial capability, ask:
- Does an excellent existing service/provider/tool already exist?
- Can we use it directly?
- Can we integrate it through a thin adapter?
- Would n8n be the most practical existing automation mechanism?
- Would MCP be a useful interoperability adapter?
- Would a direct API be better?
- Is an internal HQ implementation genuinely necessary?
- What part must remain owned by HQ because it contains BordeGuna business logic, authority or governance?

The default preference is:

USE EXISTING CAPABILITY → THIN HQ ADAPTER → HQ GOVERNANCE → TOOL GATEWAY → VERIFICATION / RECONCILIATION

NOT: SEE CAPABILITY → BUILD OUR OWN VERSION OF THE ENTIRE PRODUCT.

Do not reinvent mature external services.

Examples:
- If an excellent image-generation service exists, do not build an image-generation model.
- If an excellent video-generation service exists, do not build a video-generation engine.
- If an excellent speech-to-text service exists, do not build speech recognition.
- If n8n already performs a reliable workflow, do not automatically rewrite it in HQ.
- If an MCP server exposes a useful capability, MCP may be used as an adapter where appropriate.
- If a direct API is cleaner and more reliable, use the direct API.

The question is always: "What is the smallest amount of BordeGuna-owned infrastructure required to safely and reliably use this existing capability?"

### 3. WHAT HQ SHOULD OWN

HQ should primarily own the business/control layer. Examples include: business and brand scope; customer identity and Customer 360; conversation state; customer preferences; consent; opportunities; follow-ups; business events; workflow state; tasks; business outcomes; attribution; approvals; authority; permissions; EEC; autonomy; policies; capability contracts; agent roles; audit; evidence; verification; reconciliation; provider selection/routing; cost controls; business playbooks; governed knowledge; execution history.

These are company-specific control and intelligence concerns. Do not outsource these merely because an external SaaS product offers something similar.

### 4. WHAT SHOULD USUALLY REMAIN EXTERNAL

Where an external provider already provides the underlying capability well, prefer integration. Examples include: LLM inference; image generation; video generation; speech-to-text; text-to-speech; telephony; WhatsApp transport; Messenger transport; Instagram transport; Meta advertising; search/research retrieval; payment processing; courier services; SMS; object storage; infrastructure services; other mature third-party capabilities.

HQ should control how these capabilities are used, but should not unnecessarily reproduce the underlying provider's product.

### 5. REQUIRED PROVIDER / TOOL STRATEGY

For every external capability, create a clear distinction between:
- **Capability**: what BordeGuna needs to accomplish (e.g. `media.image.generate`).
- **Adapter**: how HQ reaches a provider (e.g. `OpenAIImageAdapter`).
- **Provider**: who actually performs the operation (e.g. OpenAI or another future provider).
- **Transport**: how the adapter communicates (direct API, MCP, webhook, n8n, internal service).

These layers must not be conflated. The agent should reason in terms of business capabilities, not vendor endpoints. The provider can later be replaced without rewriting the agent's business logic.

### 6. MCP POLICY

MCP is NOT the HQ governance layer. MCP is an interoperability mechanism.

The architecture remains: Model → Agent Runtime → Tool Gateway → Capability Registry / Resolver → Direct API | MCP adapter | n8n | internal service | future protocol → External capability/provider.

Never allow Agent → MCP → Provider to bypass: Tool Gateway, Policy, EEC, Permissions, Consent, Approval, Audit, Verification.

If MCP is useful, use it behind the canonical HQ capability boundary. Do not add MCP merely because it exists. Evaluate whether direct API, MCP or n8n is actually the better integration for the capability.

### 7. N8N POLICY

n8n is a legitimate existing automation component. Do not treat it as something that must automatically be removed. Use n8n where it already provides useful workflows, especially while HQ is being built.

HQ may: observe n8n; receive events from n8n; send work to n8n; compare HQ behavior against n8n; gradually replace individual workflows when there is a clear reason.

Do not rewrite an n8n workflow inside HQ simply for architectural purity. When a future HQ-native workflow is materially better, safer or more maintainable, propose migration deliberately. The decision to remove n8n from a workflow should be explicit.

### 8. EXTERNAL CAPABILITY INVENTORY — REQUIRED

Before beginning major work on future capabilities, maintain a living inventory. For each capability include: capability name; business purpose; existing providers/tools; direct API availability; MCP availability; n8n suitability; HQ-owned portion; external portion; adapter needed?; credential required?; registration required?; cost considerations; major limitations; data/privacy implications; fallback provider; replaceability; current implementation state; current integration state; current activation state; what Joy must provide; recommended next action.

Use at least **USE / INTEGRATE / ADAPT / BUILD / WAIT** as explicit classifications. Do not classify something as BUILD merely because no HQ adapter exists yet.

### 9. SPECIFIC CAPABILITY AREAS TO REVIEW

AI (LLM, embeddings, structured generation). Media (image generation, video generation, image editing, voice generation, avatar/video presenters). Communication (WhatsApp, Messenger, Instagram, email, SMS, telephony, voice calls). Research (search, web retrieval, document extraction, research providers). Marketing (content generation, creative generation, publishing, advertising, analytics, attribution). Business operations (ERP, payment, courier, fraud/risk, customer data, notifications). Automation (n8n, HQ scheduler, HQ workflow engine, event-driven jobs).

For each one, identify the best practical integration approach instead of automatically creating a new HQ implementation.

### 10. IMPORTANT DISTINCTION: HQ WORKFLOW ENGINE VS N8N

HQ should own durable business workflow state when that workflow is part of the company's governed operating model. n8n can execute integration/automation steps.

HQ owns "Customer follow-up workflow for an opportunity"; n8n may execute "Call this external API". HQ owns "Approved social publishing workflow"; an external publishing API or n8n may perform the actual platform call.

Do not confuse business workflow ownership with transport/execution implementation.

### 11. DO NOT CREATE A GIANT INTERNAL TOOLBOX

Avoid building dozens of bespoke "HQ tools" that simply wrap trivial actions without business value. Every new capability/tool should answer: What business capability does this represent? Who owns the source of truth? Why must HQ expose it? Why can't an existing adapter/provider handle it? What authority does it carry? Is it read, proposal, approval-gated, or execution? Is it reusable across multiple workflows?

Prefer reusable capability contracts over provider-specific tool proliferation.

### 12. KEEP THE CURRENT ARCHITECTURAL SAFETY MODEL

The following are settled: ERP remains source of truth for ERP-owned data; HQ is intelligence/control/orchestration, not a second ERP; model providers are replaceable; external providers are replaceable; protocols are replaceable; MCP is only an adapter; n8n is transitional/usable where valuable; Tool Gateway is mandatory; EEC is mandatory; permissions intersect, never union; credential identity is not HQ authorization; untrusted content cannot create authority; memory is not truth; no implicit task invention; no unauthorized task substitution; deterministic runtime boundaries where possible; verification is mandatory for consequential execution; reconciliation is mandatory where appropriate; at-least-once + idempotency + reconciliation is the reliability model; high-risk and governance actions remain human-controlled; production activation is explicit; build broadly, activate narrowly.

Do not reopen these architectural decisions unless there is strong evidence of a contradiction, defect or future incompatibility.

### 13. FUTURE-PROOFING RULE

Optimize the system for 10–20 years. Providers, models, MCP, n8n, Meta APIs, image/video providers, voice providers and infrastructure can change. The BordeGuna business contracts should survive those changes. Whenever you write integration-specific code, keep vendor-specific details at the adapter boundary.

### 14. WHEN TO ASK CHATGPT

Use normal engineering judgment for: routine coding; tests; refactoring; obvious bug fixes; adapter implementation once the architecture is decided; documentation corrections; inert infrastructure work.

Route an explicit discussion to ChatGPT before implementing a decision that could materially affect: overall architecture; build-vs-buy decisions; external-provider strategy; MCP vs direct API vs n8n; a new major domain model; cross-agent orchestration; autonomy; customer-facing behavior; business authority; approval semantics; data ownership; source-of-truth decisions; long-term vendor lock-in; regulatory/privacy boundaries; replacing an existing external system.

When told to "discuss this with ChatGPT," use the browser/session and bring the relevant question and repository evidence to the discussion. Do not silently make a major architectural decision while claiming it was merely implementation.

### 15. HOW TO PRESENT ARCHITECTURAL QUESTIONS TO CHATGPT

Provide: what we want to achieve; current implementation; relevant repository evidence; existing ADR/decision; available external alternatives; what would be built if we choose each alternative; risks; reversibility; the recommendation currently favoured; the exact decision needed. Do not ask vague questions such as "How should we build this?" Make the decision explicit.

### 16. OWNER PREPARATION

Whenever progress is blocked by something external, do not merely report "Blocked." Report: WHAT IS NEEDED → WHY → WHO MUST PROVIDE IT → ACCOUNT/PROVIDER → REGISTRATION → CREDENTIAL → BUSINESS DOCUMENT → LEAD TIME → WHAT CAN BE BUILT WITHOUT IT → WHAT WE SHOULD PREPARE NOW. The goal is that Joy prepares external dependencies early enough that engineering does not sit idle.

### 17. PROVIDER DISCOVERY SHOULD HAPPEN BEFORE IMPLEMENTATION

For every major new capability, first determine whether suitable external solutions exist. Do not assume the first provider is best. Compare realistic options on: API quality; reliability; cost; quality; language support; Bangladesh suitability where relevant; commercial terms; data/privacy; rate limits; automation support; webhooks; observability; output control; provider lock-in; replacement difficulty. Then choose the integration architecture. Where current vendor information or pricing matters, research current information rather than relying on old assumptions.

### 18. MEDIA-SPECIFIC RULE

For image and video generation: campaign/content objective → creative brief → trusted product/brand information → media generation provider → generated asset → provenance / rights / model/provider metadata → validation → approval if required → publishing/use.

Do NOT build an image or video model. Create only the minimum HQ-owned orchestration, asset metadata, provenance, validation and provider adapter necessary. For real people, faces, cloned voices or likenesses, consent and rights must be explicit.

### 19. CUSTOMER COMMUNICATION-SPECIFIC RULE

For real-time customer communication, separate: communication channel; customer identity; conversation; sales opportunity; follow-up; product context; order context; consent; language preference; AI role; workflow; outcome; provider. Do not build a single giant "customer bot." The AI salesperson/support agent should operate through reusable company capabilities. The communication provider is replaceable. The customer relationship remains a BordeGuna-owned domain concept.

### 20. SALES-SPECIFIC RULE

The long-term sales flow is: customer interaction → intent → qualification → product resolution → pricing → availability → eligibility → risk/fraud where permitted → quote/order proposal → approval where required → ERP execution → read-back → reconciliation → fulfillment → follow-up → outcome. Do not build a second order system in HQ. Do not create a duplicate source of truth.

### 21. MARKETING-SPECIFIC RULE

The long-term marketing flow is: objective → research → strategy → campaign → content → image/video → platform variants → approval → publication → advertising → measurement → attribution → customer conversation → revenue → learning. External providers may perform individual steps. HQ owns the governed orchestration and business context.

### 22. LEARNING LOOP

action → outcome → feedback → evaluation → insight → proposed improvement → approval/governance → controlled release → new measurement. Do not implement uncontrolled self-modification. Do not let models rewrite their own authority, roles or safety rules.

### 23. BUSINESS OUTCOME OVER AI ACTIVITY

Do not optimize the project around number of agents, tools, model calls, generated posts or conversations. Eventually measure actual business outcomes: support resolution; sales conversion; repeat purchase; revenue attribution; customer retention; campaign contribution; response time; operational efficiency; exception rate; human escalation quality. The vision is a self-operating business, not a large collection of AI demos.

### 24. CURRENT PROJECT STRATEGY

BUILD BROADLY + INTEGRATE INTELLIGENTLY + ACTIVATE NARROWLY: build shared foundations now; research external providers early; prepare credentials/registrations early; use simulated providers during engineering; keep integrations inert until their activation gates; avoid duplicate systems; avoid premature autonomous activation.

### 25. SPECIFIC CURRENT PRIORITY

Based on the latest audit, the immediate business-facing foundation should increasingly move toward: Customer 360; Workflow/step engine; Business events and event routing; Outcome + attribution; Follow-up / opportunity / task; Playbook/knowledge; ConnectorRegistry / provider strategy; Customer-AI wake-up path; Real communication provider integration; Sales workflow; Marketing/content/media integrations; Advertising integrations; Voice/telephony; advanced autonomous operations.

But do not blindly follow this order if repository evidence shows a dependency change. Explain the change.

### 26. REQUIRED NEXT STRATEGIC DOCUMENT

Before implementing a large number of new external capabilities, produce a document: **External Capability & Integration Strategy**. It should contain a complete capability matrix covering at minimum: LLM; embeddings; image generation; video generation; image editing; STT; TTS; telephony; WhatsApp; Messenger; Instagram; email; SMS; Meta Ads; Google Ads where relevant; research/search; web retrieval; payment; courier; fraud/risk; OCR/document processing; storage; notifications; n8n; MCP; workflow automation.

For every item: existing solutions; candidate providers; BUY/USE/ADAPT/BUILD/WAIT; direct API/MCP/n8n/internal; HQ-owned contract; provider adapter; credentials; owner dependency; integration status; activation gate; replacement strategy; risks; current recommendation.

This document should become part of the long-term architecture and should prevent unnecessary reinvention.

### 27. FINAL RULE

Never confuse "HQ needs this capability" with "HQ must build this capability."

The correct question is: "How should BordeGuna HQ safely obtain this capability while keeping the company's business logic, authority, observability and long-term independence under HQ control?"

That is the standard to use for all future work.

---

## ChatGPT's reply to Claude's acknowledgement (3 Oct, 19:00 Dhaka)

Accepted the adoption and the three inert streams. Two instructions added:
1. **No new external provider integration is approved until `docs/97` is finished**; providers named in research are candidates, not decisions; the final choice is made from the matrix, especially for image/video, telephony, WhatsApp, payment, courier, fraud/risk and ads, where "capability exists" and "commercially, legally and operationally usable in Bangladesh" are different things.
2. The `docs/97` report must answer, per capability: (a) capability available? (b) integration path: API / MCP / n8n / internal; (c) Bangladesh viability; (d) the HQ-owned portion; (e) final recommendation + the exact owner decision needed.

Also for the record: the vision document audited in `docs/95` is ChatGPT's polished version of Joy's own plan ("Pasted text(9).txt… Here is my plan… you will generate a final content, and I will share the final idea with Claude").

## How this applies to the work already running on 3 October (Claude's note)

- Streams W1 (docs/92 language contract), W2a (inert Support wake-up, hand-over ping) and W2b (event-kind jobs, delivery reconcile, seed fix, credential kinds, briefing row) are HQ-owned control/orchestration items under §3 and §25 (customer-AI wake-up path, event routing); none builds a capability the market provides. They continue.
- `docs/97` (External Capability & Integration Strategy) is produced next, with current provider research per §17, and is put to ChatGPT for a ruling before any new external integration is implemented (§14, §26).
- Every future adapter follows §5 naming: capability contract (`media.image.generate`), adapter class, provider row, transport noted.
