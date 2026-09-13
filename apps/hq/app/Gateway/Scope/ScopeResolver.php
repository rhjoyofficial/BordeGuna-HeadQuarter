<?php

namespace App\Gateway\Scope;

use App\Gateway\Descriptor\ToolDescriptor;
use App\Gateway\Descriptor\ToolScope;
use App\Gateway\ToolRequest;
use App\Models\Business;
use App\Models\Store;

/**
 * Works out which business and store a call targets.
 *
 * Two sources, in this order:
 *   1. the request's own context (`businessId`, `storeId`), which a UI caller sets;
 *   2. the argument path the tool declared in `scopeFrom`, which is how the scheduled jobs and the
 *      console have always named their target.
 *
 * A store implies its business, so naming `store_slug: laladia-com` resolves the business too. That
 * matters for the business-scope check and for the credential account reference.
 *
 * Nothing here is free text from a model: `scopeFrom` is a fixed path declared by the tool's own
 * class, and the value at that path is a reference looked up against a real row. An unknown
 * reference resolves to nothing and the call is refused, rather than silently widening to "all
 * businesses".
 *
 * ## The value is a reference, not only a slug (docs/67, ADR-039)
 *
 * ⭐ Gate 4 refused `erp.read_snapshot` twelve times for naming no business, and the refusal said
 * "neither the call nor its arguments named one" without saying WHICH argument or what it takes.
 * Two changes. The value at the declared path is resolved by `BusinessReferenceResolver`, so a
 * store slug, a brand name or a display name lands on the business it means instead of on
 * "which HQ does not have". And every refusal names the argument and lists the values it accepts,
 * so a caller told no is told what yes looks like. Resolving a name is not authorisation: whether
 * the caller may act on the business it named is still `ToolGateway::checkScope()`'s decision.
 */
final class ScopeResolver
{
    public function __construct(private readonly BusinessReferenceResolver $references) {}

    public function resolve(ToolDescriptor $descriptor, ToolRequest $request, array $args): ResolvedScope
    {
        $storeId = $request->storeId;
        $storeSlug = null;
        $businessId = $request->businessId;
        $businessSlug = null;

        // 1. Anything the request already carries.
        if ($storeId !== null) {
            $store = Store::find($storeId);
            if ($store === null) {
                return ResolvedScope::missing("The call names store {$storeId}, which does not exist.");
            }
            $storeSlug = $store->slug;
            $businessId ??= $store->business_id;
        }
        if ($businessId !== null) {
            $business = Business::find($businessId);
            if ($business === null) {
                return ResolvedScope::missing("The call names business {$businessId}, which does not exist.");
            }
            $businessSlug = $business->slug;
        }

        // 2. The declared argument path, for the parts the request did not carry.
        $paths = $this->declaredPaths($descriptor);

        foreach ($paths as $kind => $path) {
            $value = data_get($args, $path);
            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($kind === 'store' && $storeId === null) {
                $store = $this->references->store($value);
                if ($store === null) {
                    return ResolvedScope::missing(
                        "[{$descriptor->key}] was given store [{$value}], which HQ does not have. "
                        ."[{$path}] takes one of: ".$this->list($this->references->activeStoreSlugs()).'.',
                    );
                }
                $storeId = $store->id;
                $storeSlug = $store->slug;
                if ($businessId === null) {
                    $businessId = $store->business_id;
                    $businessSlug = Business::find($businessId)?->slug;
                }
            }

            if ($kind === 'business' && $businessId === null) {
                $business = $this->references->business($value);
                if ($business === null) {
                    return ResolvedScope::missing(
                        "[{$descriptor->key}] was given business [{$value}], which HQ does not have. "
                        ."[{$path}] takes one of: ".$this->list($this->references->activeBusinessSlugs()).'.',
                    );
                }
                $businessId = $business->id;
                $businessSlug = $business->slug;
            }
        }

        // 3. A scoped tool must have ended up with a target, and a refusal says which argument
        //    would have supplied one and what it accepts.
        if ($descriptor->requiresBusinessContext() && $businessId === null && $storeId === null) {
            return ResolvedScope::missing(
                "[{$descriptor->key}] is scoped to a {$descriptor->scope->value} and neither the call nor its arguments named one."
                .$this->howToName($paths),
            );
        }

        return new ResolvedScope($businessId, $businessSlug, $storeId, $storeSlug);
    }

    /** The sentence that turns "you named none" into "name one like this". */
    private function howToName(array $paths): string
    {
        if (isset($paths['business'])) {
            return " Pass [{$paths['business']}] with one of: ".$this->list($this->references->activeBusinessSlugs()).'.';
        }
        if (isset($paths['store'])) {
            return " Pass [{$paths['store']}] with one of: ".$this->list($this->references->activeStoreSlugs()).'.';
        }

        return ' The call must carry a business or store context.';
    }

    /** @param  list<string>  $values */
    private function list(array $values): string
    {
        return $values === [] ? '(none is active)' : implode(', ', $values);
    }

    /**
     * `scopeFrom` accepts one or two declarations, for example
     * `store:args.store_slug` or `business:args.business_slug`.
     *
     * Public because the agent catalogue reads it too: the argument a tool resolves its scope from
     * is the argument the catalogue marks required and enumerates (docs/67).
     *
     * @return array<string, string> kind => argument path
     */
    public function declaredPaths(ToolDescriptor $descriptor): array
    {
        if ($descriptor->scopeFrom === null || $descriptor->scopeFrom === '') {
            return [];
        }

        $paths = [];
        foreach (explode('|', $descriptor->scopeFrom) as $clause) {
            $clause = trim($clause);
            if (! str_contains($clause, ':')) {
                continue;
            }
            [$kind, $path] = explode(':', $clause, 2);
            $kind = trim($kind);
            if (in_array($kind, ['business', 'store'], true) && str_starts_with($path, 'args.')) {
                $paths[$kind] = substr(trim($path), 5);
            }
        }

        // A store is resolved first when both are declared, because a store implies its business and
        // the reverse is not true.
        return array_filter([
            'store' => $paths['store'] ?? null,
            'business' => $paths['business'] ?? null,
        ], static fn (?string $v) => $v !== null && $v !== '');
    }

    /** True when the descriptor's scope means a target is required at all. */
    public function requiresTarget(ToolDescriptor $descriptor): bool
    {
        return $descriptor->scope === ToolScope::Business || $descriptor->scope === ToolScope::Store;
    }
}
