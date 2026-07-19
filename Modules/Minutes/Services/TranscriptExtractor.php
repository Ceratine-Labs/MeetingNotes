<?php

namespace Modules\Minutes\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Turns an upload into plain text. Supported: .txt .md .docx .pdf.
 * Scanned/image PDFs (near-zero extractable text) are rejected with a
 * clear message — OCR is deliberately out of scope for v1.
 */
class TranscriptExtractor
{
    public const SUPPORTED = ['txt', 'md', 'docx', 'pdf'];

    /**
     * @return array{text: string, mime: ?string}
     *
     * @throws ExtractionException
     */
    public function extract(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $text = match ($extension) {
            'txt', 'md' => (string) file_get_contents($file->getRealPath()),
            'docx' => $this->fromDocx($file->getRealPath()),
            'pdf' => $this->fromPdf($file->getRealPath()),
            default => throw new ExtractionException(
                "Unsupported file type .{$extension} — supported: " . implode(', ', self::SUPPORTED)
            ),
        };

        $text = $this->normalize($text);

        if (mb_strlen($text) < 40) {
            throw new ExtractionException(
                $extension === 'pdf'
                    ? 'This PDF has no extractable text — it is likely scanned. OCR is not supported yet; paste the text instead.'
                    : 'The file contains no usable text.'
            );
        }

        return ['text' => $text, 'mime' => $file->getClientMimeType()];
    }

    protected function fromDocx(string $path): string
    {
        try {
            $document = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new ExtractionException('Could not read the .docx file: ' . $e->getMessage());
        }

        $parts = [];

        foreach ($document->getSections() as $section) {
            $this->collectText($section->getElements(), $parts);
        }

        return implode("\n", $parts);
    }

    protected function collectText(array $elements, array &$parts): void
    {
        foreach ($elements as $element) {
            if (method_exists($element, 'getElements')) {
                $line = [];
                $this->collectInline($element->getElements(), $line);

                if ($line !== []) {
                    $parts[] = implode('', $line);
                }
            } elseif (method_exists($element, 'getText') && is_string($element->getText())) {
                $parts[] = $element->getText();
            }
        }
    }

    protected function collectInline(array $elements, array &$line): void
    {
        foreach ($elements as $element) {
            if (method_exists($element, 'getText') && is_string($element->getText())) {
                $line[] = $element->getText();
            } elseif (method_exists($element, 'getElements')) {
                $this->collectInline($element->getElements(), $line);
            }
        }
    }

    protected function fromPdf(string $path): string
    {
        try {
            return (new PdfParser())->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new ExtractionException('Could not read the PDF: ' . $e->getMessage());
        }
    }

    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = preg_replace('/\n{4,}/', "\n\n\n", $text);

        return trim($text);
    }
}
