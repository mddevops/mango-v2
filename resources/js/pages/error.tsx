import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, LogIn, LogOut, ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { dashboard, login, logout } from '@/routes';

const TITLES: Record<number, string> = {
    403: 'Нет доступа',
    404: 'Страница не найдена',
    419: 'Сессия устарела',
    500: 'Ошибка сервера',
    503: 'Сервис временно недоступен',
};

export default function ErrorPage({
    status,
    message,
}: {
    status: number;
    message?: string | null;
}) {
    const { auth } = usePage().props;
    const title = TITLES[status] ?? 'Ошибка';
    const canOpenWorkspace = (auth?.permissions ?? []).includes(
        'workspace.view',
    );

    const content = (
        <div className="mx-auto flex max-w-md flex-1 flex-col items-center justify-center gap-4 p-4 py-12 text-center">
            <Head title={title} />
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                <ShieldAlert className="size-6 text-muted-foreground" />
            </div>
            <div className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">Код {status}</p>
                <h1 className="text-xl font-semibold">{title}</h1>
                {message && (
                    <p className="text-sm text-muted-foreground">{message}</p>
                )}
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                {status === 419 && (
                    <Button
                        type="button"
                        onClick={() => window.location.reload()}
                    >
                        Обновить страницу
                    </Button>
                )}
                {status !== 419 && canOpenWorkspace && (
                    <Button variant="outline" asChild>
                        <Link href={dashboard()}>
                            <ArrowLeft />
                            На рабочее место
                        </Link>
                    </Button>
                )}
                {auth?.user ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => router.post(logout.url())}
                    >
                        <LogOut />
                        Выйти
                    </Button>
                ) : (
                    <Button variant="outline" asChild>
                        <Link href={login()}>
                            <LogIn />
                            Войти
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );

    if (!auth?.user) {
        return (
            <div className="flex min-h-svh flex-col bg-background">
                {content}
            </div>
        );
    }

    return (
        <AppLayout breadcrumbs={[{ title, href: dashboard() }]}>
            {content}
        </AppLayout>
    );
}
