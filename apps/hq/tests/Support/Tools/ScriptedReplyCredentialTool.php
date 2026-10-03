<?php

namespace Tests\Support\Tools;

use App\Gateway\Descriptor\CredentialRequirement;

/** The scripted reply tool, declaring a credential that is never stored: the pre-flight refuses it. */
class ScriptedReplyCredentialTool extends ScriptedReplyTool
{
    public function key(): string
    {
        return 'test.scripted_reply_credential';
    }

    protected function descriptorArguments(): array
    {
        return ['key' => $this->key(), 'credentials' => [new CredentialRequirement('scripted_provider', 'args.account')]] + parent::descriptorArguments();
    }
}
