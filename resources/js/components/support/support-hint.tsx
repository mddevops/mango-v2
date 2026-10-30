import { MessageSquareText } from 'lucide-react';
import { useState } from 'react';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import { Frame, FramePanel } from '@/components/reui/frame';
import { Button } from '@/components/ui/button';

const STORAGE_KEY = 'support.hint.hidden';

function isHidden(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/** How to describe a problem. Once closed, it stays closed in this browser. */
export function SupportHint() {
    const [hidden, setHidden] = useState(isHidden);

    if (hidden) {
        return null;
    }

    const close = () => {
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // Storage may be unavailable; the hint then just hides until reload.
        }

        setHidden(true);
    };

    return (
        <Frame>
            <FramePanel className="overflow-hidden p-0!">
                <Alert className="border-0 shadow-none">
                    <MessageSquareText className="text-success" />
                    <AlertTitle>Как описать проблему</AlertTitle>
                    <AlertAction>
                        <Button
                            type="button"
                            variant="outline"
                            size="xs"
                            onClick={close}
                        >
                            Закрыть
                        </Button>
                    </AlertAction>
                    <AlertDescription>
                        <p>
                            Если Ваш вопрос технического плана, то желательно
                            предоставить более подробную информацию о проблеме,
                            при каких обстоятельствах проявляются ошибки. Если это
                            ошибки в работе CRM/Чата/Склада, то опишите,
                            пожалуйста, какие действия совершались до ее
                            возникновения, а также приложите, по возможности,
                            скриншот ошибки.
                        </p>
                        <p>
                            Данная информация значительно ускорит время ответа и
                            избавит от лишних вопросов.
                        </p>
                    </AlertDescription>
                </Alert>
            </FramePanel>
        </Frame>
    );
}
