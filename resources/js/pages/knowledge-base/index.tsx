import { Head, Link } from '@inertiajs/react';
import { CarFront, ChevronRight } from 'lucide-react';
import { PageBody, PageHeader } from '@/components/organization/page-header';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    index as knowledgeBaseIndex,
    marks as marksIndex,
} from '@/routes/knowledge-base';

const SECTIONS = [
    {
        title: 'Каталог автомобилей',
        description:
            'Марки, модели, поколения, технические характеристики и комплектации',
        href: marksIndex(),
        icon: CarFront,
    },
];

export default function KnowledgeBaseIndex() {
    return (
        <>
            <Head title="База знаний" />
            <PageBody>
                <PageHeader
                    title="База знаний"
                    description="Справочные материалы для работы с клиентами"
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {SECTIONS.map((section) => (
                        <Link
                            key={section.title}
                            href={section.href}
                            className="group/section rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <Card className="h-full transition-colors group-hover/section:bg-muted/50">
                                <CardHeader className="flex flex-row items-center gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                        <section.icon className="size-5" />
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <CardTitle>{section.title}</CardTitle>
                                        <CardDescription>
                                            {section.description}
                                        </CardDescription>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                </CardHeader>
                            </Card>
                        </Link>
                    ))}
                </div>
            </PageBody>
        </>
    );
}

KnowledgeBaseIndex.layout = {
    breadcrumbs: [{ title: 'База знаний', href: knowledgeBaseIndex() }],
};
