<?php

/*
 | Seed data for the domain model (ADR-010). This is configuration, not code: adding a business,
 | brand, store or platform account is an edit here (or later, a screen), never a hardcode.
 | Only what exists TODAY (verified 5 Sep 2026) is listed. No future businesses are invented.
 |
 | ERP business ids mirror the Bor De Guna ERP `businesses` table. Econic is deliberately NOT an ERP business
 | (Joy, Q7, 5 Sep 2026): it exists here as an HQ-only business and store, erp_business_id null, and its brand
 | code ECN is HQ-only marketing identity with no ERP counterpart. Azmion stays IT-services only.
 */
return [
    'businesses' => [
        ['slug' => 'bor-de-guna', 'name' => 'Bor de Guna', 'erp_business_id' => 4],
        ['slug' => 'laladia', 'name' => 'LaLaDia', 'erp_business_id' => 1],
        ['slug' => 'bionic', 'name' => 'Bionic', 'erp_business_id' => 2],
        ['slug' => 'azmion', 'name' => 'Azmion', 'erp_business_id' => 3],
        ['slug' => 'econic', 'name' => 'Econic', 'erp_business_id' => null],
    ],

    // code => ERP brand code where one exists (LLD, BNC). Marketing-only brands carry their own code.
    'brands' => [
        ['code' => 'LLD', 'slug' => 'laladia', 'name' => 'LaLaDia', 'business' => 'laladia'],
        ['code' => 'BNC', 'slug' => 'bionic-garden', 'name' => 'Bionic Garden', 'business' => 'bionic'],
        ['code' => 'AZM', 'slug' => 'azmion', 'name' => 'Azmion', 'business' => 'azmion'],
        ['code' => 'ECN', 'slug' => 'econic', 'name' => 'Econic', 'business' => 'econic'],
        ['code' => 'BDG', 'slug' => 'bor-de-guna', 'name' => 'Bor de Guna', 'business' => 'bor-de-guna'],
    ],

    // adapter names match the Commerce Platform Interface adapters (doc 15). No adapter is built in Phase 0.
    'stores' => [
        ['slug' => 'laladia-com', 'name' => 'laladia.com', 'business' => 'laladia', 'adapter' => 'laladia', 'base_url' => 'https://laladia.com', 'product_owners' => ['bor-de-guna']],
        ['slug' => 'bionic-garden', 'name' => 'bionic.garden', 'business' => 'bionic', 'adapter' => 'bionic', 'base_url' => 'https://bionic.garden', 'product_owners' => ['bor-de-guna']],
        ['slug' => 'econic-store', 'name' => 'econic.store', 'business' => 'econic', 'adapter' => 'woocommerce', 'base_url' => 'https://econic.store', 'product_owners' => ['econic']],
        ['slug' => 'erp-counter', 'name' => 'ERP counter / POS', 'business' => 'bor-de-guna', 'adapter' => 'erp_pos', 'base_url' => 'https://erp.srv1808022.hstgr.cloud', 'product_owners' => ['bor-de-guna']],
    ],

    'channels' => [
        ['key' => 'web', 'name' => 'Website'],
        ['key' => 'landing', 'name' => 'Landing page'],
        ['key' => 'messenger', 'name' => 'Facebook Messenger'],
        ['key' => 'instagram', 'name' => 'Instagram'],
        ['key' => 'whatsapp', 'name' => 'WhatsApp'],
        ['key' => 'phone', 'name' => 'Phone'],
        ['key' => 'pos', 'name' => 'Counter / POS'],
    ],

    // Known external accounts, recorded WITHOUT credentials. approval_status and capabilities stay
    // unknown until an adapter verifies them (doc 18: never assume capabilities).
    'platform_accounts' => [
        ['brand' => 'LLD', 'platform' => 'facebook_page', 'account_ref' => '100085015853889', 'name' => 'La La Dia'],
        ['brand' => 'LLD', 'platform' => 'meta_pixel', 'account_ref' => '1260982165256142', 'name' => 'LaLaDia Web Pixel 01'],
        ['brand' => 'LLD', 'platform' => 'gtm', 'account_ref' => 'GTM-5W9MN2VS', 'name' => 'laladia.com container'],
        ['brand' => 'BNC', 'platform' => 'facebook_page', 'account_ref' => '321936947659712', 'name' => 'Bionic Garden'],
        ['brand' => 'BNC', 'platform' => 'meta_pixel', 'account_ref' => '1773890070472625', 'name' => 'Bionic pixel'],
        ['brand' => 'BNC', 'platform' => 'gtm', 'account_ref' => 'GTM-5DH52W8X', 'name' => 'bionic.garden container'],
        ['brand' => 'BDG', 'platform' => 'meta_ad_account', 'account_ref' => '28164410319885213', 'name' => 'BordeGuna Ads (BDT)'],
    ],
];
