<?php

namespace App\Agents;

use App\Gateway\Scope\BusinessReferenceResolver;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Store;

/**
 * Reads the TRUSTED text for the targets the task is about, and nothing else (ADR-043, docs/74 C.2).
 *
 * Deterministic, narrow and versioned, like the security-intent detector beside it (ADR-042): it
 * knows the group phrases and the names in HQ's own directory, matched as whole phrases after the
 * same normalisation the reference resolver uses, so "laladia.com" is the LaLaDia store and
 * "Bionic Garden" is Bionic. It does not understand the task; it answers one question, "which
 * businesses did the operator name", so that a model's omission of a target can be filled from
 * the operator's words and never from every business HQ holds.
 *
 * The version is recorded on every run. A change to the phrases or the matching is a new version,
 * because a scope decision is evidence a gate reads back.
 */
final class TaskScopeResolver
{
    public const VERSION = 'v1';

    /** A group phrase makes the task group-wide; the list is deliberately short and literal. */
    public const GROUP_PHRASES = [
        'all businesses', 'every business', 'each business', 'all the businesses', 'all our businesses',
        'all stores', 'every store', 'each store', 'all shops', 'every shop', 'each shop',
        'across the group', 'group wide', 'the whole group', 'whole group', 'all of them',
    ];

    /** A name shorter than this is not matched, so an abbreviation cannot claim a business by accident. */
    private const MIN_NAME_LENGTH = 3;

    /**
     * @param  int|null  $runBusinessId  a run opened FOR one business is single for that business before the text is read
     */
    public function resolve(string $trustedText, ?int $runBusinessId = null): TaskScope
    {
        if ($runBusinessId !== null && ($business = Business::find($runBusinessId)) !== null) {
            return new TaskScope(TaskScope::SINGLE, [(string) $business->slug], [], 'run opened for '.$business->slug);
        }

        $text = ' '.BusinessReferenceResolver::normalise($trustedText).' ';
        if (trim($text) === '') {
            return TaskScope::none();
        }

        foreach (self::GROUP_PHRASES as $phrase) {
            if (str_contains($text, ' '.$phrase.' ')) {
                return new TaskScope(TaskScope::GROUP, [], [], $phrase);
            }
        }

        $businesses = [];   // business id => slug
        $stores = [];       // store slug
        $matched = [];

        foreach (Business::query()->where('is_active', true)->orderBy('id')->get(['id', 'slug', 'name']) as $business) {
            foreach ([(string) $business->slug, (string) $business->name] as $name) {
                if ($this->names($text, $name)) {
                    $businesses[(int) $business->id] = (string) $business->slug;
                    $matched[] = $name;
                }
            }
        }

        foreach (Store::query()->where('is_active', true)->orderBy('id')->get(['id', 'business_id', 'slug', 'name']) as $store) {
            foreach ([(string) $store->slug, (string) $store->name] as $name) {
                if ($this->names($text, $name)) {
                    $stores[] = (string) $store->slug;
                    $businesses[(int) $store->business_id] ??= (string) Business::find($store->business_id)?->slug;
                    $matched[] = $name;
                }
            }
        }

        foreach (Brand::query()->where('is_active', true)->orderBy('id')->get(['id', 'business_id', 'slug', 'name']) as $brand) {
            foreach ([(string) $brand->slug, (string) $brand->name] as $name) {
                if ($this->names($text, $name)) {
                    $businesses[(int) $brand->business_id] ??= (string) Business::find($brand->business_id)?->slug;
                    $matched[] = $name;
                }
            }
        }

        $slugs = array_values(array_filter(array_unique(array_values($businesses))));
        $stores = array_values(array_unique($stores));

        return match (count($slugs)) {
            0 => TaskScope::none(),
            1 => new TaskScope(TaskScope::SINGLE, $slugs, $stores, implode(', ', array_unique($matched))),
            default => new TaskScope(TaskScope::NAMED, $slugs, $stores, implode(', ', array_unique($matched))),
        };
    }

    /** Whole-phrase match of one directory name inside the normalised text. */
    private function names(string $normalisedText, string $name): bool
    {
        $needle = BusinessReferenceResolver::normalise($name);

        return mb_strlen($needle) >= self::MIN_NAME_LENGTH && str_contains($normalisedText, ' '.$needle.' ');
    }
}
