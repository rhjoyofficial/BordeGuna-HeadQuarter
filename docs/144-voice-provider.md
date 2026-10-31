# 144. Voice: decision record (Q2, 31 October 2026)

## Question

Which speech provider, and how does push to talk work without giving a voice any authority?

## Default chosen

- **Provider interface unchanged** (`SpeechToText`, `TextToSpeech`, capabilities `voice.transcribe` and `voice.synthesize`), with a new default
  adapter pair, `OpenAiCompatibleSpeechToText` and `OpenAiCompatibleTextToSpeech` (the OpenAI audio API: `/audio/transcriptions`,
  `/audio/speech`). One credential kind `speech_openai_compatible_key` and one base URL (`hq.voice.openai_compatible.base_url`) serve OpenAI, a
  self-hosted Whisper server or any compatible gateway. The adapters that already existed (Google Speech, Azure, ElevenLabs) are registered as
  alternatives at lower priority. Two ready providers at one priority are refused, never guessed, as for every capability.
- **Honest caveat from docs/97 section 2.3**: OpenAI's own voices are not built for Bengali, and Bangla transcription quality needs the 50-note
  bake-off. The default exists because the brief asked for an OpenAI-compatible default and because the adapter is provider-neutral; it is not a
  claim that it speaks good Bangla. Azure `bn-BD` stays the recommended voice for Bangla speech out.
- **Push to talk**: the voice screen asks the browser for the microphone ONLY inside the click handler of the talk button, never on load and never
  from an effect (a test holds that the API appears once, in that function, with no effect hook in the file). Click to start, click to stop and send.
- **Consent**: the recording goes to HQ only because the person clicked stop; the server deletes it as soon as it is transcribed (or refused). The
  transcript is stored (`voice_transcripts`, 30 days, visible only to the person) only when they ticked "keep a copy" for that recording.
- **No extra authority**: `voice.transcribe` returns an untrusted transcript (`Provenance::Customer`, never trusted) to the browser; the screen puts
  it in the Ask HQ question box and the person sends it from there, through the same gateway, approvals and business scope as typed text.
  Nothing is acted on by voice alone. A spoken yes does not approve anything.
- **Scope**: voice is HQ-wide (the key is `=hq`); a recording is a personal file (no business, only its uploader can open it), so a person limited to
  some businesses can use voice without a business, and cannot open anyone else's recording or answer audio.
- **Read aloud** (`voice.synthesize`): stock voices only (a cloned voice is refused by `SpeechSynthesisRequest`), audio kept one day for that person.
  It sends audio to no one.
- **Pause All AI**: both tools refuse while AI is paused, and a refused recording is discarded.
- Tools: `voice.transcribe`, `voice.synthesize` (R3, permission `hq.voice.use`, flag `connectors.voice`, not agent-executable).

## Unproven until a real key arrives

The OpenAI-compatible shapes are the published ones; Bangla quality and the right model (`gpt-4o-transcribe` default, configurable) are settled by
the bake-off. Safari's recorder produces MP4 audio, which the upload guard classifies as video and refuses for now; Chrome and Firefox produce
WebM/Ogg. A fix is a small guard change once confirmed on a real Safari.

## Questions for ChatGPT

1. Is an OpenAI-compatible default acceptable given docs/97's Bangla caveat, or should the default be ElevenLabs Scribe (speech to text) and Azure (text to speech)?
2. May the transcript be kept for 30 days, or should "keep" mean until the person deletes it?
