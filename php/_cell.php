<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use Wtp\Parser\_spans\ATTRS_MATCH; // Assuming ATTRS_MATCH function is in Wtp\Parser\_spans
use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans

// PHP equivalents for regex constants
const DOTALL = 's';
const VERBOSE = 'x';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()`, `start()`, `end()`, و `span()`.
 * Also, Python's `Match[bytes]` means it operates on bytes. PHP strings are byte strings.
 *
 * Helper function to create a Match object alike array for PHP.
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param string $flags Regex flags.
 * @return array|null
 */
function create_match_object(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match($pattern . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        // Store raw matches for direct access by numeric index or named keys
        foreach ($matches as $key => $value) {
            if (is_int($key)) { // Numeric capture group
                $matchObject[$key] = $value[0]; // Matched string
                $matchObject['offset_' . $key] = $value[1]; // Offset
            } elseif (is_string($key)) { // Named capture group
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            }
        }

        // Mimic `match.group(group_number_or_name)`
        $matchObject['group'] = function ($group) use ($matchObject) {
            return $matchObject[$group] ?? null;
        };

        // Mimic `match.start(group_number_or_name)`
        $matchObject['start'] = function ($group = 0) use ($matchObject) {
            return $matchObject['offset_' . $group] ?? -1;
        };

        // Mimic `match.end(group_number_or_name)`
        $matchObject['end'] = function ($group = 0) use ($matchObject) {
            if (isset($matchObject['offset_' . $group]) && isset($matchObject[$group])) {
                return $matchObject['offset_' . $group] + strlen($matchObject[$group]);
            }
            return -1;
        };

        // Mimic `match.span(group_number_or_name)`
        $matchObject['span'] = function ($group = 0) use ($matchObject) {
            $start = $matchObject['start']($group);
            $end = $matchObject['end']($group);
            return [$start, $end];
        };

        // Mimic `match.captures(group_name)`
        // This is complex for a single preg_match. If a group is repeated, preg_match only captures the last one.
        // To get all, preg_match_all needs to be run. For ATTRS_MATCH, this is handled by ATTRS_MATCH function itself.
        $matchObject['captures'] = function ($groupName) use ($subject, $pattern, $flags) {
            // For Cell, attrs are often parsed from a substring. We need to re-run ATTRS_MATCH if it's based on it.
            // If the pattern being matched here (NEWLINE_CELL_MATCH, etc.) had repeating capture groups,
            // we'd need to run `preg_match_all` with `PREG_PATTERN_ORDER`.
            // For now, assume this is mainly for ATTRS_MATCH which needs its own handling.
            return []; // Placeholder
        };

        // Store the original subject string for cache validation
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من ملف _spans.py في Python.
 * المرجع: (لا يوجد توثيق عام لهذا الملف، لذا قد تحتاج إلى البحث في الكود الأصلي)
 *
 * This function is used to match attributes, and it handles repeating capture groups ('attr_name', 'attr_value').
 * So it should return a match-like object that properly supports `spans()` and `captures()`.
 */
function ATTRS_MATCH_FUNCTION(string $subject, int $start_offset, int $end_offset): array
{
    // This is a placeholder. The actual ATTRS_MATCH needs to be defined
    // to properly handle multiple attributes and return a suitable match object.
    // It should internally use preg_match_all to capture all occurrences of attr_name and attr_value.

    // For now, a simplified version that mimics the structure used by Cell.
    $attrs_string = substr($subject, $start_offset, $end_offset - $start_offset);

    $matches = [];
    preg_match_all(
        '/(?<attr>\s*(?<attr_name>[A-Za-z0-9]++)\s*=(?<quote>[\'"]?)(?<attr_value>.*?)(?P=quote))|(?<attr_name_no_value>[A-Za-z0-9]++)/x',
        $attrs_string,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    $attrs_match_object = [];
    $attrs_match_object['attr_name'] = [];
    $attrs_match_object['attr_value'] = [];
    $attrs_match_object['span_attr_name'] = [];
    $attrs_match_object['span_attr_value'] = [];
    $attrs_match_object['span_attr'] = []; // The full attribute span

    foreach ($matches as $match) {
        if (isset($match['attr_name']) && $match['attr_name'][0] !== null) {
            $attrs_match_object['attr_name'][] = $match['attr_name'][0];
            // Adjust offset to be relative to original subject
            $attrs_match_object['span_attr_name'][] = [$start_offset + $match['attr_name'][1], $start_offset + $match['attr_name'][1] + strlen($match['attr_name'][0])];
        } elseif (isset($match['attr_name_no_value']) && $match['attr_name_no_value'][0] !== null) {
            // Handle attributes without values (e.g. `disabled`)
            $attrs_match_object['attr_name'][] = $match['attr_name_no_value'][0];
            $attrs_match_object['span_attr_name'][] = [$start_offset + $match['attr_name_no_value'][1], $start_offset + $match['attr_name_no_value'][1] + strlen($match['attr_name_no_value'][0])];
            $attrs_match_object['attr_value'][] = ''; // Empty string for value
            $attrs_match_object['span_attr_value'][] = [-1, -1]; // No span for value
        }

        if (isset($match['attr']) && $match['attr'][0] !== null) {
            $attrs_match_object['span_attr'][] = [$start_offset + $match['attr'][1], $start_offset + $match['attr'][1] + strlen($match['attr'][0])];
        }

        if (isset($match['attr_value']) && $match['attr_value'][0] !== null) {
            $attrs_match_object['attr_value'][] = $match['attr_value'][0];
            $attrs_match_object['span_attr_value'][] = [$start_offset + $match['attr_value'][1], $start_offset + $match['attr_value'][1] + strlen($match['attr_value'][0])];
        }
    }

    $attrs_match_object['spans'] = function ($groupName) use ($attrs_match_object) {
        return $attrs_match_object['span_' . $groupName] ?? [];
    };

    $attrs_match_object['captures'] = function ($groupName) use ($attrs_match_object) {
        return $attrs_match_object[$groupName] ?? [];
    };

    $attrs_match_object['string'] = $subject; // Full subject, not just the slice

    return $attrs_match_object;
}


// Global regex patterns for different cell types
$NEWLINE_CELL_MATCH_PATTERN = rc(
    '\\s*+'
        . '(?P<sep>\\|(?![+\\-}])|!)'
        . '(?>'
        . '(?P<attrs>)'
        . '\\|'
        . '(?!\\|)'
        . '|'
        . '(?P<attrs>'
        . '(?:'
        . '[^|\\n]'
        . '(?!'
        . '(?P=sep){2}'
        . ')'
        . ')*'
        . ')'
        . '\\|'
        . '(?!'
        . '\\||'
        . '!!'
        . ')'
        . ')?+'
        . '(?P<data>[\\s\\S]*?)'
        . '(?='
        . '\\|\\||'
        . '(?P=sep){2}|'
        . '\\|!!|'
        . '\\n\\s*+[!|]|'
        . '$'
        . ')',
    VERBOSE
);

$INLINE_HAEDER_CELL_MATCH_PATTERN = rc(
    '(?>'
        . '(?P<sep>\\|)!(?P<attrs>)!'
        . '|'
        . '(?>'
        . '(?P<sep>!)!'
        . '|'
        . '(?P<sep>\\|)\\|'
        . ')'
        . '(?>'
        . '(?P<attrs>)'
        . '\\|'
        . '(?!\\|)'
        . '|'
        . '(?P<attrs>'
        . '(?:'
        . '(?!!!{2})'
        . '[^|\\n]'
        . ')*+'
        . ')'
        . '\\|'
        . '(?!\\|)'
        . ')?+'
        . '(?P<data>.*?)'
        . '(?='
        . '\\n\\s*+[!|]|'
        . '\\|\\||'
        . '!!|'
        . '\\|!!|'
        . '$'
        . ')',
    VERBOSE . DOTALL
);

$INLINE_NONHAEDER_CELL_MATCH_PATTERN = rc(
    '(?P<sep>\\|)\\|'
        . '(?>'
        . '(?P<attrs>)'
        . '\\|'
        . '(?!\\|)'
        . '|'
        . '(?P<attrs>'
        . '[^|\\n]*?'
        . ')'
        . '\\|'
        . '(?!\\|)'
        . ')?+'
        . '(?P<data>'
        . '[^|]*?'
        . '(?='
        . '\\|\\||'
        . '\\n\\s*+[!|]|'
        . '$'
        . ')'
        . ')',
    VERBOSE
);


/**
 * Class Cell
 *
 * Represents a single cell within a MediaWiki table.
 */
class Cell extends SubWikiTextWithAttrs
{
    // __slots__ is Python-specific.
    private bool $_header;
    private ?array $_match_cache;
    private ?array $_attrs_match_cache;

    /**
     * @param string|array<string> $string
     * @param bool $header
     * @param TypeToSpans|null $_typeToSpans
     * @param array<int>|null $_span
     * @param int|null $_type
     * @param array|null $_match Array representing the pre-computed regex match for the cell.
     * @param array|null $_attrs_match Array representing the pre-computed regex match for attributes.
     */
    public function __construct(
        string|array $string,
        bool $header = false,
        ?TypeToSpans $_typeToSpans = null,
        ?array $_span = null,
        ?int $_type = null,
        ?array $_match = null,
        ?array $_attrs_match = null
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        $this->_header = $header;

        if ($_match) {
            $this->_match_cache = [$_match, $this->string];
            if ($_attrs_match) {
                $this->_attrs_match_cache = [$_attrs_match, $this->string];
            } else {
                // If cell match is provided but attrs match is not, derive attrs match
                $cell_start = $_match['start'](); // Get start of the cell match
                list($attrs_start, $attrs_end) = $_match['span']('attrs');

                // ATTRS_MATCH_FUNCTION expects the full string and relative offsets
                $this->_attrs_match_cache = [
                    ATTRS_MATCH_FUNCTION($_match['string'], $attrs_start, $attrs_end),
                    $this->string,
                ];
            }
        } else {
            $this->_attrs_match_cache = $this->_match_cache = [null, null];
        }
    }

    /**
     * Return the match object for the current cell. Cache the result.
     *
     * @return array<string, mixed> The match array for the cell.
     */
    protected function _match(): array
    {
        global $NEWLINE_CELL_MATCH_PATTERN, $INLINE_HAEDER_CELL_MATCH_PATTERN, $INLINE_NONHAEDER_CELL_MATCH_PATTERN;

        list($cache_match, $cache_string) = $this->_match_cache;
        $string = $this->string;

        if ($cache_string === $string) {
            return $cache_match;
        }

        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText
        $m = null;

        // ord('\n') is 10, ord('!') is 33
        if (isset($shadow[0]) && ord($shadow[0]) === 10) { // Check for newline character at start
            $m = create_match_object($NEWLINE_CELL_MATCH_PATTERN, $shadow, VERBOSE);
            if ($m && isset($m['sep'])) {
                $this->_header = (ord($m['sep']) === 33); // Check if separator is '!'
            }
        } elseif ($this->_header) {
            $m = create_match_object($INLINE_HAEDER_CELL_MATCH_PATTERN, $shadow, VERBOSE . DOTALL);
        } else {
            $m = create_match_object($INLINE_NONHAEDER_CELL_MATCH_PATTERN, $shadow, VERBOSE);
        }

        $this->_match_cache = [$m, $string];
        $this->_attrs_match_cache = [null, null]; // Clear attrs cache when cell match changes

        if ($m === null) {
            return []; // Return empty array if no match
        }
        return $m;
    }

    /**
     * Return the match object for attributes.
     *
     * @return array<string, mixed> The match array for attributes.
     */
    protected function _attrs_match(): array
    {
        list($cache, $cache_string) = $this->_attrs_match_cache;
        $string = $this->string;

        if ($cache_string === $string) {
            return $cache;
        }

        $cell_match = $this->_match(); // Get the current cell match
        if (empty($cell_match)) {
            return [];
        }

        list($s, $e) = $cell_match['span']('attrs');
        if ($s === -1 || $e === -1) {
            // No 'attrs' group found in the cell match, so no attributes.
            return [];
        }

        // ATTRS_MATCH_FUNCTION expects the full shadow string and specific offsets for the attribute part.
        $attrs_match = ATTRS_MATCH_FUNCTION($cell_match['string'], $s, $e);
        $this->_attrs_match_cache = [$attrs_match, $string];
        return $attrs_match;
    }

    /**
     * Cell's value.
     *
     * getter: Return this cell's value.
     * setter: Assign new_value to self.
     *
     * @return string
     */
    public function value(): string
    {
        $m = $this->_match(); // Call as method
        if (empty($m)) {
            return '';
        }

        $offset = $m['start'](); // Start of the cell's match (relative to its own string)
        list($s, $e) = $m['span']('data'); // Span of the 'data' group (relative to cell's shadow string)

        // Adjust offsets to be relative to the object's string and extract
        return $this->__invoke($s - $offset, $e - $offset);
    }

    /**
     * @param string $new_value The new value for the cell.
     */
    public function set_value(string $new_value): void
    {
        $m = $this->_match(); // Call as method
        if (empty($m)) {
            return;
        }

        $offset = $m['start']();
        list($s, $e) = $m['span']('data');
        $this->offsetSet($s - $offset, ($e - $offset) - ($s - $offset), $new_value);
    }

    /**
     * Set the value for the given attribute name.
     *
     * If there are already multiple attributes with that name, only
     * set the value for the last one.
     * If attr_value == '', use the implicit empty attribute syntax.
     *
     * @param string $attr_name
     * @param string $attr_value
     */
    public function set_attr(string $attr_name, string $attr_value): void
    {
        // Note: The set_attr method of the parent class cannot be used instead
        // of this method because a cell could be without any attrs placeholder
        // which means the appropriate piping should be added around attrs by
        // this method. Also ATTRS_MATCH does not have any 'start' group.
        $cell_match = $this->_match();
        if (empty($cell_match)) {
            return;
        }

        $shadow = $cell_match['string']; // The string that was matched (cell's shadow)
        list($attrs_start, $attrs_end) = $cell_match['span']('attrs');

        if ($attrs_start !== -1) {
            // There is an existing attributes span
            $encoded_attr_name = $attr_name; // PHP strings are bytes, no explicit encode needed for this context
            $attrs_m = ATTRS_MATCH_FUNCTION($shadow, $attrs_start, $attrs_end);

            $attr_names_captured = $attrs_m['captures']('attr_name');
            $attr_values_spans = $attrs_m['spans']('attr_value');
            $attr_full_spans = $attrs_m['spans']('attr');

            // Iterate in reversed order to find the last attribute
            for ($i = count($attr_names_captured) - 1; $i >= 0; $i--) {
                if ($attr_names_captured[$i] === $encoded_attr_name) {
                    list($vs, $ve) = $attr_values_spans[$i];

                    $q = 0;
                    // Check if the original character at the end of the value span (ve) is a quote.
                    // This `attrs_m.string[ve]` checks the original shadow string.
                    if (isset($shadow[$ve]) && in_array($shadow[$ve], ['"', "'"])) {
                        $q = 1;
                    }

                    // Replace the attribute value, including its quotes if they exist
                    $replacement_str = $attr_value;
                    if ($attr_value !== '') { // Only add quotes if value is not empty
                        $replacement_str = '"' . $attr_value . '"';
                    }

                    $this->offsetSet($vs - $q, ($ve + $q) - ($vs - $q), $replacement_str);
                    return;
                }
            }
            // We have some attributes, but none of them is attr_name, so add it
            $attr_end = $cell_match['end']('attrs'); // End of the 'attrs' group

            $fmt = ' {}="{}"'; // Default format
            if (isset($shadow[$attr_end - 1]) && ord($shadow[$attr_end - 1]) === 32) { // Check if previous char was space
                $fmt = ' {}="{}"'; // If already a space, just add attribute
            } else {
                $fmt = ' {}="{}"'; // Add leading space if needed
            }

            $insert_value = sprintf($fmt, $attr_name, $attr_value);
            $this->insert($attr_end, $insert_value);
            return;
        }

        // There is no attributes span in this cell. Create one.
        $fmt_no_value = ' %s |'; // Format for attribute without value
        $fmt_with_value = ' %s="%s" |'; // Format for attribute with value

        $to_insert = $attr_value ? sprintf($fmt_with_value, $attr_name, $attr_value) : sprintf($fmt_no_value, $attr_name);

        if (isset($shadow[0]) && ord($shadow[0]) === 10) { // If it's a newline cell (starts with '\n')
            // Insert after the cell separator (e.g., '|' or '!')
            $this->insert($cell_match['start']('sep') + 1, $to_insert);
            return;
        }
        // An inline cell, insert after the first two characters (e.g., '||' or '!!')
        $this->insert(2, $to_insert);
    }

    /**
     * Return True if this is a header cell.
     *
     * @return bool
     */
    public function is_header(): bool
    {
        return $this->_header;
    }
}
