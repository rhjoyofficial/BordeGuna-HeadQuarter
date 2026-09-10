<?php

/*
 | Inertia, published for ONE reason: the package's default page path is wrong for this app, and it
 | is wrong in the way that only shows up on Linux (M5, 10 Sep 2026).
 |
 | ⚠⚠ The package defaults to `resource_path('js/Pages')` with a capital P. This app's pages live in
 | `resources/js/pages`, lower case. Windows is case-insensitive, so
 | `assertInertia(...)->component('agents/index')` resolves locally and passes; CI is Linux and fails
 | with "Inertia page component file does not exist" for a file that plainly exists and is committed.
 |
 | Until now the workaround was to pass `false` as the second argument to `component()`, turning the
 | check off. That works, and it means the only way to use the assertion is to disable it - so a
 | controller rendering a misspelled component name passes its own test and the mistake surfaces as a
 | blank screen in a browser. Naming the real path makes the check work instead of optional.
 |
 | ⚠ Every other value here is copied verbatim from the package default, deliberately. Publishing a
 | config makes every key in it authoritative, so a key changed "while we are here" is a production
 | behaviour change nobody asked for - `ssr.enabled` in particular, which this app has never set and
 | which production has always run on the package's own default.
 */
return [

    'ssr' => [

        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

    ],

    // Package default. A missing component already fails loudly in the browser, and checking the
    // filesystem on every render is a cost paid on every request to catch what a test should catch.
    'ensure_pages_exist' => false,

    'page_paths' => [

        // THE FIX. Lower case, because that is what the directory is called.
        resource_path('js/pages'),

    ],

    'page_extensions' => [

        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',

    ],

    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', false),

    'testing' => [

        // ⭐ True in tests, where the cost is nothing and the mistake is cheap to fix.
        'ensure_pages_exist' => true,

        'page_paths' => [

            resource_path('js/pages'),

        ],

        'page_extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
