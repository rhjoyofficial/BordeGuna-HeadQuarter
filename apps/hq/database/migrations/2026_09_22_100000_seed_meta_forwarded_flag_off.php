<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/88 A point 2: the n8n copy-forward receiver's flag, seeded OFF.
 *
 * In a migration rather than a seeder for the reason `channels.meta.inbound` is (2026_09_13_150000): the
 * route answers 404 unless a row says otherwise, and the row must exist, OFF, on every database the
 * code reaches, so the flags screen shows it and nobody has to create it to know it is off. Inserted
 * only when absent, so re-running never switches back a flag a person set.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('feature_flags')->where('key', 'channels.meta.forwarded')->where('environment', '*')->exists();
        if (! $exists) {
            DB::table('feature_flags')->insert([
                'key' => 'channels.meta.forwarded',
                'environment' => '*',
                'description' => 'Accept the n8n copy of Meta webhooks for the P3-G3 channel shadow. OFF until gate P3-G3.',
                'enabled' => false,
                'rollout' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'channels.meta.forwarded')->delete();
    }
};
