<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Tests;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;
use EidCloud\CodeMigration\Diff\DiffFormatter;
use EidCloud\CodeMigration\MigrationEngine;
use EidCloud\CodeMigration\Rules\ArrowFunctionsRule;
use EidCloud\CodeMigration\Rules\MatchExpressionRule;
use EidCloud\CodeMigration\Rules\MysqliToPdoRule;
use EidCloud\CodeMigration\Rules\NullsafeOperatorRule;
use EidCloud\CodeMigration\Rules\RuleRegistry;
use EidCloud\CodeMigration\Rules\TypedPropertiesRule;

class CodeMigrationTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool
    {
        $this->testAstTokenStream();
        $this->testNullsafeOperatorRule();
        $this->testTypedPropertiesRule();
        $this->testMatchExpressionRule();
        $this->testMysqliToPdoRule();
        $this->testArrowFunctionsRule();
        $this->testDiffFormatter();
        $this->testRuleRegistry();
        $this->testMigrationEnginePlanAndApply();
        $this->testSyntaxValidation();

        echo "\n\033[1;37m" . str_repeat('=', 50) . "\033[0m\n";
        echo "Test Results: \033[32m{$this->passed} passed\033[0m, ";
        if ($this->failed > 0) {
            echo "\033[31m{$this->failed} failed\033[0m\n";
            return false;
        } else {
            echo "\033[32m0 failed\033[0m (100% PASS)\n";
            return true;
        }
    }

    private function assert(bool $condition, string $testName, string $details = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔\033[0m {$testName}\n";
        } else {
            $this->failed++;
            echo "  \033[31m✖\033[0m {$testName}\n";
            if ($details !== '') {
                echo "    \033[33mDetail: {$details}\033[0m\n";
            }
        }
    }

    private function testAstTokenStream(): void
    {
        echo "\n\033[1;34m[Testing AST Token Stream]\033[0m\n";
        $code = "<?php \$x = 10; // comment\n";
        $stream = TokenStream::fromSource($code);

        $this->assert($stream->count() > 0, "Token stream parses tokens from source");
        $this->assert($stream->render() === $code, "Token stream faithfully reconstructs exact original source");

        // Test next/prev meaningful index
        $idx0 = 0; // <?php
        $idxVar = $stream->nextMeaningfulIndex($idx0);
        $this->assert($idxVar !== null && $stream->get($idxVar)->text === '$x', "Identifies next meaningful token skipping whitespace");
    }

    private function testNullsafeOperatorRule(): void
    {
        echo "\n\033[1;34m[Testing NullsafeOperatorRule]\033[0m\n";
        $code = "<?php\n\$name = \$user !== null ? \$user->getName() : null;\n";
        $transformer = new AstTransformer($code);
        $rule = new NullsafeOperatorRule();
        $changes = $rule->apply($transformer);

        $this->assert($changes === 1, "Detects and transforms null-check ternary to nullsafe operator");
        $output = $transformer->getSource();
        $this->assert(str_contains($output, '$user?->getName()'), "Contains nullsafe operator ?->", $output);
    }

    private function testTypedPropertiesRule(): void
    {
        echo "\n\033[1;34m[Testing TypedPropertiesRule]\033[0m\n";
        $code = "<?php\nclass User {\n    /** @var string */\n    private \$name;\n}\n";
        $transformer = new AstTransformer($code);
        $rule = new TypedPropertiesRule();
        $changes = $rule->apply($transformer);

        $this->assert($changes === 1, "Migrates PHPDoc @var to typed property");
        $output = $transformer->getSource();
        $this->assert(str_contains($output, 'private string $name;'), "Contains typed property 'private string \$name;'", $output);
    }

    private function testMatchExpressionRule(): void
    {
        echo "\n\033[1;34m[Testing MatchExpressionRule]\033[0m\n";
        $code = "<?php\nswitch (\$role) {\n    case 'admin':\n        return 1;\n    case 'editor':\n        return 2;\n    default:\n        return 0;\n}\n";
        $transformer = new AstTransformer($code);
        $rule = new MatchExpressionRule();
        $changes = $rule->apply($transformer);

        $this->assert($changes === 1, "Migrates switch statement to match expression");
        $output = $transformer->getSource();
        $this->assert(str_contains($output, "return match (\$role) {"), "Contains 'return match (\$role) {'", $output);
        $this->assert(str_contains($output, "'admin' => 1,"), "Contains 'admin' => 1,", $output);
    }

    private function testMysqliToPdoRule(): void
    {
        echo "\n\033[1;34m[Testing MysqliToPdoRule]\033[0m\n";
        $code = "<?php\n\$db = mysqli_connect('localhost', 'root', 'secret', 'app');\n\$res = mysqli_query(\$db, 'SELECT * FROM users');\n\$row = mysqli_fetch_assoc(\$res);\n";
        $transformer = new AstTransformer($code);
        $rule = new MysqliToPdoRule();
        $changes = $rule->apply($transformer);

        $this->assert($changes === 3, "Migrates mysqli_connect, mysqli_query, and mysqli_fetch_assoc");
        $output = $transformer->getSource();
        $this->assert(str_contains($output, "new \\PDO("), "Generates PDO connection", $output);
        $this->assert(str_contains($output, "\$db->query("), "Migrates to \$db->query", $output);
        $this->assert(str_contains($output, "\$res->fetch(\\PDO::FETCH_ASSOC)"), "Migrates to \$res->fetch", $output);
    }

    private function testArrowFunctionsRule(): void
    {
        echo "\n\033[1;34m[Testing ArrowFunctionsRule]\033[0m\n";
        $code = "<?php\n\$multiplier = 3;\n\$double = function(\$x) use (\$multiplier) {\n    return \$x * \$multiplier;\n};\n";
        $transformer = new AstTransformer($code);
        $rule = new ArrowFunctionsRule();
        $changes = $rule->apply($transformer);

        $this->assert($changes === 1, "Migrates single-return closure to arrow function");
        $output = $transformer->getSource();
        $this->assert(str_contains($output, "fn(\$x) => \$x * \$multiplier"), "Contains arrow function syntax", $output);
    }

    private function testDiffFormatter(): void
    {
        echo "\n\033[1;34m[Testing DiffFormatter]\033[0m\n";
        $orig = "line 1\nline 2\nline 3\n";
        $mod = "line 1\nline 2 modified\nline 3\n";
        $diff = DiffFormatter::createUnifiedDiff($orig, $mod, 'test.php');

        $this->assert(str_contains($diff, "--- a/test.php"), "Generates standard diff header");
        $this->assert(str_contains($diff, "-line 2"), "Contains removed line");
        $this->assert(str_contains($diff, "+line 2 modified"), "Contains added line");

        $colored = DiffFormatter::colorize($diff);
        $this->assert(str_contains($colored, "\033[32m"), "Colorizes diff additions in green");
    }

    private function testRuleRegistry(): void
    {
        echo "\n\033[1;34m[Testing RuleRegistry]\033[0m\n";
        $registry = RuleRegistry::createDefault();
        $this->assert(count($registry->all()) >= 5, "Registry contains all built-in rules");

        $php84Rules = $registry->resolve('php84');
        $this->assert(count($php84Rules) >= 3, "Resolves php84 pack correctly");

        $mysqliRules = $registry->resolve('mysqli');
        $this->assert(count($mysqliRules) >= 1, "Resolves mysqli pack correctly");
    }

    private function testMigrationEnginePlanAndApply(): void
    {
        echo "\n\033[1;34m[Testing MigrationEngine Plan and Apply]\033[0m\n";
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_test_' . uniqid();
        mkdir($tmpDir);

        $testFile = $tmpDir . DIRECTORY_SEPARATOR . 'sample.php';
        $content = "<?php\n\$a = \$item !== null ? \$item->val() : null;\n";
        file_put_contents($testFile, $content);

        $engine = new MigrationEngine();
        $plan = $engine->plan($tmpDir, 'php84');

        $this->assert($plan->totalChanges === 1, "Engine plan finds 1 transformation");
        $this->assert($plan->totalFilesAffected === 1, "Engine plan targets 1 file");

        $res = $engine->applyPlan($plan, true);
        $this->assert($res['files_migrated'] === 1, "Engine apply updates file");
        $this->assert(file_exists($testFile . '.bak'), "Backup file created when requested");

        $updatedContent = file_get_contents($testFile);
        $this->assert(str_contains($updatedContent, '$item?->val()'), "File content properly migrated");

        // Clean up
        @unlink($testFile);
        @unlink($testFile . '.bak');
        @rmdir($tmpDir);
    }

    private function testSyntaxValidation(): void
    {
        echo "\n\033[1;34m[Testing Syntax Validation Check]\033[0m\n";
        $engine = new MigrationEngine();

        $validRes = $engine->validateSyntax("<?php echo 'valid';\n");
        $this->assert($validRes['valid'] === true, "Valid PHP passes syntax check");

        $invalidRes = $engine->validateSyntax("<?php echo 'broken' missing_semicolon");
        $this->assert($invalidRes['valid'] === false, "Invalid PHP fails syntax check");
    }
}
