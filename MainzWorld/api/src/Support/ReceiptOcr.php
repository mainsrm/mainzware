<?php
declare(strict_types=1);

namespace MainzWorld\Support;

use RuntimeException;

// Wraps the self-hosted `tesseract` binary — no external OCR API/service required.
// Install with `brew install tesseract` (mac) or `apt install tesseract-ocr` (Linux host).
final class ReceiptOcr
{
    public static function extractText(string $imagePath): string
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('Receipt file is missing on disk.');
        }

        $tesseractPath = trim((string) shell_exec('command -v tesseract'));
        if ($tesseractPath === '') {
            throw new RuntimeException('Tesseract OCR is not installed on this server.');
        }

        $outputBase = tempnam(sys_get_temp_dir(), 'receipt_ocr_');
        if ($outputBase === false) {
            throw new RuntimeException('Could not create a temp file for OCR output.');
        }
        unlink($outputBase); // tesseract appends ".txt" to this base path itself

        $command = sprintf(
            '%s %s %s 2>&1',
            escapeshellcmd($tesseractPath),
            escapeshellarg($imagePath),
            escapeshellarg($outputBase)
        );
        exec($command, $outputLines, $exitCode);

        $textFile = $outputBase . '.txt';
        if ($exitCode !== 0 || !is_file($textFile)) {
            throw new RuntimeException('OCR failed: ' . implode("\n", $outputLines));
        }

        $text = file_get_contents($textFile) ?: '';
        unlink($textFile);

        return $text;
    }
}
