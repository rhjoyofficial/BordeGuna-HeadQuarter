<?php

namespace App\Playbooks;

use App\Models\PlaybookVersion;

/**
 * The active playbook text a person (or a connected caption drafter) writes a post from (docs/145-a).
 *
 * Read-only, and it lives here so the store remains the one place that names the playbook models
 * (PlaybookStructureTest, structural search S4). It returns plain text per kind: brand-specific active
 * version first, then the business-wide one. It never decides anything and is never read by deciding code.
 */
final class PlaybookGuidance
{
    /**
     * @param  list<PlaybookKind>  $kinds
     * @return list<array{kind: string, title: string, body: string}>
     */
    public function forBrand(int $businessId, ?int $brandId, array $kinds): array
    {
        $out = [];
        foreach ($kinds as $kind) {
            $version = null;
            if ($brandId !== null) {
                $version = PlaybookVersion::query()->where('business_id', $businessId)->where('brand_id', $brandId)
                    ->where('kind', $kind->value)->where('status', PlaybookVersion::ACTIVE)->first();
            }
            $version ??= PlaybookVersion::query()->where('business_id', $businessId)->whereNull('brand_id')
                ->where('kind', $kind->value)->where('status', PlaybookVersion::ACTIVE)->first();
            if ($version !== null) {
                $out[] = ['kind' => $kind->value, 'title' => (string) $version->title, 'body' => (string) $version->body];
            }
        }

        return $out;
    }
}
