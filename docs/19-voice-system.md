# 19 - Voice System (Phase 7)

## Separation of concerns

```
 Telephony provider  <->  Voice Gateway (Node)  <->  Realtime AI (speech-to-speech or STT->LLM->TTS)
                                |
                                +-->  HQ Tool API (/api/v1/tools/*, agent identity = voice agent)  <-> CRM / ERP / Commerce
```

- **Telephony**: the layer that owns phone numbers, SIP/WebRTC media, call control, recording. Candidates for Bangladesh: a local BTRC-licensed IP telephony provider with SIP trunking (required for a local number; **UNKNOWN which providers offer SIP to small businesses, must be researched**), or Twilio/Vonage for outbound-only with a foreign caller id (poor answer rates locally).
- **Voice Gateway**: a small Node service in its own container. It bridges audio between the provider and the realtime model, handles barge-in, silence, DTMF, and calls HQ tools over HTTP. It holds no business logic.
- **Realtime AI**: OpenAI Realtime API is the strongest current fit for low-latency speech-to-speech with tool calling; a fallback pipeline (Whisper STT -> text model -> TTS) covers providers without realtime. Bangla speech quality must be tested before any customer call; **UNKNOWN today**.
- **Business tools**: the same `support` tool set through the gateway, with the same policies. Nothing voice-specific may bypass the policy engine.

## Use cases in priority order

1. Order confirmation calls for COD orders (the highest-value case: COD cancellations are ~33%; a confirmation call before dispatch is the classic fix). Outcome writes back to the order as a confirmation record (the shop already models dispatch confirmation evidence).
2. Inbound receptionist: identify caller by phone -> customer profile -> answer order status / product questions -> take order -> escalate to a human line.
3. Follow-up and reminder calls (delivery today, reorder nudge) with strict consent and time windows.
4. Voice commands from the owner (Telegram voice notes first, phone later).

## Data

`calls(id, business_id, direction, customer_id, provider_call_id, from, to, started_at, ended_at, outcome, transcript_ref, recording_ref, cost, agent_run_id)`. The n8n `call_confirmations` table (empty) is superseded.

## Safeguards

Disclose that the caller is an AI assistant at the start; never call outside 09:00-20:00 Asia/Dhaka; max two attempts per order; recording only with consent where required; every call transcript is a conversation in the inbox with the same handoff states.

## Blockers

Telephony provider decision and number acquisition; Bangla realtime quality test; cost per minute vs the value of a confirmed COD order. All in doc 31.
