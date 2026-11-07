# 144. Reel and video drafts: decision record (Q3, 31 October 2026)

## Question

How does HQ get a reel or short video from a brief, with a person approving before anything is published?

## Default chosen

- **Capability `media.video.generate`** (moved from reserved to contracted), contract `App\Media\Pipeline\MediaPipeline`: `start(spec)` returns a handle,
  `check(handle)` returns `queued`, `running`, `ready` (with the video bytes) or `failed`. Asynchronous by design (docs/97 2.2: an async job and a poll).
- **Provider**: Google Veo through the Gemini API (`VeoMediaPipeline`), the recommendation of docs/97 (Veo 3.1 Fast, the same Google key), model and base URL
  in configuration (`hq.media.veo`). The fallbacks (Kling through fal.ai, Runway) are further implementations of the same interface, not built. `FakeMediaPipeline`
  is the sandbox stand-in. The operation name is validated to a strict pattern before it is placed in a URL (a test found and fixed a looser one).
- **Draft only**. `media.draft_reel` (R4: it spends provider credit, so it always waits for approval) creates a `media_drafts` row in `generating`;
  `media.check_draft` fetches the result, checks the bytes really are a video, and stores it as a `MediaAsset` with `Provenance::Generated` (untrusted) and
  NO rights recorded, so `MediaAsset::permitsUse()` refuses it for publication until a person records rights. A person then approves or rejects the draft on
  `/media-drafts`. Approving records a yes on the draft and nothing else: no tool in this change publishes, posts or sends it, and `content.publish` keeps
  its own approval. The review screen has no publish button.
- **No likeness, no voice**: `MediaJobSpec` has a brief, a length (at most 15 seconds) and a shape, and no field for a person's face or voice; the
  synthetic-identity consent class applies first (docs/97 `media.presenter.generate` stays WAIT).
- **Scope**: a draft belongs to one business; source photos must be that business's own chat attachments; a restricted person cannot see, check or decide
  another business's drafts (404).
- **Pause All AI**: the draft request refuses while AI is paused.
- Flag `connectors.media_pipeline`, permission `hq.tools.media.draft`, credential `gemini_video_key` (`hq`).

## Unproven until a key arrives

The long-running pattern (`POST models/{model}:predictLongRunning`, poll `operations/{id}`, download the file URI with the same key) is the Gemini API's
published video-generation pattern as recalled; the first live call proves the field names and the model id. Cost per clip is not capped in code yet: the
approval card names the action; a per-month media budget is a follow-up.

## Questions for ChatGPT

1. A media budget ceiling per business (like the ads one)? (Default: approval per request only.)
2. May a draft source be a customer's photo? (Default: no, only HQ users' own uploads for that business.)
