import { CircleAlert, FileIcon, Paperclip, X } from 'lucide-react';
import { useEffect, useRef } from 'react';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@/components/reui/alert';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    useFileUpload,
    type FileWithPreview,
} from '@/hooks/use-file-upload';
import { cn } from '@/lib/utils';
import type { SupportUploadLimits } from '@/types/support';

const MIME_BY_TYPE: Record<string, string> = {
    pdf: 'application/pdf',
    txt: 'text/plain',
    doc: 'application/msword',
    docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};

/** The browser filter: whole image/video families plus the listed document types. */
function acceptFor(types: string[]): string {
    const documents = types
        .filter((type) => MIME_BY_TYPE[type])
        .flatMap((type) => [`.${type}`, MIME_BY_TYPE[type]]);

    return ['image/*', 'video/*', ...documents].join(',');
}

function selectedFiles(items: FileWithPreview[]): File[] {
    return items
        .map((item) => item.file)
        .filter((file): file is File => file instanceof File);
}

type Props = {
    limits: SupportUploadLimits;
    disabled?: boolean;
    invalid?: boolean;
    onFilesChange: (files: File[]) => void;
};

export function SupportFileUpload({
    limits,
    disabled,
    invalid,
    onFilesChange,
}: Props) {
    const [
        { files, isDragging, errors },
        {
            removeFile,
            handleDragEnter,
            handleDragLeave,
            handleDragOver,
            handleDrop,
            openFileDialog,
            getInputProps,
        },
    ] = useFileUpload({
        maxFiles: limits.max_files,
        accept: acceptFor(limits.types),
        multiple: true,
    });

    const report = useRef(onFilesChange);

    useEffect(() => {
        report.current = onFilesChange;
    });

    useEffect(() => {
        report.current(selectedFiles(files));
    }, [files]);

    return (
        <div className="flex w-full flex-col gap-3">
            <div
                className={cn(
                    'flex items-center gap-3 rounded-lg border border-dashed p-4 transition-colors',
                    isDragging
                        ? 'border-primary bg-primary/5'
                        : 'border-muted-foreground/25 hover:border-muted-foreground/50',
                    invalid && 'border-destructive',
                )}
                onDragEnter={handleDragEnter}
                onDragLeave={handleDragLeave}
                onDragOver={handleDragOver}
                onDrop={handleDrop}
            >
                <input
                    {...getInputProps({ disabled })}
                    className="sr-only"
                    aria-label="Прикрепить файл"
                />

                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={disabled}
                    onClick={openFileDialog}
                >
                    <Paperclip />
                    Добавить файлы
                </Button>

                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                    {files.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Перетащите файлы сюда или нажмите кнопку (не больше{' '}
                            {limits.max_files}): фото, видео, PDF, TXT, Word
                        </p>
                    ) : (
                        files.map((item) => {
                            const title = `${item.file.name} (${formatBytes(item.file.size)})`;

                            return (
                                <div
                                    key={item.id}
                                    className="group/item relative shrink-0"
                                >
                                    {item.file.type.startsWith('image/') &&
                                    item.preview ? (
                                        <img
                                            src={item.preview}
                                            alt={item.file.name}
                                            title={title}
                                            className="size-12 rounded-lg border object-cover"
                                        />
                                    ) : (
                                        <div
                                            title={title}
                                            className="flex size-12 items-center justify-center rounded-lg border bg-muted"
                                        >
                                            <FileIcon className="size-5 text-muted-foreground" />
                                        </div>
                                    )}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        disabled={disabled}
                                        aria-label={`Убрать ${item.file.name}`}
                                        onClick={() => removeFile(item.id)}
                                        className="absolute -end-2 -top-2 size-5 rounded-full opacity-0 shadow-md transition-opacity group-hover/item:opacity-100 focus-visible:opacity-100"
                                    >
                                        <X className="size-3" />
                                    </Button>
                                </div>
                            );
                        })
                    )}
                </div>

                {files.length > 0 && (
                    <div className="shrink-0 text-xs text-muted-foreground">
                        {files.length}/{limits.max_files}
                    </div>
                )}
            </div>

            {errors.length > 0 && (
                <Alert variant="destructive">
                    <CircleAlert />
                    <AlertTitle>Не удалось добавить файлы</AlertTitle>
                    <AlertDescription>
                        {errors.map((error, index) => (
                            <p key={index}>{error}</p>
                        ))}
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
