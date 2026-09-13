<?php

namespace App\Gateway\Scope;

use App\Models\Agent;
use App\Models\Brand;
use App\Models\Business;
use App\Models\Store;
use App\Models\ToolRegistration;
use App\Support\Authorization\BusinessScope;
use Normalizer;

/**
 * Turns a reference somebody gave into the business or store HQ actually has (docs/67, ADR-039).
 *
 * ## Why this exists
 *
 * ⭐ The fourth gate ended two runs per suite the same way: asked "How is Bionic Garden doing this
 * week?", the agent called `erp.read_snapshot` with no arguments, was refused for naming no
 * business, and asked for the identical call again. Nothing in the tool's contract told it the
 * argument was required, nothing told it which values were valid, and even a model that guessed
 * would have guessed `bionic-garden`, which is the STORE and the BRAND, while the business is
 * `bionic`. Joy's instruction on it: do not make the model guess a display-name-to-slug mapping
 * where HQ can resolve the identity deterministically.
 *
 * So the identity is resolved here, in code, from every name HQ already holds for a business: its
 * slug, its display name, the slugs and names of its stores, the slugs and names of its brands.
 * "Bionic Garden", "bionic-garden", "bionic.garden" and "bionic" all mean business `bionic`; the
 * model is told the canonical slug in its catalogue and in a directory block, and a tool argument
 * given as any of the others still lands on the one row it meant.
 *
 * ## What it never does
 *
 * ⛔ It never widens. A reference that matches nothing resolves to null and the call is refused; a
 * reference that matches two different businesses (which the seed data does not contain, and which
 * would be a data defect) resolves to null rather than to whichever came first. Resolving an
 * identity is not authorisation: whether the caller may act on the business it named is decided
 * afterwards by `ToolGateway::checkScope()`, exactly as before.
 *
 * ## The permitted list
 *
 * A model can only name a business it is shown. `permittedBusinesses()` is the list the catalogue
 * and the directory are built from: active businesses, narrowed by the tool's runtime switch
 * (`tool_registrations.enabled_businesses`), by the enabling human's own business scope (an agent
 * borrows a human's authority and never exceeds it), and by the business the run was opened for.
 */
final class BusinessReferenceResolver
{
    public function __construct(private readonly BusinessScope $businessScope) {}

    /** The business a reference names, or null when it names none or more than one. */
    public function business(string $reference): ?Business
    {
        $needle = self::normalise($reference);
        if ($needle === '') {
            return null;
        }

        $ids = [];

        foreach (Business::query()->get(['id', 'slug', 'name']) as $business) {
            if (self::normalise((string) $business->slug) === $needle || self::normalise((string) $business->name) === $needle) {
                $ids[] = (int) $business->id;
            }
        }

        foreach (Store::query()->get(['business_id', 'slug', 'name']) as $store) {
            if (self::normalise((string) $store->slug) === $needle || self::normalise((string) $store->name) === $needle) {
                $ids[] = (int) $store->business_id;
            }
        }

        foreach (Brand::query()->get(['business_id', 'slug', 'name']) as $brand) {
            if (self::normalise((string) $brand->slug) === $needle || self::normalise((string) $brand->name) === $needle) {
                $ids[] = (int) $brand->business_id;
            }
        }

        $ids = array_values(array_unique($ids));

        // ⛔ Two different businesses is an ambiguity, and an ambiguity is not resolved by picking one.
        return count($ids) === 1 ? Business::find($ids[0]) : null;
    }

