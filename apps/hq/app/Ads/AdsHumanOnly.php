<?php

namespace App\Ads;

/**
 * What HQ must never have a tool for, in paid media (ADR-050 decision 2 and clarifications 2, 3 and 6,
 * accepted 22 September 2026, `docs/88` A point 4).
 *
 * ⛔ **Not a list of tools that are switched off. A list of tools that do not exist.** Each entry is
 * a decision about the business, not a stage: money, legal identity, ownership and deletion stay with
 * people at every autonomy level. `AdsHumanOnlyTest` reads every tool class in the application and
 * every registered tool and fails if any of them does one of these things.
 *
 * Each key is the operation; each value names the words a tool for it would carry, used by the test
 * to recognise one, and the reason.
 */
final class AdsHumanOnly
{
    /** @var array<string, array{terms: list<string>, why: string}> */
    public const OPERATIONS = [
        'payment_method' => [
            'terms' => ['payment_method', 'payment method', 'funding_source', 'funding source'],
            'why' => 'Adding, changing or removing how the business pays Meta is moving money (docs/60 2.11).',
        ],
        'billing' => [
            'terms' => ['billing', 'invoice', 'pay_bill', 'pay the bill', 'prepay', 'add_funds', 'add funds'],
            'why' => 'Paying, prepaying or disputing a bill is moving money.',
        ],
        'legal_identity' => [
            'terms' => ['advertiser', 'payer', 'legal_identity', 'legal identity', 'beneficiary'],
            'why' => 'The Advertiser and Payer fields are the business\'s legal identity; only the owner fills them.',
        ],
        'account_lifecycle' => [
            'terms' => ['create_ad_account', 'close_ad_account', 'delete_ad_account', 'close account', 'delete account'],
            'why' => 'Creating or closing an ad account is the owner\'s.',
        ],
        'people_and_access' => [
            'terms' => ['system_user', 'assign_user', 'remove_user', 'add_person', 'remove_person', 'partner_access', 'business_user'],
            'why' => 'Granting or removing people, partners or system users changes who can spend.',
        ],
        'ownership' => [
            'terms' => ['ownership', 'transfer_account', 'transfer account', 'owner_business'],
            'why' => 'Changing who owns an ad account is the owner\'s.',
        ],
        'spending_ceiling' => [
            'terms' => ['spend_cap', 'spending_limit', 'spending limit', 'account_limit', 'set_limit'],
            'why' => 'The ad account spending limit is the owner\'s outer ceiling; HQ reads it and never writes it (clarification 3).',
        ],
        'deletion' => [
            'terms' => ['delete_campaign', 'delete_adset', 'delete_ad', 'delete_creative', 'delete campaign'],
            'why' => 'Deleting an ad object destroys the history reconciliation and attribution need; people archive in Ads Manager.',
        ],
    ];
}
