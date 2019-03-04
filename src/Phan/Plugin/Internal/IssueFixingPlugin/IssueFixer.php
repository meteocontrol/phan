<?php declare(strict_types=1);

namespace Phan\Plugin\Internal\IssueFixingPlugin;

use Closure;
use Generator;
use Microsoft\PhpParser;
use Microsoft\PhpParser\FilePositionMap;
use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\TokenKind;
use Phan\AST\TolerantASTConverter\NodeUtils;
use Phan\Config;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Library\FileCache;
use Phan\Library\StringUtil;
use RuntimeException;

/**
 * Represents a set of changes to be made to file contents.
 * The structure of this will change.
 */
class IssueFixer
{

    /**
     * @return Generator<void,PhpParser\Node,void,void>
     */
    private static function getNodesAtLine(string $file_contents, PhpParser\Node $node, int $line)
    {
        $file_position_map = new FilePositionMap($file_contents);
        foreach ($node->getDescendantNodes() as $node) {
            $line_for_node = $file_position_map->getStartLine($node);
            if ($line_for_node < $line) {
                continue;
            }
            if ($line_for_node > $line) {
                return;
            }
            yield $node;
        }
    }

    private static function isMatchingNamespaceUseDeclaration(
        string $file_contents,
        NamespaceUseDeclaration $declaration,
        IssueInstance $issue_instance
    ) : bool {
        $type = $issue_instance->getIssue()->getType();

        switch ($type) {
            case Issue::UnreferencedUseNormal:
                $expected_token_kind = null;
                break;
            case Issue::UnreferencedUseFunction:
                $expected_token_kind = TokenKind::FunctionKeyword;
                break;
            case Issue::UnreferencedUseConstant:
                $expected_token_kind = TokenKind::ConstKeyword;
                break;
            default:
                self::debug(\sprintf("Unexpected kind %s in %s\n", $type, __METHOD__));
                return false;
        }

        $actual_token_kind = $declaration->functionOrConst->kind ?? null;
        if ($expected_token_kind !== $actual_token_kind) {
            self::debug(\sprintf("DEBUG: Unexpected type %s in %s\n", $actual_token_kind ?? 'null', __METHOD__));
            return false;
        }
        $list = $declaration->useClauses->children ?? [];
        if (\count($list) !== 1) {
            self::debug(\sprintf("DEBUG: Unexpected count %d in %s\n", \count($list), __METHOD__));
            return false;
        }
        $element = $list[0];
        // $dumper = new \Phan\AST\TolerantASTConverter\NodeDumper($file_contents);
        // $dumper->setIncludeTokenKind(true);
        // $dumper->dumpTree($element);
        if (!($element instanceof NamespaceUseClause)) {
            return false;
        }
        if ($element->openBrace || $element->groupClauses || $element->closeBrace) {
            // Not supported
            return false;
        }
        // $element->namespaceAliasingClause doesn't matter for the the subsequent checks

        $namespace_name = $element->namespaceName;
        if (!($namespace_name instanceof QualifiedName)) {
            return false;
        }
        $actual_use_name = (new NodeUtils($file_contents))->phpParserNameToString($namespace_name);
        // Get the last argument from
        // Possibly zero references to use statement for classlike/namespace {CLASSLIKE} ({CLASSLIKE})
        $expected_use_name = $issue_instance->getTemplateParameters()[1];

        if (\strcasecmp(\ltrim((string)$expected_use_name, "\\"), \ltrim($actual_use_name, "\\")) !== 0) {
            // Not the same fully qualified name.
            return false;
        }
        // This is the same fully qualified name.
        return true;
    }

    /**
     * @return ?FileEdit
     */
    private static function maybeRemoveNamespaceUseDeclaration(
        string $file_contents,
        NamespaceUseDeclaration $declaration,
        IssueInstance $issue_instance
    ) {
        if (!self::isMatchingNamespaceUseDeclaration($file_contents, $declaration, $issue_instance)) {
            return null;
        }

        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        $end = $declaration->getEndPosition();
        $end = self::skipTrailingWhitespaceAndNewlines($file_contents, $end);
        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        return new FileEdit($declaration->getStart(), $end);
    }

    private static function skipTrailingWhitespaceAndNewlines(string $file_contents, int $end) : int
    {
        // Handles \r\n and \n, but doesn't bother handling \r
        $next = \strpos($file_contents, "\n", $end);
        if ($next === false) {
            return $end;
        }
        $remaining = (string)\substr($file_contents, $end, $next - $end);
        if (\trim($remaining) === '') {
            return $next + 1;
        }
        return $end;
    }

    /**
     * @return array<string,Closure(string,PhpParser\Node,IssueInstance):(?FileEditSet)>
     */
    private static function createClosures() : array
    {
        /**
         * @return ?FileEditSet
         */
        $handle_unreferenced_use = static function (
            string $file_contents,
            PhpParser\Node $root_node,
            IssueInstance $issue_instance
        ) {
            // 1-based line
            $line = $issue_instance->getLine();
            $edits = [];
            foreach (self::getNodesAtLine($file_contents, $root_node, $line) as $candidate_node) {
                self::debug(\sprintf("Handling %s for %s\n", \get_class($candidate_node), (string)$issue_instance));
                if ($candidate_node instanceof NamespaceUseDeclaration) {
                    $edit = self::maybeRemoveNamespaceUseDeclaration($file_contents, $candidate_node, $issue_instance);
                    if ($edit) {
                        $edits[] = $edit;
                    }
                    break;
                }
            }
            if ($edits) {
                return new FileEditSet($edits);
            }
            return null;
        };
        return [
            Issue::UnreferencedUseNormal => $handle_unreferenced_use,
            Issue::UnreferencedUseConstant => $handle_unreferenced_use,
            Issue::UnreferencedUseFunction => $handle_unreferenced_use,
        ];
    }

