<?php

/*
 | Seed data for the domain model (ADR-010). This is configuration, not code: adding a business,
 | brand, store or platform account is an edit here (or later, a screen), never a hardcode.
 | Only what exists TODAY is listed (businesses, brands, stores, channels verified 5 Sep 2026; the Meta
 | platform accounts re-verified 3 Oct 2026, see below). No future businesses are invented.
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
        ['slug' => 'erp-counter', 'name' => 'ERP counter / POS', 'business' => 'bor-de-guna', 'adapter' => 'erp_pos', 'base_url' => 'https://erp.bordeguna.com', 'product_owners' => ['bor-de-guna']],
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

    /*
     | Known external accounts, recorded WITHOUT credentials. approval_status and capabilities stay
     | unknown until an adapter verifies them (doc 18: never assume capabilities), and the seeder sets
     | them only when it creates a row, never on a re-run.
     |
     | Meta ids corrected 3 October 2026 (docs/95 §5 "Operations"; source: Joy's Meta move of 21 Sep 2026,
     | memory `project_meta_bdg_portfolio` and `project_meta_capi_live`). Everything for both shops lives
     | in the Bor de Guna portfolio `3967588683468735`: LaLaDia dataset `1260982165256142` (renamed
     | "LaLaDia | Website + CAPI"), Bionic dataset `689321244257515` ("Bionic | Website + CAPI", the one
     | Bionic dataset, decided), ad accounts LaLaDia `530866839523958` and Bionic `547593411643381`
     | (both USD). The two rows below marked `retired` were the 5 Sep values and are kept, inactive, so a
     | production row is retired rather than orphaned: Bionic's old pixel `1773890070472625` (replaced by
     | the dataset above on 21 Sep) and the BDT ad account `28164410319885213`, which sits in the End
     | Brackets portfolio and is out of scope since 22 Sep (`feedback_meta_bdg_only_focus`).
     |
     | `instagram_account` and `whatsapp_number` rows are placeholders: Meta's Instagram account id and the
     | WhatsApp phone_number_id are not known yet, so each account_ref comes from an env value that
     | defaults to null, and the seeder creates NO row while it is null. Fill `HQ_SEED_*` in deploy/.env
     | and re-seed; no code change.
     */
    'platform_accounts' => [
        ['brand' => 'LLD', 'platform' => 'facebook_page', 'account_ref' => '100085015853889', 'name' => 'La La Dia'],
        ['brand' => 'LLD', 'platform' => 'meta_pixel', 'account_ref' => '1260982165256142', 'name' => 'LaLaDia | Website + CAPI'],
        ['brand' => 'LLD', 'platform' => 'meta_ad_account', 'account_ref' => '530866839523958', 'name' => 'La La Dia (USD)'],
        ['brand' => 'LLD', 'platform' => 'gtm', 'account_ref' => 'GTM-5W9MN2VS', 'name' => 'laladia.com container'],
        ['brand' => 'LLD', 'platform' => 'instagram_account', 'account_ref' => env('HQ_SEED_LALADIA_INSTAGRAM_ACCOUNT_ID'), 'name' => 'laladiaglobal'],
        ['brand' => 'LLD', 'platform' => 'whatsapp_number', 'account_ref' => env('HQ_SEED_LALADIA_WHATSAPP_PHONE_NUMBER_ID'), 'name' => 'LaLaDia WhatsApp +880 1334-943783'],
        ['brand' => 'BNC', 'platform' => 'facebook_page', 'account_ref' => '321936947659712', 'name' => 'Bionic Garden'],
        ['brand' => 'BNC', 'platform' => 'meta_pixel', 'account_ref' => '689321244257515', 'name' => 'Bionic | Website + CAPI'],
        ['brand' => 'BNC', 'platform' => 'meta_ad_account', 'account_ref' => '547593411643381', 'name' => 'Bionic (USD)'],
        ['brand' => 'BNC', 'platform' => 'gtm', 'account_ref' => 'GTM-5DH52W8X', 'name' => 'bionic.garden container'],
        ['brand' => 'BNC', 'platform' => 'instagram_account', 'account_ref' => env('HQ_SEED_BIONIC_INSTAGRAM_ACCOUNT_ID'), 'name' => 'bionicgardenofficial'],
        ['brand' => 'BNC', 'platform' => 'whatsapp_number', 'account_ref' => env('HQ_SEED_BIONIC_WHATSAPP_PHONE_NUMBER_ID'), 'name' => 'Bionic WhatsApp +880 1334-943785'],
        // Retired 3 Oct 2026 (see above). Kept so existing production rows are deactivated, never deleted.
        ['brand' => 'BNC', 'platform' => 'meta_pixel', 'account_ref' => '1773890070472625', 'name' => 'Bionic pixel (retired 3 Oct 2026, replaced by 689321244257515)', 'retired' => true],
        ['brand' => 'BDG', 'platform' => 'meta_ad_account', 'account_ref' => '28164410319885213', 'name' => 'BordeGuna Ads (BDT) (retired 3 Oct 2026, End Brackets portfolio, out of scope)', 'retired' => true],
    ],
];
