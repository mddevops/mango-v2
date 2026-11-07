import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { cn, toUrl } from '@/lib/utils';
import { index as orderReasonsIndex } from '@/routes/order-reasons';
import { index as orderStatusesIndex } from '@/routes/order-statuses';
import { index as paymentMethodsIndex } from '@/routes/payment-methods';
import { index as sourceReferencesIndex } from '@/routes/source-references';
import { index as visitResultsIndex } from '@/routes/visit-results';
import type { NavItem } from '@/types';

type NavGroup = { title: string; items: NavItem[] };

export default function ReferencesLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { allows } = usePermissions();

    const groups: NavGroup[] = [
        ...(allows('viewOrderReferences')
            ? [
                  {
                      title: 'Заявки',
                      items: [
                          {
                              title: 'Статусы заявок',
                              href: orderStatusesIndex(),
                          },
                          {
                              title: 'Причины корзины',
                              href: orderReasonsIndex('trash'),
                          },
                          {
                              title: 'Причины отказа / срыва',
                              href: orderReasonsIndex('drop'),
                          },
                          {
                              title: 'Способы оплаты',
                              href: paymentMethodsIndex(),
                          },
                          {
                              title: 'Итоги визита',
                              href: visitResultsIndex(),
                          },
                      ],
                  },
              ]
            : []),
        ...(allows('viewSourceReferences')
            ? [
                  {
                      title: 'Источники',
                      items: [
                          {
                              title: 'Виды источников',
                              href: sourceReferencesIndex('kinds'),
                          },
                          {
                              title: 'Классификации',
                              href: sourceReferencesIndex('classifications'),
                          },
                          {
                              title: 'Каналы обращения',
                              href: sourceReferencesIndex('channels'),
                          },
                          {
                              title: 'Агентства',
                              href: sourceReferencesIndex('agencies'),
                          },
                      ],
                  },
              ]
            : []),
    ];

    return (
        <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col lg:flex-row">
            <aside className="w-full px-4 pt-4 lg:w-56 lg:shrink-0 lg:pr-0">
                <nav className="flex flex-col gap-4" aria-label="Справочники">
                    {groups.map((group) => (
                        <div key={group.title} className="flex flex-col gap-1">
                            <span className="px-2 text-xs font-medium text-muted-foreground">
                                {group.title}
                            </span>
                            {group.items.map((item) => (
                                <Button
                                    key={toUrl(item.href)}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': isCurrentOrParentUrl(
                                            item.href,
                                        ),
                                    })}
                                >
                                    <Link href={item.href} prefetch>
                                        {item.title}
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    ))}
                </nav>
                <Separator className="mt-4 lg:hidden" />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">{children}</div>
        </div>
    );
}
