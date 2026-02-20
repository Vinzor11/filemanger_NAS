import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import type { FileRow } from '@/components/file-manager/file-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type FilePreviewModalProps = {
    file: FileRow | null;
    open: boolean;
    onOpenChange: (nextOpen: boolean) => void;
};

type PreviewMode =
    | 'image'
    | 'pdf'
    | 'video'
    | 'audio'
    | 'docx'
    | 'text'
    | 'unsupported';

const IMAGE_EXTENSIONS = new Set([
    'png',
    'jpg',
    'jpeg',
    'gif',
    'webp',
    'bmp',
    'svg',
    'heic',
    'heif',
    'tif',
    'tiff',
]);
const VIDEO_EXTENSIONS = new Set([
    'mp4',
    'mov',
    'avi',
    'mkv',
    'webm',
    'wmv',
    'm4v',
    'mpeg',
    'mpg',
]);
const AUDIO_EXTENSIONS = new Set([
    'mp3',
    'wav',
    'ogg',
    'flac',
    'aac',
    'm4a',
    'wma',
]);
const TEXT_EXTENSIONS = new Set([
    'txt',
    'md',
    'csv',
    'tsv',
    'json',
    'xml',
    'yaml',
    'yml',
    'log',
    'ini',
    'env',
    'js',
    'jsx',
    'ts',
    'tsx',
    'css',
    'scss',
    'html',
    'php',
    'py',
    'java',
    'c',
    'cpp',
    'h',
    'hpp',
    'go',
    'rs',
    'rb',
    'sh',
    'sql',
]);
const DOCX_MIME =
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const MAX_TEXT_PREVIEW_BYTES = 2 * 1024 * 1024;
const MAX_TEXT_PREVIEW_CHARACTERS = 200_000;

type MammothLikeModule = {
    convertToHtml: (input: { arrayBuffer: ArrayBuffer }) => Promise<{
        value: string;
    }>;
};

function normalizedExtension(file: FileRow): string {
    const explicitExtension = (file.extension ?? '').trim().toLowerCase();
    if (explicitExtension) {
        return explicitExtension;
    }

    return file.original_name.split('.').pop()?.trim().toLowerCase() ?? '';
}

function detectPreviewMode(file: FileRow): PreviewMode {
    const extension = normalizedExtension(file);
    const mimeType = (file.mime_type ?? '').toLowerCase();

    if (mimeType.startsWith('image/') || IMAGE_EXTENSIONS.has(extension)) {
        return 'image';
    }

    if (mimeType === 'application/pdf' || extension === 'pdf') {
        return 'pdf';
    }

    if (mimeType.startsWith('video/') || VIDEO_EXTENSIONS.has(extension)) {
        return 'video';
    }

    if (mimeType.startsWith('audio/') || AUDIO_EXTENSIONS.has(extension)) {
        return 'audio';
    }

    if (mimeType === DOCX_MIME || extension === 'docx') {
        return 'docx';
    }

    if (
        mimeType.startsWith('text/') ||
        mimeType.includes('json') ||
        mimeType.includes('xml') ||
        mimeType.includes('javascript') ||
        mimeType.includes('typescript') ||
        mimeType.includes('csv') ||
        TEXT_EXTENSIONS.has(extension)
    ) {
        return 'text';
    }

    return 'unsupported';
}

function sanitizePreviewHtml(html: string): string {
    const parser = new DOMParser();
    const document = parser.parseFromString(html, 'text/html');
    document
        .querySelectorAll('script,style,iframe,object,embed,link,meta')
        .forEach((element) => element.remove());
    document.querySelectorAll('*').forEach((element) => {
        for (const attribute of Array.from(element.attributes)) {
            const lowerName = attribute.name.toLowerCase();
            const lowerValue = attribute.value.trim().toLowerCase();
            if (lowerName.startsWith('on')) {
                element.removeAttribute(attribute.name);
                continue;
            }
            if (
                (lowerName === 'href' || lowerName === 'src') &&
                lowerValue.startsWith('javascript:')
            ) {
                element.removeAttribute(attribute.name);
            }
        }
    });

    return document.body.innerHTML;
}

function mimeLabel(file: FileRow): string {
    const mimeType = (file.mime_type ?? '').trim();
    if (mimeType) {
        return mimeType;
    }

    const extension = normalizedExtension(file);
    if (extension) {
        return `${extension.toUpperCase()} file`;
    }

    return 'File';
}

