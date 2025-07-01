<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\_spans\TypeToSpans;
use Wtp\Parser\_wikitext\EXTERNAL_LINK_FINDITER;
use Wtp\Parser\SubWikiText; // Assuming SubWikiText will be in Wtp\Parser

// PHP equivalents for regex constants
const MULTILINE = 'm';

// Using constants for patterns as they are effectively global for this class
const SUBLIST_PATTERN = '(?>^' . '(?&pattern)' . '[:;#*].*+' . '(?>\n|\Z)' . ')*+';
const SUBLIST_WITH_SECOND_PATTERN = '[*#;:].*+(?>\n|\Z)' . '(?>' . '(?&pattern)[*#;:].*+(?>\n|\Z)' . ')*+';
const LIST_PATTERN_FORMAT = (
    '(?<fullitem>^'
    . '(?<pattern>{pattern})'
    . '(?>'
    . '(?(?<=;\s*+)'
    . '# mark inline definition as an item'
    . '(?<item>[^:\n]*+)(?<fullitem>:(?<item>.*+))?+'
    . '(?>\n|\Z)' . SUBLIST_PATTERN . '|'
    . '# non-definition'
    . '(?>'
    . '(?<item>)'
    . SUBLIST_WITH_SECOND_PATTERN
    . '|(?<item>.*+)(?>\n|\Z)'
    . SUBLIST_PATTERN
    . ')'
    . ')'
    . '))++'
);


/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة regex في Python.
 * المرجع: https://pypi.org/project/regex/
 *
 * `fullmatch` في Python يعادل `preg_match` مع `^` و `$` حول النمط.
 *
 * @param string $pattern
 * @param string $subject
 * @param string $flags
 * @return array|null Returns a structured array mimicking Python's Match object, or null if no match.
 * The structure will contain numeric and named captures, with each capture being
 * [matched_string, offset]. For spans, 'span_groupname' will hold [start, end] arrays.
 */
function fullmatch(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    // PREG_OFFSET_CAPTURE captures the offset of each match.
    // PREG_UNMATCHED_AS_NULL ensures that unmatched groups are null, similar to Python's behavior.
    $result = preg_match('/^' . $pattern . '$/' . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        // The first element [0] contains the full match string and its offset
        $matchObject[0] = $matches[0];
        $matchObject['start'] = $matches[0][1]; // Start offset of the entire match

        // Process named and numeric captures
        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Named capture group
                $matchObject[$key] = $value;
                if ($value[0] !== null) { // If the group actually matched
                    $matchObject['span_' . $key][] = [$value[1], $value[1] + strlen($value[0])];
                } else {
                    $matchObject['span_' . $key] = []; // Empty span if not matched
                }
            } elseif (is_int($key) && $key > 0) { // Numeric capture group (excluding full match at index 0)
                $matchObject[$key] = $value;
                if ($value[0] !== null) {
                    $matchObject['span_' . $key][] = [$value[1], $value[1] + strlen($value[0])];
                } else {
                    $matchObject['span_' . $key] = [];
                }
            }
        }

        // Mimic match.span() and match.spans() methods
        // `spans(group_name)` returns a list of (start, end) tuples.
        $matchObject['spans'] = function ($groupName) use ($matchObject) {
            if (isset($matchObject['span_' . $groupName])) {
                return $matchObject['span_' . $groupName];
            }
            return [];
        };
        // `span(group_name)` returns (start, end) for the first occurrence.
        $matchObject['span'] = function ($groupName) use ($matchObject) {
            if (isset($matchObject['span_' . $groupName]) && !empty($matchObject['span_' . $groupName])) {
                return $matchObject['span_' . $groupName][0];
            }
            return [null, null]; // Return null for start and end if not found, similar to Python
        };

        // Mimic match['group_name'] for accessing matched string
        $matchObject['get_group'] = function ($groupName) use ($matchObject) {
            if (isset($matchObject[$groupName]) && isset($matchObject[$groupName][0])) {
                return $matchObject[$groupName][0];
            }
            return null;
        };

        return $matchObject;
    }
    return null;
}

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة regex في Python.
 * المرجع: https://pypi.org/project/regex/
 *
 * `escape` في Python يعادل `preg_quote` في PHP.
 */
function escape(string $string): string
{
    return preg_quote($string, '/'); // Use '/' as delimiter for consistency
}

