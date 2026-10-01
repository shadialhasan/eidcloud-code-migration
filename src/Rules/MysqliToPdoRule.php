<?php

declare(strict_types=1);

namespace EidCloud\CodeMigration\Rules;

use EidCloud\CodeMigration\Ast\AstTransformer;
use EidCloud\CodeMigration\Ast\TokenStream;

/**
 * Migrates procedural mysqli_* calls to modern PDO syntax.
 * E.g.:
 * $db = mysqli_connect('host', 'user', 'pass', 'database');
 * => $db = new PDO('mysql:host=host;dbname=database', 'user', 'pass');
 *
 * $res = mysqli_query($db, $sql);
 * => $res = $db->query($sql);
 *
 * $row = mysqli_fetch_assoc($res);
 * => $row = $res->fetch(PDO::FETCH_ASSOC);
 *
 * mysqli_real_escape_string($db, $str);
 * => $db->quote($str);
 *
 * mysqli_insert_id($db);
 * => $db->lastInsertId();
 */
class MysqliToPdoRule implements RuleInterface
{
    public function getId(): string
    {
        return 'mysqli.to_pdo';
    }

    public function getDescription(): string
    {
        return 'Migrate legacy procedural mysqli_* functions to modern PDO object-oriented calls';
    }

    public function getPack(): string
    {
        return 'mysqli';
    }

    public function apply(AstTransformer $transformer): int
    {
        $stream = $transformer->getStream();
        $changes = 0;

        $i = 0;
        while ($i < $stream->count()) {
            $tok = $stream->get($i);
            if ($tok === null) {
                break;
            }

            if ($tok->id === T_STRING) {
                $fnName = strtolower($tok->text);

                // mysqli_connect(host, user, pass, db) -> new PDO("mysql:host=host;dbname=db", user, pass)
                if ($fnName === 'mysqli_connect') {
                    $res = $this->transformConnect($stream, $i);
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_query($db, $sql) -> $db->query($sql)
                if ($fnName === 'mysqli_query') {
                    $res = $this->transformQuery($stream, $i);
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_fetch_assoc($res) -> $res->fetch(PDO::FETCH_ASSOC)
                if ($fnName === 'mysqli_fetch_assoc') {
                    $res = $this->transformFetchAssoc($stream, $i);
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_fetch_array($res) -> $res->fetch(PDO::FETCH_BOTH)
                if ($fnName === 'mysqli_fetch_array') {
                    $res = $this->transformSimpleMethodCall($stream, $i, 'fetch', 'PDO::FETCH_BOTH');
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_real_escape_string($db, $str) or mysqli_escape_string -> $db->quote($str)
                if ($fnName === 'mysqli_real_escape_string' || $fnName === 'mysqli_escape_string') {
                    $res = $this->transformEscapeString($stream, $i);
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_insert_id($db) -> $db->lastInsertId()
                if ($fnName === 'mysqli_insert_id') {
                    $res = $this->transformZeroArgMethod($stream, $i, 'lastInsertId');
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }

                // mysqli_close($db) -> $db = null; or remove
                if ($fnName === 'mysqli_close') {
                    $res = $this->transformClose($stream, $i);
                    if ($res !== null) {
                        $changes++;
                        $i = $res;
                        continue;
                    }
                }
            }

            $i++;
        }

        return $changes;
    }

    protected function getFunctionArguments(TokenStream $stream, int $fnIndex): ?array
    {
        $openParen = $stream->nextMeaningfulIndex($fnIndex);
        if ($openParen === null || $stream->get($openParen)->text !== '(') {
            return null;
        }

        $closeParen = $stream->findMatchingPair($openParen, '(', ')');
        if ($closeParen === null) {
            return null;
        }

        // Split arguments by top-level commas
        $args = [];
        $currentArgTokens = [];
        $depth = 0;

        for ($k = $openParen + 1; $k < $closeParen; $k++) {
            $tok = $stream->get($k);
            $text = $tok->text;
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $currentArgTokens[] = $tok;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                $currentArgTokens[] = $tok;
            } elseif ($text === ',' && $depth === 0) {
                $args[] = trim(implode('', array_map(fn($t) => $t->text, $currentArgTokens)));
                $currentArgTokens = [];
            } else {
                $currentArgTokens[] = $tok;
            }
        }

        if (!empty($currentArgTokens)) {
            $args[] = trim(implode('', array_map(fn($t) => $t->text, $currentArgTokens)));
        }

        return [
            'open' => $openParen,
            'close' => $closeParen,
            'args' => $args
        ];
    }

    protected function transformConnect(TokenStream $stream, int $fnIndex): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 3) {
            return null;
        }

        $host = $info['args'][0] ?? "'localhost'";
        $user = $info['args'][1] ?? "''";
        $pass = $info['args'][2] ?? "''";
        $db = $info['args'][3] ?? null;

        // strip literal quotes if static string to form dsn cleanly, or concatenate
        $dsnExpr = '';
        if ($db !== null) {
            $dsnExpr = "\"mysql:host=\" . {$host} . \";dbname=\" . {$db}";
        } else {
            $dsnExpr = "\"mysql:host=\" . {$host}";
        }

        $replacement = "new \\PDO({$dsnExpr}, {$user}, {$pass}, [\\PDO::ATTR_ERRMODE => \\PDO::ERRMODE_EXCEPTION])";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformQuery(TokenStream $stream, int $fnIndex): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 2) {
            return null;
        }

        $dbVar = $info['args'][0];
        $sqlArg = $info['args'][1];

        $replacement = "{$dbVar}->query({$sqlArg})";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformFetchAssoc(TokenStream $stream, int $fnIndex): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 1) {
            return null;
        }

        $resVar = $info['args'][0];
        $replacement = "{$resVar}->fetch(\\PDO::FETCH_ASSOC)";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformSimpleMethodCall(TokenStream $stream, int $fnIndex, string $methodName, string $defaultParam = ''): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 1) {
            return null;
        }

        $targetVar = $info['args'][0];
        $paramStr = $defaultParam !== '' ? $defaultParam : '';
        $replacement = "{$targetVar}->{$methodName}({$paramStr})";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformEscapeString(TokenStream $stream, int $fnIndex): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 2) {
            return null;
        }

        $dbVar = $info['args'][0];
        $strVar = $info['args'][1];

        $replacement = "{$dbVar}->quote({$strVar})";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformZeroArgMethod(TokenStream $stream, int $fnIndex, string $methodName): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 1) {
            return null;
        }

        $dbVar = $info['args'][0];
        $replacement = "{$dbVar}->{$methodName}()";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }

    protected function transformClose(TokenStream $stream, int $fnIndex): ?int
    {
        $info = $this->getFunctionArguments($stream, $fnIndex);
        if ($info === null || count($info['args']) < 1) {
            return null;
        }

        $dbVar = $info['args'][0];
        $replacement = "{$dbVar} = null";
        $stream->replaceRange($fnIndex, $info['close'], $replacement);
        return $fnIndex + 1;
    }
}