    /**
     * Apply fixes where possible for any issues in $instances.
     *
     * @param IssueInstance[] $instances
     * @return void
     */
    public static function applyFixes(array $instances)
    {
        $fixers_for_files = self::computeFixersForInstances($instances);
        foreach ($fixers_for_files as $file => $fixers) {
            self::attemptFixForIssues((string)$file, $fixers);
        }
    }

    /**
     * Given a list of issue instances,
     * return arrays of Closures to fix fixable instances in their corresponding files.
     *
     * @param IssueInstance[] $instances
     * @return array<string,array<int,Closure(string,PhpParser\Node):(?FileEditSet)>>
     */
    public static function computeFixersForInstances(array $instances)
    {
        $closures = self::createClosures();
        $fixers_for_files = [];
        foreach ($instances as $instance) {
            $issue = $instance->getIssue();
            $type = $issue->getType();
            $closure = $closures[$type] ?? null;
            self::debug("Found closure for $type: " . \json_encode((bool)$closure));
            if ($closure) {
                /**
                 * @return ?FileEditSet
                 */
                $fixers_for_files[$instance->getFile()][] = static function (
                    string $file_contents,
                    PhpParser\Node $ast
                ) use (
                    $closure,
                    $instance
) {
                    self::debug("Calling for $instance\n");
                    return $closure($file_contents, $ast, $instance);
                };
            }
        }
        return $fixers_for_files;
    }

    /**
     * @param string $file the file name, for debugging
     * @param array<int,Closure(string,PhpParser\Node):(?FileEditSet)> $fixers one or more fixers. These return 0 edits if nothing works.
     * @return ?string the new contents, if fixes could be applied
     */
    public static function computeNewContentForFixers(
        string $file,
        string $contents,
        array $fixers
    ) {
        // A tolerantparser ast node
        $ast = (new Parser())->parseSourceFile($contents);

        // $dumper = new \Phan\AST\TolerantASTConverter\NodeDumper($contents);
        // $dumper->setIncludeTokenKind(true);
        // $dumper->dumpTree($ast);

        $all_edits = [];
        foreach ($fixers as $fix) {
            $edit_set = $fix($contents, $ast);
            foreach ($edit_set->edits ?? [] as $edit) {
                $all_edits[] = $edit;
            }
        }
        if (!$all_edits) {
            self::debug("Phan cannot create any automatic fixes for $file\n");
            return null;
        }
        return self::computeNewContents($file, $contents, $all_edits);
    }

    /**
     * @param array<int,Closure(string,PhpParser\Node):(?FileEditSet)> $fixers one or more fixers. These return 0 edits if nothing works.
     * @return void
     */
    private static function attemptFixForIssues(
        string $file,
        array $fixers
    ) {
        try {
            $entry = FileCache::getOrReadEntry($file);
        } catch (RuntimeException $e) {
            self::error("Could not automatically fix $file: could not read contents: " . $e->getMessage() . "\n");
            return;
        }
        $contents = $entry->getContents();
        $new_contents = self::computeNewContentForFixers($file, $contents, $fixers);
        if ($new_contents === null) {
            return;
        }
        // Sort file edits in order of start position
        $absolute_path = Config::projectPath($file);
        if (!\file_exists($absolute_path)) {
            // This file should exist - always warn
            self::error("Giving up on saving changes to $file: expected $absolute_path to exist already\n");
            return;
        }
        \file_put_contents($absolute_path, $new_contents);
    }

    /**
     * Compute the new contents for a file, given the original contents and a list of edits to apply to that file
     * @param string $file the path to the file, for logging.
     * @param string $contents the original contents of the file. This will be modified
     * @param FileEdit[] $all_edits
     * @return ?string - the new contents, if successful.
     */
    public static function computeNewContents(string $file, string $contents, array $all_edits)
    {
        \usort($all_edits, static function (FileEdit $a, FileEdit $b) : int {
            return ($a->replace_start <=> $b->replace_start) ?: ($a->replace_end <=> $b->replace_end);
        });
        self::debug("Going to apply these fixes for $file: " . StringUtil::jsonEncode($all_edits) . "\n");
        $last_end = 0;
        $new_contents = '';
        foreach ($all_edits as $edit) {
            if ($edit->replace_start < $last_end) {
                self::debug("Giving up on $file: replacement starts before end of another replacement\n");
                return null;
            }
            $new_contents .= \substr($contents, $last_end, $edit->replace_start - $last_end);
            $last_end = $edit->replace_end;
        }
        $new_contents .= \substr($contents, $last_end);
        return $new_contents;
    }

    private static function error(string $message)
    {
        \fwrite(\STDERR, $message);
    }

    private static function debug(string $message)
    {
        if (\getenv('PHAN_DEBUG_AUTOMATIC_FIX')) {
            \fwrite(\STDERR, $message);
        }
    }
}