/**
 * Class WikiList
 *
 * Class to represent ordered, unordered, and definition lists.
 */
class WikiList extends SubWikiText
{
    // In PHP, properties are declared directly. __slots__ is Python-specific.
    public string $pattern;
    private ?array $_match_cache;

    /**
     * @param string|array<string> $string
     * @param string $pattern
     * @param array|null $match An array representing the regex match result.
     * @param TypeToSpans|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|null $_type
     */
    public function __construct(
        string|array $string,
        string $pattern,
        ?array $_match = null,
        ?TypeToSpans $_typeToSpans = null,
        ?array $_span = null,
        ?string $_type = null
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        $this->pattern = $pattern;

        if ($_match) {
            $this->_match_cache = [$_match, $this->string];
        } else {
            $this->_match_cache = [
                fullmatch(
                    str_replace(
                        '{pattern}',
                        preg_quote($pattern, '/'),
                        self::LIST_PATTERN_FORMAT
                    ),
                    $this->_list_shadow(), // Call as method
                    MULTILINE
                ),
                $this->string,
            ];
        }
    }

    /**
     * Mimics Python's `_list_shadow` property.
     *
     * @return string
     */
    private function _list_shadow(): string
    {
        // _shadow property from SubWikiText
        // Assumes _shadow stores the string content and is mutable or accessible
        $shadowCopy = $this->_shadow;

        if (str_contains($this->pattern, ':')) {
            // EXTERNAL_LINK_FINDITER() is expected to return the regex pattern as a string
            $regex = EXTERNAL_LINK_FINDITER();

            // preg_replace_callback is used to replace matches with '_' characters
            $shadowCopy = preg_replace_callback(
                $regex,
                function ($matches) {
                    // $matches[0] is the full matched string
                    return str_repeat('_', strlen($matches[0]));
                },
                $shadowCopy
            );
        }
        return $shadowCopy;
    }

    /**
     * Return the match object for the current list.
     *
     * @return array<string, mixed>|null A PHP regex match array structure.
     */
    private function _match(): ?array
    {
        list($cacheMatch, $cacheString) = $this->_match_cache;
        $string = $this->string;

        if ($cacheString === $string) {
            return $cacheMatch;
        }

        // Recompute if the string has changed
        $cacheMatch = fullmatch(
            str_replace(
                '{pattern}',
                preg_quote($this->pattern, '/'),
                self::LIST_PATTERN_FORMAT
            ),
            $this->_list_shadow(), // Call as method
            MULTILINE
        );
        $this->_match_cache = [$cacheMatch, $string];
        return $cacheMatch;
    }

    /**
     * Return items as a list of strings.
     * Do not include sub-items and the start pattern.
     *
     * @return array<string>
     */
    public function items(): array
    {
        $items = [];
        $string = $this->string;
        $match = $this->_match(); // Call as method

        if (!$match) {
            return [];
        }

        $ms = $match['start']; // Get the start offset of the overall match

        // Call the 'spans' function stored in the match object to get item spans
        $itemSpans = $match['spans']('item');

        foreach ($itemSpans as list($s, $e)) {
            // Adjust to be relative to the SubWikiText's string start (ms)
            $items[] = substr($string, $s - $ms, $e - $s);
        }
        return $items;
    }

    /**
     * Return list of item strings. Includes their start and sub-items.
     *
     * @return array<string>
     */
    public function fullitems(): array
    {
        $fullitems = [];
        $string = $this->string;
        $match = $this->_match(); // Call as method

        if (!$match) {
            return [];
        }

        $ms = $match['start']; // Get the start offset of the overall match

        // Call the 'spans' function stored in the match object to get fullitem spans
        $fullitemSpans = $match['spans']('fullitem');

        // Sort just like Python's `sorted(match.spans('fullitem'))`
        usort($fullitemSpans, function ($a, $b) {
            return $a[0] <=> $b[0]; // Sort by start offset
        });

        foreach ($fullitemSpans as list($s, $e)) {
            $fullitems[] = substr($string, $s - $ms, $e - $s);
        }
        return $fullitems;
    }

