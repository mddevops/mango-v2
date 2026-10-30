import { createInertiaApp } from '@inertiajs/react';
import { FlashToasts } from '@/components/flash-toasts';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import AuthLoginLayout from '@/layouts/auth/auth-login-layout';
import ReferencesLayout from '@/layouts/references/layout';
import SettingsLayout from '@/layouts/settings/layout';
import { configureEcho } from '@laravel/echo-react';

// Without a Reverb key (a local setup without realtime) the pages work without signals.
if (typeof window !== 'undefined' && import.meta.env.VITE_REVERB_APP_KEY) {
    configureEcho({
        broadcaster: 'reverb',
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'error':
                return null;
            case name === 'auth/login':
                return AuthLoginLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            case name.startsWith('order-references/'):
            case name.startsWith('source-references/'):
                return [AppLayout, ReferencesLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
                <FlashToasts />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