export function FilePreviewModal({
    file,
    open,
    onOpenChange,
}: FilePreviewModalProps) {
    const [isLoading, setIsLoading] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [docxHtml, setDocxHtml] = useState<string | null>(null);
    const [textContent, setTextContent] = useState<string | null>(null);

    const previewMode = useMemo(
        () => (file ? detectPreviewMode(file) : 'unsupported'),
        [file],
    );
    const previewUrl = useMemo(
        () => (file ? `/files/${file.public_id}/preview` : ''),
        [file],
    );
    const downloadUrl = useMemo(
        () => (file ? `/files/${file.public_id}/download` : '#'),
        [file],
    );
    const extensionLabel = useMemo(() => {
        if (!file) {
            return 'FILE';
        }

        const extension = (
            file.extension?.trim() || normalizedExtension(file)
        ).toUpperCase();

        return extension || 'FILE';
    }, [file]);

    useEffect(() => {
        if (!open || !file) {
            setIsLoading(false);
            setPreviewError(null);
            setDocxHtml(null);
            setTextContent(null);

            return;
        }

        setPreviewError(null);
        setDocxHtml(null);
        setTextContent(null);

        if (previewMode === 'unsupported') {
            setIsLoading(false);

            return;
        }

        if (previewMode === 'text' && file.size_bytes > MAX_TEXT_PREVIEW_BYTES) {
            setIsLoading(false);
            setPreviewError(
                'Text preview is limited to files up to 2 MB. Download this file to view it.',
            );

            return;
        }

        if (previewMode !== 'docx' && previewMode !== 'text') {
            setIsLoading(false);

            return;
        }

        let isMounted = true;
        const abortController = new AbortController();

        const loadPreviewContent = async () => {
            setIsLoading(true);

            try {
                const response = await fetch(previewUrl, {
                    headers: {
                        Accept: '*/*',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: abortController.signal,
                });

                if (!response.ok) {
                    throw new Error(
                        `Request failed (${response.status}). Unable to load preview.`,
                    );
                }

                if (previewMode === 'docx') {
                    const arrayBuffer = await response.arrayBuffer();
                    const loadedMammoth = (await import(
                        'mammoth'
                    )) as unknown as MammothLikeModule & {
                        default?: MammothLikeModule;
                    };
                    const mammothModule =
                        loadedMammoth.default ?? loadedMammoth;
                    const result = await mammothModule.convertToHtml({
                        arrayBuffer,
                    });
                    if (!isMounted) {
                        return;
                    }
                    setDocxHtml(sanitizePreviewHtml(result.value));
                    return;
                }

                const rawText = await response.text();
                if (!isMounted) {
                    return;
                }

                const clipped =
                    rawText.length > MAX_TEXT_PREVIEW_CHARACTERS
                        ? `${rawText.slice(0, MAX_TEXT_PREVIEW_CHARACTERS)}\n\n... (preview truncated)`
                        : rawText;
                setTextContent(clipped);
            } catch (error) {
                if (!isMounted || abortController.signal.aborted) {
                    return;
                }

                setPreviewError(
                    error instanceof Error
                        ? error.message
                        : 'Unable to load preview.',
                );
            } finally {
                if (isMounted) {
                    setIsLoading(false);
                }
            }
        };

        void loadPreviewContent();

        return () => {
            isMounted = false;
            abortController.abort();
        };
    }, [file, open, previewMode, previewUrl]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[94vh] overflow-hidden sm:max-w-6xl">
                <DialogHeader>
                    <DialogTitle>Preview</DialogTitle>
                    <DialogDescription className="truncate">
                        {file?.original_name ?? '-'}
                    </DialogDescription>
                </DialogHeader>

                <div className="rounded-lg border bg-muted/20">
                    <div className="flex items-center justify-between border-b px-4 py-2 text-xs text-muted-foreground">
                        <span>{file ? mimeLabel(file) : '-'}</span>
                        <span>{extensionLabel}</span>
                    </div>
                    <div className="max-h-[70vh] min-h-[50vh] overflow-auto p-4">
                        {isLoading ? (
                            <div className="flex h-full min-h-[45vh] items-center justify-center text-muted-foreground">
                                <Loader2 className="mr-2 size-4 animate-spin" />
                                Loading preview...
                            </div>
                        ) : previewError ? (
                            <div className="flex h-full min-h-[45vh] items-center justify-center px-4 text-center text-sm text-warning">
                                {previewError}
                            </div>
                        ) : previewMode === 'image' ? (
                            <img
                                src={previewUrl}
                                alt={file?.original_name ?? 'File preview'}
                                className="mx-auto h-auto max-h-[66vh] w-auto max-w-full rounded-md object-contain"
                            />
                        ) : previewMode === 'pdf' ? (
                            <iframe
                                title={file?.original_name ?? 'PDF preview'}
                                src={previewUrl}
                                className="h-[66vh] w-full rounded-md border bg-background"
                            />
                        ) : previewMode === 'video' ? (
                            <video
                                className="mx-auto h-auto max-h-[66vh] w-full rounded-md bg-black"
                                controls
                                preload="metadata"
                                src={previewUrl}
                            />
                        ) : previewMode === 'audio' ? (
                            <div className="flex h-full min-h-[45vh] items-center justify-center">
                                <audio
                                    controls
                                    preload="metadata"
                                    src={previewUrl}
                                    className="w-full max-w-2xl"
                                />
                            </div>
                        ) : previewMode === 'docx' && docxHtml ? (
                            <article
                                className="[&_a]:text-primary [&_a]:underline [&_h1]:mb-3 [&_h1]:text-2xl [&_h1]:font-semibold [&_h2]:mb-3 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mb-2 [&_h3]:text-lg [&_h3]:font-medium [&_li]:list-disc [&_li]:ml-5 [&_ol]:mb-3 [&_ol]:space-y-1 [&_p]:mb-3 [&_table]:mb-4 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:bg-muted [&_th]:px-2 [&_th]:py-1 [&_ul]:mb-3 [&_ul]:space-y-1 text-sm text-foreground"
                                dangerouslySetInnerHTML={{ __html: docxHtml }}
                            />
                        ) : previewMode === 'text' && textContent !== null ? (
                            <pre className="overflow-auto whitespace-pre-wrap break-words rounded-md border bg-background p-4 font-mono text-xs text-foreground">
                                {textContent}
                            </pre>
                        ) : previewMode === 'unsupported' ? (
                            <div className="flex h-full min-h-[45vh] items-center justify-center px-4 text-center text-sm text-muted-foreground">
                                Preview is not available for this file type.
                            </div>
                        ) : (
                            <div className="flex h-full min-h-[45vh] items-center justify-center px-4 text-center text-sm text-muted-foreground">
                                Preview content is not available.
                            </div>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" asChild>
                        <a href={downloadUrl}>Download</a>
                    </Button>
                    <Button type="button" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