    /**
     * Return level of nesting for the current list.
     * Level is a one-based index, for example the level for `* a` will be 1.
     *
     * @return int
     */
    public function level(): int
    {
        $match = $this->_match(); // Call as method
        if ($match) {
            // Access the 'pattern' group's matched string using the helper function
            $patternMatch = $match['get_group']('pattern');
            if ($patternMatch !== null) {
                return strlen($patternMatch);
            }
        }
        return 0; // Or throw an error if a list must always have a pattern
    }

    /**
     * Return the Lists inside the item with the given index.
     *
     * @param int|null $i The index of the item which its sub-lists are desired.
     * @param string|iterable<string> $pattern The starting symbol for the desired sub-lists.
     * The `pattern` of the current list will be automatically added as prefix.
     * @return array<WikiList>
     */
    public function sublists(?int $i = null, string|iterable $pattern = ['\#', '\*', '[:;]']): array
    {
        if (is_string($pattern)) {
            $patterns = [$pattern];
        } else {
            // Ensure patterns is an array for iteration
            $patterns = is_array($pattern) ? $pattern : iterator_to_array($pattern);
        }

        $self_pattern = $this->pattern;
        $sublists = [];

        if ($i === null) {
            // Any sublist is acceptable
            foreach ($patterns as $p) {
                // Call get_lists from the parent class (SubWikiText)
                foreach (parent::get_lists($self_pattern . $p) as $lst) {
                    $sublists[] = $lst;
                }
            }
        } else {
            // Only return sub-lists that are within the given item
            $match = $this->_match(); // Call as method
            if (!$match) {
                return [];
            }

            // Get 'fullitem' spans using the helper function
            $fullitem_spans = $match['spans']('fullitem');

            if (!isset($fullitem_spans[$i])) {
                return []; // Index out of bounds
            }

            list($s, $e) = $fullitem_spans[$i];

            // _span_data is a property from SubWikiText, assumed to be accessible.
            // It should hold [start_offset, end_offset, type, source].
            $ss = $this->_span_data[0];
            $ms = $match['start']; // Start offset of the overall match

            // Adjust s and e to be relative to the WikiList's string content
            $e -= ($ms - $ss);
            $s -= ($ms - $ss);

            foreach ($patterns as $p) {
                // Call get_lists from the parent class (SubWikiText)
                foreach (parent::get_lists($self_pattern . $p) as $lst) {
                    // noinspection PyProtectedMember
                    // Assuming _span_data is public or has a getter and contains [start, end, type, source]
                    $ls = $lst->_span_data[0];
                    $le = $lst->_span_data[1];
                    if ($s <= $ls && $le <= $e) {
                        $sublists[] = $lst;
                    }
                }
            }
        }

        // Sort by the span data (start offset primarily)
        usort($sublists, function (WikiList $a, WikiList $b) {
            // Assuming _span_data is a protected property [start, end, type, source]
            return $a->_span_data[0] <=> $b->_span_data[0];
        });

        return $sublists;
    }

    /**
     * Convert to another list type by replacing starting pattern.
     *
     * @param string $newstart The new starting pattern (e.g., "*", "#").
     */
    public function convert(string $newstart): void
    {
        $match = $this->_match(); // Call as method
        if (!$match) {
            return;
        }

        $ms = $match['start']; // Global start offset of the match

        // Get 'pattern' spans using the helper function
        $patternSpans = $match['spans']('pattern');

        // Process in reverse to avoid issues with changing offsets during iteration
        // Sort by start position in descending order
        usort($patternSpans, function ($a, $b) {
            return $b[0] <=> $a[0];
        });

        foreach ($patternSpans as list($s, $e)) {
            // Calculate slice relative to the WikiList's own string (string property of SubWikiText)
            $relativeStart = $s - $ms;
            $relativeLength = $e - $s;

            // This is where we need to modify the underlying string
            // Assuming SubWikiText has a method to handle slicing like Python's __setitem__
            $this->replaceSubstring($relativeStart, $relativeLength, $newstart);
        }

        // Update the pattern property to reflect the change
        $this->pattern = escape($newstart);
    }

    /**
     * Returns the Lists inside the current WikiList.
     * This is a convenience method that calls sublists with default parameters.
     *
     * @param string|iterable<string> $pattern
     * @return array<WikiList>
     */
    public function get_lists(string|iterable $pattern = ['\#', '\*', '[:;]']): array
    {
        return $this->sublists(null, $pattern);
    }
}
