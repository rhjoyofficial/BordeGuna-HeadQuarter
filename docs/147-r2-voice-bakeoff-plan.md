# 147-r2. Bangla speech-to-text bake-off: the plan (31 October 2026)

Ruling 31 Oct 2026 item 8: speech to text has **no production default** until this bake-off passes. An OpenAI-compatible API is not
evidence of Bangla quality. Text to speech is settled separately: Azure `bn-BD` (Nabanita, Pradeep) is the preferred provider and
ElevenLabs the alternative.

Nothing in this plan runs on production, sends a customer's voice anywhere, or switches voice on. It produces one evidence file and,
if a provider passes, one reviewed change: `hq.voice.stt.benchmark_passed_provider` set to that provider's key (`App\Voice\SttBenchmarkGate`).
Voice stays inert after that too, until its own flag (`connectors.voice`), its credential and the separate activation gate (P7-G2).

## 1. Candidates

| Key | Provider | Model | Adapter in HQ |
|---|---|---|---|
| `google_speech` | Google Cloud Speech-to-Text v2 | Chirp 3, `bn-BD` | `GoogleSpeechToText` (built; set `hq.voice.google.model`) |
| `elevenlabs_scribe` | ElevenLabs | Scribe | not built: a small adapter behind `SpeechToText`, built only for the bake-off run |
| `openai_compatible_stt` | OpenAI | current transcription model | `OpenAiCompatibleSpeechToText` (built) |
| `gemini_stt` | Google Gemini API | audio understanding, transcription prompt fixed in code | not built: as for Scribe |

Every candidate answers the same `SpeechToText` contract and its transcript is untrusted (`Provenance::Customer`). Build-vs-integrate
(docs/96): the two missing adapters are thin HTTP clients over the providers' published APIs, not hand-rolled recognition.

## 2. The corpus: 50 voice notes

Recorded by staff and consenting volunteers for this purpose only (never a customer's real message, never a real phone number or
address). Each note is 5 to 40 seconds. Each has a reference transcript typed by a person in Bangla script (Banglish words kept as
spoken, in Latin script), checked by a second person.

| Group | Notes | What it covers |
|---|---:|---|
| Standard Dhaka Bangla, quiet room | 8 | the baseline |
| Regional accents: Chattogram, Sylhet, Barishal, Rajshahi, Rangpur, Khulna, Mymensingh | 14 (2 each) | accent robustness |
| Banglish (code-switching: "order ta confirm korben", "delivery charge koto") | 8 | mixed script and language |
| Noise: street, market, bus, kitchen, fan, phone speaker | 8 | real conditions |
| Names: people, places, districts, thanas | 4 | proper nouns |
| Numbers: amounts in BDT, quantities, phone-like digit strings (fake), dates, order numbers | 4 | digits and money |
| Product terms: ilish, shutki, ghee, sorishar tel, honey and the brand names LaLaDia, Bionic, Bor de Guna | 4 | the catalogue |

## 3. What is measured

1. **Word error rate (WER)** on Bangla script after NFC normalisation and punctuation removal, per group and overall.
2. **Character error rate (CER)**, which is fairer to Bangla compound characters.
3. **Critical-token accuracy**: every number, amount, name and product term in the reference is a critical token; each is right or
   wrong exactly. A wrong amount or quantity is the failure that matters most.
4. **Banglish handling**: Latin-script words kept, not transliterated into nonsense.
5. **Latency** (median and 95th percentile, seconds) and **cost per minute** in the provider's currency and in BDT (the media budget
   rules, docs/147-r2 section 6, apply to any paid run).
6. **Failure modes**: empty transcripts, hallucinated words in silence, wrong-language output.

Each note is sent to each provider once, from a test machine, with the provider's own sandbox or a dedicated test key. Results are
written to a CSV and a short report under `docs/` (no audio in the repository, which is public).

## 4. Pass gate (all must hold for the winner)

- Overall CER at or below 12%, and no group above 20%.
- Critical-token accuracy at or above 95% overall and 100% on amounts written as digits in the reference.
- No transcript invents words over silence in the noise group.
- Median latency under 5 seconds for a 20 second note.
- A cost per minute Joy accepts, recorded in BDT.

If no candidate passes, nothing changes: speech to text stays refused. If more than one passes, the lowest critical-token error wins,
then the lowest CER, then cost. The winner's key, the CSV and the report are committed together in one reviewed change; that change is
the only way `benchmark_passed_provider` is ever set.

## 5. Who does what

- Joy: approves the corpus brief, the recording volunteers' consent wording, the test keys, and the final change.
- An engineer: records the reference transcripts, builds the two thin adapters, runs the harness, writes the report.
- Nothing is activated by the bake-off itself.
