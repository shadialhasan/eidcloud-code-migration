<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Diff;

/**
 * Unified Diff Generator with line numbers and color formatting support.
 */
class DiffFormatter
{
    /**
     * Generates a standard unified diff between original and modified strings.
     */
    public static function createUnifiedDiff(
        string $original,
        string $modified,
        string $filePath = 'target.php',
        int $context = 3
    ): string {
        if ($original === $modified) {
            return '';
        }

        $origLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $original));
        $modLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $modified));

        $matrix = self::computeLcsMatrix($origLines, $modLines);
        $diffOps = self::buildDiffOps($matrix, $origLines, $modLines);

        $hunks = self::buildHunks($diffOps, $context);
        if (empty($hunks)) {
            return '';
        }

        $output = "--- a/{$filePath}\n";
        $output .= "+++ b/{$filePath}\n";

        foreach ($hunks as $hunk) {
            $output .= sprintf(
                "@@ -%d,%d +%d,%d @@\n",
                $hunk['old_start'],
                $hunk['old_count'],
                $hunk['new_start'],
                $hunk['new_count']
            );
            foreach ($hunk['lines'] as $line) {
                $output .= $line . "\n";
            }
        }

        return $output;
    }

    /**
     * Colorizes a unified diff for terminal display.
     */
    public static function colorize(string $diff): string
    {
        $lines = explode("\n", $diff);
        $colored = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                $colored[] = "\033[1;37m" . $line . "\033[0m"; // bold white
            } elseif (str_starts_with($line, '@@')) {
                $colored[] = "\033[36m" . $line . "\033[0m"; // cyan
            } elseif (str_starts_with($line, '+')) {
                $colored[] = "\033[32m" . $line . "\033[0m"; // green
            } elseif (str_starts_with($line, '-')) {
                $colored[] = "\033[31m" . $line . "\033[0m"; // red
            } else {
                $colored[] = $line;
            }
        }

        return implode("\n", $colored);
    }

    protected static function computeLcsMatrix(array $a, array $b): array
    {
        $m = count($a);
        $n = count($b);
        $matrix = [];

        for ($i = 0; $i <= $m; $i++) {
            $matrix[$i][0] = 0;
        }
        for ($j = 0; $j <= $n; $j++) {
            $matrix[0][$j] = 0;
        }

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $matrix[$i][$j] = $matrix[$i - 1][$j - 1] + 1;
                } else {
                    $matrix[$i][$j] = max($matrix[$i - 1][$j], $matrix[$i][$j - 1]);
                }
            }
        }

        return $matrix;
    }

    protected static function buildDiffOps(array $matrix, array $a, array $b): array
    {
        $i = count($a);
        $j = count($b);
        $ops = [];

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $ops[] = ['op' => ' ', 'line' => $a[$i - 1], 'old_ln' => $i, 'new_ln' => $j];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $matrix[$i][$j - 1] >= $matrix[$i - 1][$j])) {
                $ops[] = ['op' => '+', 'line' => $b[$j - 1], 'old_ln' => null, 'new_ln' => $j];
                $j--;
            } elseif ($i > 0 && ($j === 0 || $matrix[$i][$j - 1] < $matrix[$i - 1][$j])) {
                $ops[] = ['op' => '-', 'line' => $a[$i - 1], 'old_ln' => $i, 'new_ln' => null];
                $i--;
            }
        }

        return array_reverse($ops);
    }

    protected static function buildHunks(array $ops, int $context): array
    {
        $hunks = [];
        $count = count($ops);
        $i = 0;

        while ($i < $count) {
            // Find change
            if ($ops[$i]['op'] === ' ') {
                $i++;
                continue;
            }

            // Found a change at $i. Expand context backwards
            $hunkStart = max(0, $i - $context);

            // Expand forward until no changes within 2*context
            $hunkEnd = $i;
            while ($hunkEnd < $count) {
                if ($ops[$hunkEnd]['op'] !== ' ') {
                    $lastChange = $hunkEnd;
                    $hunkEnd = min($count - 1, $lastChange + $context);
                } else {
                    $hunkEnd++;
                }
                if ($hunkEnd - ($lastChange ?? $i) > $context) {
                    break;
                }
            }

            // Extract hunk lines
            $hunkLines = [];
            $oldStart = null;
            $oldCount = 0;
            $newStart = null;
            $newCount = 0;

            for ($k = $hunkStart; $k <= min($count - 1, $hunkEnd); $k++) {
                $op = $ops[$k];
                $hunkLines[] = $op['op'] . $op['line'];

                if ($op['op'] === ' ') {
                    if ($oldStart === null) $oldStart = $op['old_ln'];
                    if ($newStart === null) $newStart = $op['new_ln'];
                    $oldCount++;
                    $newCount++;
                } elseif ($op['op'] === '-') {
                    if ($oldStart === null) $oldStart = $op['old_ln'];
                    $oldCount++;
                } elseif ($op['op'] === '+') {
                    if ($newStart === null) $newStart = $op['new_ln'];
                    $newCount++;
                }
            }

            $hunks[] = [
                'old_start' => $oldStart ?? 1,
                'old_count' => $oldCount,
                'new_start' => $newStart ?? 1,
                'new_count' => $newCount,
                'lines' => $hunkLines,
            ];

            $i = $hunkEnd + 1;
        }

        return $hunks;
    }
}
