import { Link, router } from '@inertiajs/react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { CatalogLink } from '@/types/knowledge-base';

/** Modification switch plus the «Характеристики» / «Комплектации» pair of the modification. */
export function ModificationNav({
    current,
    modifications,
    specificationsHref,
    equipmentHref,
    active,
}: {
    current: string;
    modifications: CatalogLink[];
    specificationsHref: string;
    equipmentHref: string | null;
    active: 'specifications' | 'equipment';
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <Tabs value={active}>
                <TabsList aria-label="Раздел">
                    <TabsTrigger value="specifications" asChild>
                        <Link href={specificationsHref}>Характеристики</Link>
                    </TabsTrigger>
                    {equipmentHref ? (
                        <TabsTrigger value="equipment" asChild>
                            <Link href={equipmentHref}>Комплектации</Link>
                        </TabsTrigger>
                    ) : (
                        <TabsTrigger value="equipment" disabled>
                            Комплектаций нет
                        </TabsTrigger>
                    )}
                </TabsList>
            </Tabs>
            {modifications.length > 1 && (
                <Select
                    value={current}
                    onValueChange={(href) => router.visit(href)}
                >
                    <SelectTrigger
                        className="w-full sm:w-72"
                        aria-label="Модификация"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {modifications.map((modification) => (
                            <SelectItem
                                key={modification.href}
                                value={modification.href}
                            >
                                {modification.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}
