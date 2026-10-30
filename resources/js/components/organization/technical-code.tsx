import { ChevronDown } from 'lucide-react';
import FormField from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';

type Props = {
    id: string;
    code: string;
};

export function TechnicalCode({ id, code }: Props) {
    return (
        <Collapsible className="flex flex-col gap-3">
            <CollapsibleTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="group self-start text-muted-foreground"
                >
                    Дополнительно
                    <ChevronDown className="transition-transform group-data-[state=open]:rotate-180" />
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <FormField
                    label="Технический код"
                    description="Технический код создан автоматически и используется для интеграций"
                >
                    <Input
                        id={id}
                        value={code}
                        readOnly
                        className="font-mono"
                    />
                </FormField>
            </CollapsibleContent>
        </Collapsible>
    );
}
