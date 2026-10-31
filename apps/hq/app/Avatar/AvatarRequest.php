<?php

namespace App\Avatar;

/** Exactly what one generation asks a provider for, built from one valid consent row (docs/145-b). */
final readonly class AvatarRequest
{
    public function __construct(
        public string $generationUuid,
        public string $consentUuid,
        public string $modality,
        public string $script,
        public ?string $providerAvatarRef,
        public ?string $providerVoiceRef,
    ) {}
}
