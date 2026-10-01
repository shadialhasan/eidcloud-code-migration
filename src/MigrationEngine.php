<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Diff\DiffFormatter;
use EidCloud\CodeMigration\Rules\RuleInterface;
use EidCloud\CodeMigration\Rules\RuleRegistry;

class MigrationPlan
{
    /**
     * @param array<string, array{
     *    original: string,
     *    transformed: string,
     *    diff: string,
     *    changes_count: int,
     *    rules_applied: array<string>,
     *    syntax_valid: bool,
     *    syntax_error?: string
     * }> $filePlans
     */
    public function __construct(
        public array $filePlans = [],
        public int $totalChanges = 0,
        public int $totalFilesAffected = 0
    ) {
    }
}

class MigrationEngine
{
    protected RuleRegistry $registry;

    public function __construct(?RuleRegistry $registry = null)
    {
        $this->registry = $registry ?? RuleRegistry::createDefault();
    }

    public function getRegistry(): RuleRegistry
    {
        return $this->registry;
    }

    /**
     * Scans and prepares a migration plan for a target file or directory.
     *
     * @param string $path
     * @param string $ruleSelector
     * @return MigrationPlan
     */
    public function plan(string $path, string $ruleSelector = 'all'): MigrationPlan
    {
        $rules = $this->registry->resolve($ruleSelector);
        $files = $this->findPhpFiles($path);

        $filePlans = [];
        $totalChanges = 0;
        $totalFiles = 0;

        foreach ($files as $file) {
            $original = (string)file_get_contents($file);
            $transformer = new AstTransformer($original);

            $fileChanges = 0;
            $appliedRules = [];

            foreach ($rules as $rule) {
                $count = $rule->apply($transformer);
                if ($count > 0) {
                    $fileChanges += $count;
                    $appliedRules[] = $rule->getId();
                }
            }

            if ($fileChanges > 0) {
                $transformed = $transformer->getSource();
                $diff = DiffFormatter::createUnifiedDiff($original, $transformed, $file);

                $validation = $this->validateSyntax($transformed);

                $filePlans[$file] = [
                    'original' => $original,
                    'transformed' => $transformed,
                    'diff' => $diff,
                    'changes_count' => $fileChanges,
                    'rules_applied' => $appliedRules,
                    'syntax_valid' => $validation['valid'],
                    'syntax_error' => $validation['error'] ?? null,
                ];

                $totalChanges += $fileChanges;
                $totalFiles++;
            }
        }

        return new MigrationPlan($filePlans, $totalChanges, $totalFiles);
    }

    /**
     * Applies the migration plan directly to files, with optional backup creation (.bak).
     *
     * @param MigrationPlan $plan
     * @param bool $backup
     * @return array{files_migrated: int, total_changes: int, backups: array<string>}
     */
    public function applyPlan(MigrationPlan $plan, bool $backup = false): array
    {
        $migrated = 0;
        $backups = [];

        foreach ($plan->filePlans as $filePath => $details) {
            // Refuse to apply if syntax validation failed
            if (!$details['syntax_valid']) {
                throw new \RuntimeException("Syntax validation failed for {$filePath}: " . ($details['syntax_error'] ?? 'Unknown error'));
            }

            if ($backup) {
                $bakPath = $filePath . '.bak';
                file_put_contents($bakPath, $details['original']);
                $backups[] = $bakPath;
            }

            file_put_contents($filePath, $details['transformed']);
            $migrated++;
        }

        return [
            'files_migrated' => $migrated,
            'total_changes' => $plan->totalChanges,
            'backups' => $backups
        ];
    }

    /**
     * Helper to directly migrate source code string.
     */
    public function migrateSource(string $source, string $ruleSelector = 'all'): array
    {
        $rules = $this->registry->resolve($ruleSelector);
        $transformer = new AstTransformer($source);
        $changes = 0;
        $applied = [];

        foreach ($rules as $rule) {
            $c = $rule->apply($transformer);
            if ($c > 0) {
                $changes += $c;
                $applied[] = $rule->getId();
            }
        }

        $transformed = $transformer->getSource();
        $diff = DiffFormatter::createUnifiedDiff($source, $transformed, 'source.php');
        $validation = $this->validateSyntax($transformed);

        return [
            'original' => $source,
            'transformed' => $transformed,
            'diff' => $diff,
            'changes_count' => $changes,
            'rules_applied' => $applied,
            'syntax_valid' => $validation['valid'],
            'syntax_error' => $validation['error'] ?? null,
        ];
    }

    /**
     * Validates syntax using php -l in a temporary context.
     *
     * @return array{valid: bool, error: ?string}
     */
    public function validateSyntax(string $phpCode): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'eid_syn_') . '.php';
        file_put_contents($tmpFile, $phpCode);

        $escaped = escapeshellarg($tmpFile);
        $output = [];
        $exitCode = 0;
        exec("php -l {$escaped} 2>&1", $output, $exitCode);

        @unlink($tmpFile);

        $outStr = implode("\n", $output);
        if ($exitCode === 0 && str_contains($outStr, 'No syntax errors detected')) {
            return ['valid' => true, 'error' => null];
        }

        return ['valid' => false, 'error' => $outStr];
    }

    /**
     * @return array<string>
     */
    protected function findPhpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $filePath = $item->getRealPath();
                // Ignore vendor, tests/fixtures, .git
                if (!str_contains($filePath, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) &&
                    !str_contains($filePath, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
                    $files[] = $filePath;
                }
            }
        }

        sort($files);
        return $files;
    }
}
