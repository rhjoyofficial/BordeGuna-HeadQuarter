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
 * class, and the value at that path is a slug looked up against a real row. An unknown slug resolves
 * to nothing and the call is refused, rather than silently widening to "all businesses".
 */
final class ScopeResolver
{
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
        foreach ($this->declaredPaths($descriptor) as $kind => $path) {
            $value = data_get($args, $path);
            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($kind === 'store' && $storeId === null) {
                $store = Store::where('slug', $value)->first();
                if ($store === null) {
                    return ResolvedScope::missing("[{$descriptor->key}] was given store [{$value}], which HQ does not have.");
                }
                $storeId = $store->id;
                $storeSlug = $store->slug;
                if ($businessId === null) {
                    $businessId = $store->business_id;
                    $businessSlug = Business::find($businessId)?->slug;
                }
            }

            if ($kind === 'business' && $businessId === null) {
                $business = Business::where('slug', $value)->first();
                if ($business === null) {
                    return ResolvedScope::missing("[{$descriptor->key}] was given business [{$value}], which HQ does not have.");
                }
                $businessId = $business->id;
                $businessSlug = $business->slug;
            }
        }

        // 3. A scoped tool must have ended up with a target.
        if ($descriptor->requiresBusinessContext() && $businessId === null && $storeId === null) {
            return ResolvedScope::missing(
                "[{$descriptor->key}] is scoped to a {$descriptor->scope->value} and neither the call nor its arguments named one.",
            );
        }

        return new ResolvedScope($businessId, $businessSlug, $storeId, $storeSlug);
    }

    /**
     * `scopeFrom` accepts one or two declarations, for example
     * `store:args.store_slug` or `business:args.business_slug`.
     *
     * @return array<string, string> kind => argument path
     */
    private function declaredPaths(ToolDescriptor $descriptor): array
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
