<?php

namespace Tests\Support\Tools;

use App\Consent\ConsentPurpose;
use App\Gateway\Descriptor\ConsentRequirement;

/** The scripted reply tool, declaring a consent requirement no HQ-wide call can meet: the consent step refuses it. */
class ScriptedReplyConsentTool extends ScriptedReplyTool
{
    public function key(): string
    {
        return 'test.scripted_reply_consent';
    }

    protected function descriptorArguments(): array
    {
        return ['key' => $this->key(), 'consentRequired' => new ConsentRequirement(ConsentPurpose::Service)] + parent::descriptorArguments();
    }
}
