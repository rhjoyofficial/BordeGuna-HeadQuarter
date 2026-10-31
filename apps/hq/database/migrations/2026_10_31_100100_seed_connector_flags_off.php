<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/144: the switches of the HQ-completion connectors, seeded OFF.
 *
 * In a migration, like `channels.meta.forwarded`, so the flags screen lists them on every database and nobody
 * has to create one to know it is off. Inserted only when absent: re-running never switches back a flag a
 * person set. A connector needs its flag ON and its credential stored; either alone does nothing.
 */
return new class extends Migration
{
    private const FLAGS = [
        'connectors.courier' => 'Courier tracking: poll couriers and accept their webhooks into HQ\'s parcel read model. Reads only; books nothing. OFF until a courier credential is stored.',
        'connectors.voice' => 'Voice: speech to text and text to speech through the chosen provider. OFF until a speech credential is stored.',
        'connectors.media_upload' => 'Photos, videos and files attached in Ask HQ (stored per business, with retention). OFF until switched on.',
        'connectors.media_pipeline' => 'Reel and video drafts from the media pipeline provider. Drafts only; a person approves before anything is published.',
        'connectors.mail_ingestion' => 'Read the inbound supplier mailbox and turn bill attachments into DRAFTS for human review.',
        'connectors.erp_draft_bill' => 'Ask the ERP to create a DRAFT supplier bill from a reviewed document. Needs an ERP write token and a second person\'s approval.',
        'connectors.ads_draft' => 'Create PAUSED ad campaign drafts at the platform. Never activates; capped at the per-campaign daily budget in code.',
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $key => $description) {
            if (! DB::table('feature_flags')->where('key', $key)->where('environment', '*')->exists()) {
                DB::table('feature_flags')->insert([
                    'key' => $key, 'environment' => '*', 'description' => $description,
                    'enabled' => false, 'rollout' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', array_keys(self::FLAGS))->delete();
    }
};
