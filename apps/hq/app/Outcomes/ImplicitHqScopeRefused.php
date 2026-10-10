<?php

namespace App\Outcomes;

use InvalidArgumentException;

/**
 * An attribution named an action that has no business of its own (an HQ-wide tool call or model call, a
 * decision record, whose table has no business column), and nothing declared that HQ scope explicitly.
 *
 * ⛔ Ruling of 10 Oct 2026 (docs/110 §3 Q6, docs/115 §3): implicit null business scope is refused, fail
 *    closed. Crediting a business-scoped outcome to an action with no provable business would let any
 *    HQ-wide action be credited with any business's result. When a recorder genuinely needs to credit an
 *    HQ-scope action, the credit must DECLARE that scope and the declaration is validated against a
 *    reviewed allow-list (docs/115 §3.3); no v1 recorder needs it, so that representation is not built.
 *
 * An `InvalidArgumentException`, so every fail-closed contract of the recorder (nothing written, the
 * caller's existing `catch`) holds unchanged; the type exists so a caller can tell this refusal apart.
 */
final class ImplicitHqScopeRefused extends InvalidArgumentException {}
