import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Оформление" />

            <h1 className="sr-only">Оформление</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Оформление"
                    description="Настройте внешний вид приложения"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Оформление',
            href: editAppearance(),
        },
    ],
};
