<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use Wtp\Parser\_argument\Argument; // Assuming Argument is in Wtp\Parser\_argument
use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة bisect في Python.
 * المرجع: https://docs.python.org/3/library/bisect.html#bisect.insort
 *
 * `insort` inserts an item into a list in sorted order.
 * In PHP, you can manually insert and then sort, or use a more complex data structure.
 */
function insort(array &$a, mixed $x, callable $key = null): void
{
    // If a key function is provided, use it for comparison
    if ($key !== null) {
        $x_val = $key($x);
        $inserted = false;
        foreach ($a as $index => $item) {
            if ($key($item) > $x_val) {
                array_splice($a, $index, 0, [$x]);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $a[] = $x;
        }
    } else {
        // Simple numeric/string comparison for basic types, or object comparison for arrays.
        // For arrays, comparison `a[0] <=> b[0]` is assumed as in Python's `_span_data` sorting.
        $inserted = false;
        foreach ($a as $index => $item) {
            // This comparison needs to be specific to the type of data being sorted.
            // For spans, it's typically based on the first element (start offset).
            if (is_array($x) && is_array($item) && isset($x[0]) && isset($item[0])) {
                if ($item[0] > $x[0]) {
                    array_splice($a, $index, 0, [$x]);
                    $inserted = true;
                    break;
                }
            } elseif ($item > $x) { // Fallback for simple types
                array_splice($a, $index, 0, [$x]);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $a[] = $x;
        }
    }
}


/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `spans()` مع تمرير البداية والنهاية.
 *
 * This global variable will store the compiled regex pattern for parser function name and arguments.
 */
$PF_NAME_ARGS_FULLMATCH_PATTERN = rc(
    '[^:|}]*+(?#name)' . '(?<arg>:[^|]*+)?+(?<arg>\|[^|]*+)*+'
);

/**
 * Wrapper for preg_match/preg_match_all that mimics Python's regex.Match object.
 * This version specifically supports 'spans' method returning all matches for a group.
 *
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param int $offset The start offset for the search.
 * @param int $length The length of the substring to search within.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function fullmatch_with_spans(string $pattern, string $subject, int $offset, int $length, string $flags = ''): ?array
{
    $sub = substr($subject, $offset, $length);
    $matches = [];
    // PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
    // PREG_PATTERN_ORDER for multiple 'arg' captures
    $result = preg_match_all('/^' . $pattern . '$/' . $flags, $sub, $matches, PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL);

    if ($result > 0) {
        $matchObject = [];
        $matchObject[0] = $matches[0][0]; // Full match, [matched_string, offset]
        $matchObject['start'] = $matches[0][0][1]; // Start offset relative to $sub

        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Named capture group
                // For repeating groups like 'arg', $value will be an array of [matched_string, offset] pairs
                $matchObject[$key] = array_column($value, 0); // Array of matched strings for the group

                $spans = [];
                foreach ($value as $item) {
                    if ($item[0] !== null) {
                        $spans[] = [$item[1], $item[1] + strlen($item[0])];
                    }
                }
                $matchObject['spans_' . $key] = $spans;
            } elseif (is_int($key) && $key > 0) { // Numeric capture group
                $matchObject[$key] = array_column($value, 0);

                $spans = [];
                foreach ($value as $item) {
                    if ($item[0] !== null) {
                        $spans[] = [$item[1], $item[1] + strlen($item[0])];
                    }
                }
                $matchObject['spans_' . $key] = $spans;
            }
        }

        // Mimic `match.spans(group_name)`
        $matchObject['spans'] = function ($groupName) use ($matchObject) {
            return $matchObject['spans_' . $groupName] ?? [];
        };

        // Mimic `match.find()` and `match.group()` if needed, but not directly used by current Python code.
        // `match.start()` and `match.end()` for specific groups can be derived from `spans`.

        // Store the original subject string and its offset/length for cache validation
        $matchObject['original_subject'] = $subject;
        $matchObject['original_offset'] = $offset;
        $matchObject['original_length'] = $length;

        return $matchObject;
    }
    return null;
}


/**
 * Class SubWikiTextWithArgs
 *
 * Define common attributes for `Template` and `ParserFunction`.
 */
abstract class SubWikiTextWithArgs extends SubWikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent for memory optimization.

    // Must be implemented by child classes
    protected string $_name_args_matcher_pattern = '';
    protected int $_first_arg_sep = 0;

    /**
     * @return array<int> [start_offset, end_offset] relative to current object's string.
     */
    protected function _content_span(): array
    {
        return [2, -2]; // Assuming this refers to 2 characters from start and 2 from end
    }

    /**
     * Return the nesting level of self.
     *
     * The minimum nesting_level is 0. Being part of any Template or
     * ParserFunction increases the level by one.
     *
     * @return int
     */
    public function nesting_level(): int
    {
        return $this->_nesting_level(['Template', 'ParserFunction']); // Assumed _nesting_level method exists in SubWikiText
    }

    /**
     * Parse template content. Create self.name and self.arguments.
     *
     * @return array<Argument>
     */
    public function arguments(): array
    {
        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText
        $contentSpanStart = $this->_content_span()[0];
        $contentSpanEnd = strlen($shadow) + $this->_content_span()[1]; // Adjust for negative index

        // Use the custom fullmatch_with_spans that supports offset and length
        $match = fullmatch_with_spans(
            $this->_name_args_matcher_pattern,
            $shadow,
            $contentSpanStart,
            $contentSpanEnd - $contentSpanStart
        );

        if ($match === null) {
            return [];
        }

        // Get 'arg' spans using the helper function
        $split_spans = $match['spans']('arg');

        if (empty($split_spans)) {
            return [];
        }

        $arguments = [];
        $type_to_spans = $this->_type_to_spans; // Assuming _type_to_spans from SubWikiText

        // _span_data is [ss, se, type_identifier, byte_array/content_string]
        list($ss, $se, $_, $_) = $this->_span_data;
        $span = $this->_span_data; // Reference to the object's own span data

        // Python's `id(span)` is unique identifier for the object's span list.
        // In PHP, we can use spl_object_id or a unique string based on object hash.
        $type_ = spl_object_hash($this);

        // Get or initialize the list of arg spans for this object
        if (!isset($type_to_spans[$type_])) {
            $type_to_spans[$type_] = [];
        }
        $arg_spans = &$type_to_spans[$type_];

        // Create a map for quick lookup: (start, end) tuple to existing span reference
        $span_tuple_to_span_get = [];
        foreach ($arg_spans as $sItem) {
            $span_tuple_to_span_get[serialize([$sItem[0], $sItem[1]])] = $sItem;
        }

        $lststr = $this->_lststr; // Assuming _lststr from SubWikiText

        foreach ($split_spans as list($arg_self_start, $arg_self_end)) {
            // Adjust offsets from shadow substring relative to the original shadow start
            $absolute_arg_start = $contentSpanStart + $arg_self_start;
            $absolute_arg_end = $contentSpanStart + $arg_self_end;

            $s = $ss + $absolute_arg_start; // Global start offset
            $e = $ss + $absolute_arg_end;   // Global end offset

            $arg_span = [$s, $e, null, null]; // Initialize new span

            $old_span = $span_tuple_to_span_get[serialize([$s, $e])] ?? null;

            if ($old_span === null) {
                insort($arg_spans, $arg_span, function ($item) {
                    return $item[0];
                }); // Insert in sorted order by start offset
            } else {
                $arg_span = $old_span; // Use existing span reference
            }

            $arg = new Argument($lststr, $type_to_spans, $arg_span, $type_, $this);
            // Python's `arg._span_data[3] = shadow[arg_self_start:arg_self_end]`
            // Here, we take the slice from the *original* shadow string.
            $arg->set_span_data_element(3, substr($shadow, $absolute_arg_start, $absolute_arg_end - $absolute_arg_start));
            $arguments[] = $arg;
        }
        return $arguments;
    }

    /**
     * Return the lists in all arguments.
     *
     * For performance reasons it is usually preferred to get a specific
     * Argument and use the `get_lists` method of that argument instead.
     *
     * @param string|iterable<string> $pattern
     * @return array<WikiList>
     */
    public function get_lists(string|iterable $pattern = ['\#', '\*', '[:;]']): array
    {
        $lists = [];
        foreach ($this->arguments() as $arg) { // Call arguments as method
            foreach ($arg->get_lists($pattern) as $lst) {
                $lists[] = $lst;
            }
        }
        return $lists;
    }

    /**
     * Template's name (includes whitespace).
     *
     * getter: Return the name.
     * setter: Set a new name.
     *
     * @return string
     */
    public function name(): string
    {
        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText
        $sep = strpos($shadow, chr($this->_first_arg_sep), 2); // Find first arg separator after "{{". chr() converts ASCII to char.
        if ($sep === false) {
            return $this->__invoke(2, -2); // From index 2 to end-2
        }
        return $this->__invoke(2, $sep); // From index 2 to separator
    }

    /**
     * @param string $newname The new name string.
     */
    public function set_name(string $newname): void
    {
        $currentNameLength = strlen($this->name()); // Call name as method
        $this->offsetSet(2, $currentNameLength, $newname); // Replace from index 2 with the length of current name
    }
}

/**
 * Class ParserFunction
 *
 * Represents a MediaWiki parser function (e.g., {{#if: ... | ... }}).
 */
class ParserFunction extends SubWikiTextWithArgs
{
    // __slots__ is Python-specific, no direct PHP equivalent.

    // Set the specific matcher pattern and first argument separator for ParserFunction
    protected string $_name_args_matcher_pattern = '[^:|}]*+(?#name)' . '(?<arg>:[^|]*+)?+(?<arg>\|[^|]*+)*+';
    protected int $_first_arg_sep = 58; // ASCII value for ':'

    /**
     * Returns a list of ParserFunction objects found within the current ParserFunction,
     * excluding the current one.
     *
     * @return array<ParserFunction>
     */
    public function parser_functions(): array
    {
        // Call the parent's parser_functions method (from SubWikiText) and return all but the first element
        $allParserFunctions = parent::parser_functions();
        return array_slice($allParserFunctions, 1);
    }
}
