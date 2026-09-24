<?php

namespace App\Services;

use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extracts plain text from uploaded files and splits it into overlapping chunks.
 */
class DocumentProcessor
{
    public const SUPPORTED_EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    public function extractText(string $fullPath, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => (new PdfParser)->parseFile($fullPath)->getText(),
            'docx' => $this->extractDocx($fullPath),
            'txt', 'md', 'csv' => file_get_contents($fullPath),
            default => throw new InvalidArgumentException("Unsupported file type: {$extension}"),
        };

        // Normalise encoding and whitespace.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function extractDocx(string $path): string
    {
        $lines = [];

        // One line per top-level element (paragraph, heading, table row) keeps paragraph breaks for chunking.
        foreach (IOFactory::load($path)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectText($element, $lines);
            }
        }

        return implode("\n\n", array_filter($lines, fn ($l) => trim($l) !== ''));
    }

    /** Recursively walks PhpWord elements (paragraphs, tables, lists) collecting their text. */
    private function collectText(object $element, array &$lines): void
    {
        if ($element instanceof Text) {
            $lines[] = $element->getText();

            return;
        }

        if ($element instanceof TextBreak) {
            $lines[] = '';

            return;
        }

        if ($element instanceof AbstractContainer) {
            $buffer = [];
            foreach ($element->getElements() as $child) {
                $this->collectText($child, $buffer);
            }
            $lines[] = implode(' ', array_filter($buffer, fn ($l) => $l !== ''));

            return;
        }

        // Tables: rows -> cells -> containers.
        if (method_exists($element, 'getRows')) {
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $buffer = [];
                    $this->collectText($cell, $buffer);
                    $cells[] = trim(implode(' ', $buffer));
                }
                $lines[] = implode(' | ', $cells);
            }

            return;
        }

        // List items wrap a Text object.
        if (method_exists($element, 'getTextObject')) {
            $this->collectText($element->getTextObject(), $lines);

            return;
        }

        // Titles/headings return either a string or a TextRun.
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_string($text)) {
                $lines[] = $text;
            } elseif (is_object($text)) {
                $this->collectText($text, $lines);
            }
        }
    }

    /**
     * Split text into ~$size character chunks with $overlap characters shared between neighbours,
     * preferring to cut at paragraph or sentence boundaries.
     *
     * @return string[]
     */
    public function chunk(string $text, int $size = 1500, int $overlap = 200): array
    {
        $length = mb_strlen($text);
        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = min($start + $size, $length);

            if ($end < $length) {
                $slice = mb_substr($text, $start, $size);
                $break = mb_strrpos($slice, "\n\n");
                if ($break === false || $break < $size * 0.5) {
                    $break = mb_strrpos($slice, '. ');
                }
                if ($break !== false && $break >= $size * 0.5) {
                    $end = $start + $break + 1;
                }
            }

            $chunk = trim(mb_substr($text, $start, $end - $start));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            if ($end >= $length) {
                break;
            }

            $start = max($end - $overlap, $start + 1);
        }

        return $chunks;
    }
}
