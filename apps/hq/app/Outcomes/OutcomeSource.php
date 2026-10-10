<?php

namespace App\Outcomes;

/** How HQ learned that an outcome happened (docs/107 section 4.1). */
enum OutcomeSource: string
{
    /** The ERP's answer read back after an HQ write or a status read. */
    case ErpReadBack = 'erp_read_back';

    /** A shop's signed webhook. */
    case ShopWebhook = 'shop_webhook';

    /** The reconciliation pass that catches lost webhooks. */
    case ShopReconcile = 'shop_reconcile';

    /** HQ's own tables: a conversation, a verified publication. */
    case HqInternal = 'hq_internal';

    /** A person recorded it by hand, with a reason. */
    case Operator = 'operator';

    /** Read from an external platform (Meta, a courier). */
    case PlatformRead = 'platform_read';
}
