import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { initializeLocale } from './lib/i18n';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        // docs/90 A.6: the page's language is the person's saved preference, then the browser's
        // language, then English, resolved once here from the first page's shared props.
        const auth = (props.initialPage.props as { auth?: { user?: { locale?: unknown } } }).auth;
        initializeLocale(auth?.user?.locale);

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        // docs/135 (d): HQ violet, visible on both themes.
        color: '#7C6CF6',
    },
});

// This will set light / dark mode on load...
initializeTheme();