    /** The store a reference names, by its slug or its display name, or null. */
    public function store(string $reference): ?Store
    {
        $needle = self::normalise($reference);
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach (Store::query()->get() as $store) {
            if (self::normalise((string) $store->slug) === $needle || self::normalise((string) $store->name) === $needle) {
                $matches[] = $store;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * The businesses an agent may name in this run, in a stable order.
     *
     * @return list<Business>
     */
    public function permittedBusinesses(Agent $agent, ?string $toolKey = null, ?int $businessId = null): array
    {
        $query = Business::query()->where('is_active', true)->orderBy('id');

        if ($businessId !== null) {
            $query->whereKey($businessId);
        }

        $enabling = $agent->enablingUser;
        if ($enabling !== null && ($scope = $this->businessScope->all($enabling)) !== null) {
            $query->whereIn('id', $scope);
        }

        if ($toolKey !== null) {
            $switch = ToolRegistration::query()->where('key', $toolKey)->first(['enabled_businesses']);
            $enabledFor = $switch?->enabled_businesses;
            if (is_array($enabledFor) && $enabledFor !== []) {
                $query->whereIn('id', array_map('intval', $enabledFor));
            }
        }

        return $query->get()->all();
    }

    /**
     * The slugs of `permittedBusinesses()`, which is what a tool argument takes.
     *
     * @return list<string>
     */
    public function permittedBusinessSlugs(Agent $agent, ?string $toolKey = null, ?int $businessId = null): array
    {
        return array_values(array_map(
            static fn (Business $b) => (string) $b->slug,
            $this->permittedBusinesses($agent, $toolKey, $businessId),
        ));
    }

    /**
     * The store slugs a tool argument may take, for the permitted businesses.
     *
     * @return list<string>
     */
    public function permittedStoreSlugs(Agent $agent, ?string $toolKey = null, ?int $businessId = null): array
    {
        $businessIds = array_map(static fn (Business $b) => (int) $b->id, $this->permittedBusinesses($agent, $toolKey, $businessId));

        return Store::query()->where('is_active', true)->whereIn('business_id', $businessIds)->orderBy('id')
            ->pluck('slug')->map(fn ($s) => (string) $s)->all();
    }

    /**
     * Every slug an active business can be named by, for a refusal that lists the valid values.
     *
     * @return list<string>
     */
    public function activeBusinessSlugs(): array
    {
        return Business::query()->where('is_active', true)->orderBy('id')->pluck('slug')->map(fn ($s) => (string) $s)->all();
    }

    /** @return list<string> */
    public function activeStoreSlugs(): array
    {
        return Store::query()->where('is_active', true)->orderBy('id')->pluck('slug')->map(fn ($s) => (string) $s)->all();
    }

    /**
     * The directory block written into the prompt: for each business the model may name, the slug
     * a tool argument takes and the other names it goes by, so "Bionic Garden" is a lookup and not
     * a guess.
     *
     * @param  list<Business>  $businesses
     */
    public function directory(array $businesses): string
    {
        if ($businesses === []) {
            return 'You may name no business in this run.';
        }

        $lines = ['The businesses you may name. A tool argument such as business_slug takes the slug exactly as written here; '
            .'the other names are what people call it, and they resolve to the same business.'];

        foreach ($businesses as $business) {
            $parts = ["{$business->slug}: {$business->name}"];

            $stores = Store::query()->where('business_id', $business->id)->where('is_active', true)->orderBy('id')->get(['slug', 'name']);
            if ($stores->isNotEmpty()) {
                $parts[] = 'store'.($stores->count() > 1 ? 's' : '').' '.$stores->map(fn ($s) => "{$s->slug} ({$s->name})")->implode(', ');
            }

            $brands = Brand::query()->where('business_id', $business->id)->where('is_active', true)->orderBy('id')->get(['slug', 'name'])
                ->filter(fn ($b) => self::normalise((string) $b->name) !== self::normalise((string) $business->name));
            if ($brands->isNotEmpty()) {
                $parts[] = 'brand'.($brands->count() > 1 ? 's' : '').' '.$brands->map(fn ($b) => "{$b->name} ({$b->slug})")->implode(', ');
            }

            $lines[] = '- '.implode('; ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Lower-cased, accent-stripped, with every run of punctuation or space collapsed to one space,
     * so `bionic-garden`, `bionic.garden`, `Bionic Garden` and `bionic garden` are one key and
     * `Bôr dé Güna` is `bor de guna`.
     */
    public static function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
