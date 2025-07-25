<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use Wtp\Parser\_spans\ATTRS_MATCH_FUNCTION; // Assuming ATTRS_MATCH_FUNCTION is in Wtp\Parser\_spans
use Wtp\Node\SubWikiTextWithAttrs; // Assuming SubWikiTextWithAttrs is in Wtp\Node
use Wtp\Node\Cell; // Assuming Cell is in Wtp\Node
use Wtp\Parser\_table_utils\CAPTION_MATCH; // Assuming CAPTION_MATCH function is in Wtp\Parser\_table_utils
use Wtp\Parser\_table_utils\FIND_ROWS; // Assuming FIND_ROWS function is in Wtp\Parser\_table_utils
use Wtp\Parser\_table_utils\HEAD_DIGITS; // Assuming HEAD_DIGITS function is in Wtp\Parser\_table_utils
use Wtp\Parser\_table_utils\FIRST_NON_CAPTION_LINE; // Assuming FIRST_NON_CAPTION_LINE function is in Wtp\Parser\_table_utils
use const Wtp\Parser\WS; // Assuming WS is a constant in Wtp\Parser

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة bisect في Python.
 * المرجع: https://docs.python.org/3/library/bisect.html#bisect.insort_right
 *
 * `insort_right` inserts an item into a list in sorted order, keeping items with equal value to the right.
 * Similar to `insort` but places item after existing identical items.
 */
function insort_right(array &$a, mixed $x, callable $key = null): void
{
    // If a key function is provided, use it for comparison
    if ($key !== null) {
        $x_val = $key($x);
        $inserted = false;
        foreach ($a as $index => $item) {
            if ($key($item) > $x_val) { // Insert before larger values
                array_splice($a, $index, 0, [$x]);
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            $a[] = $x; // Append if larger than all existing
        }
    } else {
        // Simple numeric/string comparison for basic types, or object comparison for arrays (based on first element)
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
 * Converts a value (from `colspan` or `rowspan` attributes) to an integer.
 * If the value is not a digit, returns 1.
 *
 * @param string|null $value The string value from the attribute.
 * @return int
 */
function head_int(?string $value): int
{
    if ($value === null) {
        return 1;
    }
    $match = HEAD_DIGITS($value); // Call HEAD_DIGITS function from _table_utils
    // Python's `match[0]` gets the full matched string.
    if ($match === null) {
        return 1;
    }
    return (int)($match['group'](0)); // Access group 0 (full match)
}


/**
 * Class Table
 *
 * Represents a MediaWiki table.
 */
class Table extends SubWikiTextWithAttrs
{
    // __slots__ is Python-specific.
    private ?array $_attrs_match_cache;

    /**
     * @param string|array<string> $string
     * @param array|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|int|null $_type
     */
    public function __construct(
        string|array $string,
        ?array $_typeToSpans = null,
        ?array $_span = null,
        string|int|null $_type = null
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        $this->_attrs_match_cache = [null, null]; // Initialize cache
    }

    /**
     * Return the nesting level of self.
     *
     * The minimum nesting_level is 0. Being part of any Table increases
     * the level by one.
     *
     * @return int
     */
    public function nesting_level(): int
    {
        // _nesting_level is assumed to be available from SubWikiText.
        // Subtract 1 because a table itself counts as level 1, but nesting starts from 0 for contained tables.
        return $this->_nesting_level(['Table']) - 1;
    }

    /**
     * Remove Table spans from shadow and return it.
     *
     * @return string (mimicking bytearray)
     */
    private function _table_shadow(): string
    {
        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText
        $ss = $this->_span_data[0]; // Start offset of this table within the main text

        // `_subspans('Table')` returns spans of nested tables within this table.
        foreach ($this->_subspans('Table') as list($s, $e, $_, $_)) {
            if ($s === $ss) {
                continue; // Skip self's span
            }
            // Replace nested table content with '#' characters in the shadow.
            // s - ss: adjust offset relative to current table's shadow string.
            $shadow = substr_replace($shadow, str_repeat('#', $e - $s), $s - $ss, $e - $s);
        }
        return $shadow;
    }

    /**
     * Return match_table.
     *
     * @return array<array<array<string, mixed>>> A 2D array of match objects (PHP arrays).
     */
    private function _match_table(): array
    {
        $table_shadow = $this->_table_shadow();

        // Remove table-start and table-end marks.
        // `find(10)` is ord('\n').
        $pos = strpos($table_shadow, "\n"); // Find first newline
        if ($pos === false) {
            // If no newline, means table is likely malformed or empty, no rows
            return [[]];
        }

        $lsp = _lstrip_increase($table_shadow, $pos); // Strip leading whitespace from a position.

        // Remove everything until the first row.
        try {
            // Loop until a non-whitespace character (not '!' or '|') is found, indicating actual content or malformation.
            // Python's `table_shadow[lsp] not in b'!|'` means `ord($table_shadow[$lsp])` is not 33 or 124.
            while (isset($table_shadow[$lsp]) && !(ord($table_shadow[$lsp]) === 33 || ord($table_shadow[$lsp]) === 124)) {
                $nlp = strpos($table_shadow, "\n", $lsp); // Next newline position
                if ($nlp === false) {
                    return [[]]; // No more newlines, table is empty or malformed
                }
                $pos = $nlp;
                $lsp = _lstrip_increase($table_shadow, $pos);
            }
        } catch (\OutOfBoundsException $e) { // If lsp goes out of bounds.
            return [[]];
        }

        $match_table = [];

        // Start of the first row.
        $search_result = FIRST_NON_CAPTION_LINE($table_shadow, $pos); // Call FIRST_NON_CAPTION_LINE function
        $pos = $search_result['start'](); // Start of the first actual row/cell content

        $rsp = _row_separator_increase($table_shadow, $pos); // Find next row separator or end of table.
        $pos_tracker = -1; // Use a distinct variable to track position within the loop condition

        while ($pos_tracker != $rsp) {
            $pos_tracker = $rsp; // Update tracker for current row's starting position

            // `NEWLINE_CELL_MATCH` is assumed to work on the entire `table_shadow` from a given `pos_tracker`.
            $m = create_match_object_for_cell_parsing(NEWLINE_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);

            // Don't add a row if there are no new cells.
            if ($m) {
                $match_row = [];
                $match_table[] = &$match_row; // Add by reference so inner loop can populate it directly.

                while ($m !== null) {
                    $match_row[] = $m;
                    $sep_char = $m['group']('sep'); // Get the separator character (! or |)
                    $pos_tracker = $m['end'](); // Update position for next match

                    if (ord($sep_char) === ord('|')) {
                        // Inline non-header cells
                        $m = create_match_object_for_cell_parsing(INLINE_NONHAEDER_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);
                        while ($m !== null) {
                            $match_row[] = $m;
                            $pos_tracker = $m['end']();
                            $m = create_match_object_for_cell_parsing(INLINE_NONHAEDER_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);
                        }
                    } elseif (ord($sep_char) === ord('!')) {
                        // Inline header cells
                        $m = create_match_object_for_cell_parsing(INLINE_HAEDER_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);
                        while ($m !== null) {
                            $match_row[] = $m;
                            $pos_tracker = $m['end']();
                            $m = create_match_object_for_cell_parsing(INLINE_HAEDER_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);
                        }
                    }

                    // After inline cells, check for next newline cell or end of row
                    $search_result = FIRST_NON_CAPTION_LINE($table_shadow, $pos_tracker);
                    $pos_tracker = $search_result['start']();
                    $m = create_match_object_for_cell_parsing(NEWLINE_CELL_MATCH_PATTERN, $table_shadow, $pos_tracker);
                }
            }
            $rsp = _row_separator_increase($table_shadow, $pos_tracker);
        }
        return $match_table;
    }

    /**
     * Returns a list containing lists of row values.
     *
     * @param bool $span If true, calculate rows according to rowspans and colspans attributes.
     * @param int|null $row Return the specified row only. Zero-based index.
     * @param int|null $column Return the specified column only. Zero-based index.
     * @param bool $strip Strip data values.
     * @return array<array<string>>|array<string>|string
     */
    public function data(
        bool $span = true,
        ?int $row = null,
        ?int $column = null,
        bool $strip = true
    ): array|string {
        global $WS; // Access global WS constant

        $match_table = $this->_match_table(); // Call as method
        $string = $this->string;
        $table_data = [];

        foreach ($match_table as $match_row) {
            $row_data = [];
            $table_data[] = $row_data; // Add by value, then populate $table_data[idx]

            foreach ($match_row as $m) {
                list($s, $e) = $m['span']('data'); // Span of 'data' group (relative to matched substring)
                $cell_value = substr($string, $m['start']() + $s, $e - $s); // Extract from original string, adjusted by full match start

                if ($strip) {
                    // Spaces after the first newline can be meaningful in wikitext.
                    // lstrip(' ').rstrip(WS) is specific.
                    $row_data[] = rtrim(ltrim($cell_value, ' '), $WS);
                } else {
                    $row_data[] = $cell_value;
                }
            }
            end($table_data)->current($row_data); // Update the last added row with its populated data.
        }

        if (!empty($table_data) && $span) {
            $table_attrs = [];
            foreach ($match_table as $match_row) {
                $row_attrs = [];
                $table_attrs[] = &$row_attrs; // Reference for direct population
                foreach ($match_row as $m) {
                    list($s, $e) = $m['span']('attrs'); // Span of 'attrs' group (relative to matched substring)

                    // ATTRS_MATCH_FUNCTION expects the full string it was matched against, and relative start/end.
                    // `string.encode('ascii', 'replace')` is for bytes, PHP strings are fine.
                    // The ATTRS_MATCH_FUNCTION from _spans.php should provide `captures`.
                    $attrs_match_obj = ATTRS_MATCH_FUNCTION($m['string'], $s, $e); // Use the full string of the cell's match as subject.
                    $captures = $attrs_match_obj['captures']; // Get the `captures` method from the match object.

                    $attrs_dict = [];
                    $attr_names = $captures('attr_name');
                    $attr_values = $captures('attr_value');

                    // zip behavior: iterate until one list is exhausted
                    $len = min(count($attr_names), count($attr_values));
                    for ($i = 0; $i < $len; $i++) {
                        $attrs_dict[$attr_names[$i]] = $attr_values[$i];
                    }
                    $row_attrs[] = $attrs_dict;
                }
                end($table_attrs)->current($row_attrs);
            }
            $table_data = _apply_attr_spans($table_attrs, $table_data);
        }

        // Handle row/column slicing
        if ($row === null) {
            if ($column === null) {
                return $table_data;
            }
            return array_column($table_data, $column); // PHP's array_column for specific column
        }
        if ($column === null) {
            return $table_data[$row];
        }
        return $table_data[$row][$column];
    }

    /**
     * Return a list of lists containing Cell objects.
     *
     * @param int|null $row Return the specified row only. Zero-based index.
     * @param int|null $column Return the specified column only. Zero-based index.
     * @param bool $span If is True, rearrange the result according to colspan and rowspan attributes.
     * @return array<array<Cell>>|array<Cell>|Cell
     */
    public function cells(
        ?int $row = null,
        ?int $column = null,
        bool $span = true
    ): array|Cell {
        $tbl_span = $this->_span_data; // Assuming _span_data is accessible. [start, end, type, content]
        $ss = $tbl_span[0]; // Global start offset of the table.
        $match_table = $this->_match_table(); // Call as method
        $shadow = $this->_shadow; // Table's own shadow string.

        $type_ = spl_object_hash($tbl_span); // Unique ID for this specific span data object
        $type_to_spans = $this->_type_to_spans;
        if (!isset($type_to_spans[$type_])) {
            $type_to_spans[$type_] = [];
        }
        $spans = &$type_to_spans[$type_]; // Reference to the list of spans for this specific type.

        $table_cells = [];
        $table_attrs = [];

        foreach ($match_table as $match_row) {
            $row_cells = [];
            $table_cells[] = &$row_cells; // Add by reference
            if ($span) {
                $row_attrs = [];
                $table_attrs[] = &$row_attrs; // Reference for direct population
            }

            foreach ($match_row as $m) {
                $header = (ord($m['group']('sep')) === ord('!')); // Check if separator is '!'
                list($ms, $me) = $m['span'](); // Full span of the cell match relative to table_shadow.

                $cell_span_global_start = $ss + $ms;
                $cell_span_global_end = $ss + $me;
                $cell_content_in_shadow = substr($shadow, $ms, $me - $ms); // Content from table's shadow

                $cell_span = [$cell_span_global_start, $cell_span_global_end, null, $cell_content_in_shadow];

                $attrs_match_obj = null;
                if ($span) {
                    list($s_attrs, $e_attrs) = $m['span']('attrs'); // Span of attrs within cell's matched string.
                    // ATTRS_MATCH_FUNCTION operates on the `cell_content_in_shadow` (m['string']),
                    // and its own relative start/end for the attributes portion.
                    $attrs_match_obj = ATTRS_MATCH_FUNCTION($m['string'], $s_attrs, $e_attrs);

                    $captures = $attrs_match_obj['captures']; // Get the `captures` method from the match object.
                    $attrs_dict = [];
                    $attr_names = $captures('attr_name');
                    $attr_values = $captures('attr_value');

                    $len = min(count($attr_names), count($attr_values));
                    for ($i = 0; $i < $len; $i++) {
                        $attrs_dict[$attr_names[$i]] = $attr_values[$i];
                    }
                    $row_attrs[] = $attrs_dict;
                }

                // Check if this cell_span already exists to reuse its reference in `spans`.
                $old_span = null;
                foreach ($spans as $existing_span) {
                    if ($existing_span[0] === $cell_span[0] && $existing_span[1] === $cell_span[1]) {
                        $old_span = $existing_span;
                        break;
                    }
                }

                if ($old_span === null) {
                    insort_right($spans, $cell_span, function ($item) {
                        return $item[0];
                    }); // Insert into sorted spans list
                } else {
                    $cell_span = $old_span; // Use existing reference
                }

                $row_cells[] = new Cell(
                    $this->_lststr, // The main string data object
                    $header,
                    $type_to_spans,
                    $cell_span, // The span data for this cell
                    $type_, // The unique type ID for this table's cells
                    $m, // The match object for the cell
                    $attrs_match_obj // The match object for attributes
                );
            }
            // Update the row in $table_cells with the filled $row_cells
            end($table_cells)->current($row_cells);
            if ($span) {
                end($table_attrs)->current($row_attrs);
            }
        }

        if (!empty($table_cells) && $span) {
            $table_cells = _apply_attr_spans($table_attrs, $table_cells);
        }

        // Handle row/column slicing
        if ($row === null) {
            if ($column === null) {
                return $table_cells;
            }
            // Return specific column from all rows
            return array_column($table_cells, $column);
        }
        if ($column === null) {
            return $table_cells[$row];
        }
        return $table_cells[$row][$column];
    }

    /**
     * Caption of the table. Support get and set.
     *
     * @return string|null
     */
    public function caption(): ?string
    {
        $m = CAPTION_MATCH($this->_shadow); // Call CAPTION_MATCH function
        if ($m) {
            return $this->__invoke($m['start']('caption'), $m['end']('caption')); // Access span via start/end
        }
        return null;
    }

    /**
     * @param string $newcaption The new caption string.
     */
    public function set_caption(string $newcaption): void
    {
        $shadow = $this->_shadow;
        $m = CAPTION_MATCH($shadow); // Call CAPTION_MATCH function

        if ($m) {
            $s = $m['end']('attrs'); // End of attrs
            if ($s === -1) { // If no attrs group, use end of preattrs
                $s = $m['end']('preattrs');
            }
            $this->offsetSet($s, $m['end']('caption') - $s, $newcaption);
            return;
        }

        // There is no caption. Create one.
        // `shadow.partition(b'\n')` splits at first newline.
        $parts = explode("\n", $shadow, 2);
        $h = $parts[0] ?? ''; // Before first newline
        $s = isset($parts[1]) ? "\n" : ''; // Newline itself
        // $t = $parts[1] ?? ''; // After first newline

        // Insert caption after the first line (after table-start and attrs).
        // This effectively inserts after `{|\n` or `{| attrs\n`.
        $this->insert(strlen($h) + strlen($s), '|+' . $newcaption . "\n");
    }

    /**
     * Return the match object for attributes of the table itself.
     *
     * @return array<string, mixed> The match array for table attributes.
     */
    protected function _attrs_match(): array
    {
        list($cache_match, $cache_string) = $this->_attrs_match_cache;
        $string = $this->string;

        if ($cache_string === $string) {
            return $cache_match;
        }

        $shadow = $this->_shadow;
        // ATTRS_MATCH operates on a substring: from index 2 ('{|') to the first newline.
        $first_newline_pos = strpos($shadow, "\n");
        if ($first_newline_pos === false) {
            // No newline, likely a malformed table with no rows.
            // Attributes would be between "{|" and end of string.
            $attrs_match = ATTRS_MATCH_FUNCTION($shadow, 2, strlen($shadow));
        } else {
            $attrs_match = ATTRS_MATCH_FUNCTION($shadow, 2, $first_newline_pos);
        }

        $this->_attrs_match_cache = [$attrs_match, $string];
        return $attrs_match;
    }

    /**
     * Caption attributes. Support get and set operations.
     *
     * @return string|null
     */
    public function caption_attrs(): ?string
    {
        $m = CAPTION_MATCH($this->_shadow); // Call CAPTION_MATCH function
        if ($m) {
            list($s, $e) = $m['span']('attrs'); // Span of 'attrs' group within caption match
            if ($s !== -1) {
                return $this->__invoke($s, $e);
            }
        }
        return null;
    }

    /**
     * @param string $attrs The new attributes string for the caption.
     */
    public function set_caption_attrs(string $attrs): void
    {
        $shadow = $this->_shadow;
        // `shadow.partition(b'\n')` splits at first newline.
        $parts = explode("\n", $shadow, 2);
        $h = $parts[0] ?? ''; // Part before first newline
        $s = isset($parts[1]) ? "\n" : ''; // The first newline itself

        $m = CAPTION_MATCH($shadow); // Call CAPTION_MATCH function

        if ($m === null) { // There is no caption-line, so create one with attrs
            // Insert after the first line (after table-start and table attrs).
            $this->insert(strlen($h) + strlen($s), '|+' . $attrs . "|\n");
        } else { // Caption exists, possibly with or without attrs
            $end_attrs = $m['end']('attrs'); // End of existing attrs
            if ($end_attrs !== -1) { // Existing attributes found, replace them
                // Replace from `m.end('preattrs')` to `end_attrs` with new attributes.
                // `m.end('preattrs')` is the position right before `|+\n`.
                $this->offsetSet($m['end']('preattrs'), $end_attrs - $m['end']('preattrs'), $attrs);
            }
            // If there's a caption but no attrs span, need to insert them.
            // This case needs to be handled by inserting `attrs|` before caption content.
            // Python's code is simpler: `self[m.end('preattrs') : end] = attrs` which works for existing attrs.
            // If no attrs exist, `end` would be -1.
            // The original Python `self[m.end('preattrs') : end]` assumes `end` could be a valid range start/end.
            // This might mean inserting `attrs|` right after `|+` and before caption text.
            // Let's refine for the "caption but no attrs" case:
            if ($m['group']('attrs') === null && $m['group']('caption') !== null) {
                // If caption exists but no attrs, insert attrs and a pipe after `|+`
                $insert_pos = $m['end']('preattrs'); // Position after `|+`
                $this->insert($insert_pos, $attrs . '|');
            }
        }
    }

    /**
     * Row attributes.
     *
     * @return array<array<string, string>> A list of dictionaries, each representing row attributes.
     */
    public function row_attrs(): array
    {
        $shadow = $this->_table_shadow(); // Call as method
        $string = $this->string;
        $attrs = [];

        // FIND_ROWS yields matches starting with `|-`.
        // Iterating over a generator.
        foreach (FIND_ROWS($shadow) as $row_match) {
            list($s, $e) = $row_match['span'](1); // Group 1 contains everything after `|-` up to newline

            // ATTRS_MATCH_FUNCTION operates on the `shadow` string,
            // with relative start and end positions for the row's attribute part.
            $attrs_match_obj = ATTRS_MATCH_FUNCTION($shadow, $s, $e);
            $spans_func = $attrs_match_obj['spans']; // Method to get spans for groups
            $captures_func = $attrs_match_obj['captures']; // Method to get captures for groups

            $row_attrs_dict = [];
            $attr_names_spans = $spans_func('attr_name');
            $attr_values_spans = $spans_func('attr_value');

            // Python's `zip` creates pairs.
            $len = min(count($attr_names_spans), count($attr_values_spans));
            for ($i = 0; $i < $len; $i++) {
                list($ns, $ne) = $attr_names_spans[$i];
                list($vs, $ve) = $attr_values_spans[$i];
                // Extract from the original string, not shadow, as `string` is the readable version.
                $row_attrs_dict[substr($string, $ns, $ne - $ns)] = substr($string, $vs, $ve - $vs);
            }
            $attrs[] = $row_attrs_dict;
        }
        return $attrs;
    }

    /**
     * Set attributes for all rows. Overwrites all existing attribute values.
     *
     * @param array<array<string, string>> $attrs A list of attribute dictionaries for each row.
     */
    public function set_row_attrs(array $attrs): void
    {
        // Python's `reversed([*zip(FIND_ROWS(self._table_shadow), attrs)])`
        // Combines matches with provided attrs, then reverses.
        // We need to iterate from last row to first to safely delete/insert.

        $shadow = $this->_table_shadow(); // Get the shadow for finding rows
        $all_row_matches = [];
        // Convert generator to array for `zip` and `reversed`
        foreach (FIND_ROWS($shadow) as $row_match) {
            $all_row_matches[] = $row_match;
        }

        // Pair row matches with provided attrs dictionaries
        $combined_data = array_map(null, $all_row_matches, $attrs);

        // Iterate in reverse
        foreach (array_reverse($combined_data) as list($row_match, $attrs_dict)) {
            // $row_match is a PHP match object (array), $attrs_dict is a PHP array
            if ($row_match === null || $attrs_dict === null) {
                continue; // Handle cases where arrays are not of same length
            }

            list($s, $e) = $row_match['span'](1); // Group 1 contains everything after `|-` up to newline

            $this->offsetUnset($s, $e - $s); // Delete existing attributes/content of the row's attribute line

            $new_attrs_string = '';
            foreach ($attrs_dict as $name => $value) {
                // Python's f-string: `f' {name}="{value}"' if value else f' {name}'`
                if ($value) {
                    $new_attrs_string .= sprintf(' %s="%s"', $name, $value);
                } else {
                    $new_attrs_string .= sprintf(' %s', $name);
                }
            }
            $this->insert($s, $new_attrs_string); // Insert new attributes at the start position
        }
    }
}


/**
 * Apply row and column spans and return table_data.
 *
 * @template T
 * @param array<array<array<string, string>>> $table_attrs List of row attributes.
 * @param array<array<T>> $table_data List of row data.
 * @return array<array<T|null>> The table data with spans applied (can contain nulls for empty spanned cells).
 */
function _apply_attr_spans(
    array $table_attrs,
    array $table_data
): array {
    // The following code is based on the table forming algorithm described
    // at http://www.w3.org/TR/html5/tabular-data.html#processing-model-1
    // Numeral comments indicate the steps in that algorithm.

    // 1, 2, 10
    $ycurrent = 0;
    $yheight = 0;
    $xwidth = 0;

    // 4. The table is initially empty.
    $table = [];

    // 11. downward_growing_cells
    $downward_growing_cells = []; // tuple: [cell_content, cell_x_start, cell_width]

    // 13, 18. Algorithm for processing rows
    foreach ($table_data as $row_index => $row) {
        $attrs_row = $table_attrs[$row_index] ?? []; // Get attributes for current row

        // 13.1 ycurrent is never greater than yheight
        while ($ycurrent >= $yheight) { // If current row pos is at or beyond table height
            $yheight++;
            $table[] = array_fill(0, $xwidth, null); // Add a new row to the table, filled with nulls
        }

        // 13.2
        $xcurrent = 0;

        // 13.3. The algorithm for growing downward-growing cells
        // Filter out cells that have finished growing before this row
        $new_downward_growing_cells = [];
        foreach ($downward_growing_cells as list($cell, $cellx, $width, $rowspan_remaining)) {
            if ($rowspan_remaining > 1) { // If cell still has rows to span
                $r = &$table[$ycurrent]; // Get reference to current table row
                for ($x = $cellx; $x < $cellx + $width; $x++) {
                    if (isset($r[$x])) {
                        // This slot is already occupied, which indicates a table model error or overlapping cells.
                        // Based on HTML5 spec, this is an error, cell values would overlap.
                        // The original Python allows this and overwrites. Let's do the same.
                    }
                    $r[$x] = $cell; // Place the spanning cell
                }
                $new_downward_growing_cells[] = [$cell, $cellx, $width, $rowspan_remaining - 1]; // Decrement rowspan
            }
        }
        $downward_growing_cells = $new_downward_growing_cells;

        // 13.4, 13.5, 13.16 - Loop for processing cells in current row
        foreach ($row as $cell_index => $current_cell) {
            $attrs = $attrs_row[$cell_index] ?? [];

            // 13.6
            while ($xcurrent < $xwidth && $table[$ycurrent][$xcurrent] !== null) {
                $xcurrent++;
            }

            // 13.7
            while ($xcurrent >= $xwidth) { // If current column pos is at or beyond table width
                $xwidth++;
                foreach ($table as &$r) { // Add a column to all existing rows
                    $r[] = null;
                }
            }

            // 13.8 colspan (default 1)
            $colspan = head_int($attrs['colspan'] ?? null);
            if ($colspan === 0) {
                // colspan="0" spans to the last column of the column group (colgroup).
                // For simplicity, treat 0 as spanning to the current max width for this implementation.
                // In a real browser, this involves colgroup concept.
                // Here, let's treat it as 1 or a very large number for stretching.
                // Given the original context implies it still behaves like 1 for parsing, stick to that.
                $colspan = 1;
            }

            // 13.9 rowspan (default 1)
            $rowspan = head_int($attrs['rowspan'] ?? null);

            // 13.10
            $cell_grows_downward = false;
            if ($rowspan === 0) {
                // rowspan="0" spans to the last row of the table.
                // This means it grows downward indefinitely.
                $cell_grows_downward = true;
                $rowspan = 1; // Treat as 1 for current row placement, then extend via downward_growing_cells.
            }

            // 13.11 Extend table width if needed
            if ($xwidth < $xcurrent + $colspan) {
                $xwidth = $xcurrent + $colspan;
                foreach ($table as &$r) {
                    // Extend rows to the new xwidth.
                    if (count($r) < $xwidth) {
                        $r = array_pad($r, $xwidth, null);
                    }
                }
            }

            // 13.12 Extend table height if needed
            if ($yheight < $ycurrent + $rowspan) {
                $yheight = $ycurrent + $rowspan;
                while (count($table) < $yheight) {
                    $table[] = array_fill(0, $xwidth, null);
                }
            }

            // 13.13 Assign cell to table slots
            for ($y = $ycurrent; $y < $ycurrent + $rowspan; $y++) {
                $r = &$table[$y];
                for ($x = $xcurrent; $x < $xcurrent + $colspan; $x++) {
                    $r[$x] = $current_cell;
                }
            }

            // 13.14
            if ($cell_grows_downward) {
                // Store cell, its x-start, width, and remaining rowspan (0 means indefinite growth)
                $downward_growing_cells[] = [$current_cell, $xcurrent, $colspan, 0];
            }

            // 13.15
            $xcurrent += $colspan;
        }

        // 13.16 (Loop end)
        $ycurrent++;
    }

    // 14. The algorithm for ending a row group (continuation of downward growing cells)
    // 14.1
    while ($ycurrent < $yheight) {
        // 14.1.1 Run the algorithm for growing downward-growing cells.
        $new_downward_growing_cells = [];
        foreach ($downward_growing_cells as list($cell, $cellx, $width, $rowspan_remaining)) {
            if ($rowspan_remaining === 0 || $rowspan_remaining > 1) { // Still growing (indefinitely or more rows)
                $r = &$table[$ycurrent];
                for ($x = $cellx; $x < $cellx + $width; $x++) {
                    $r[$x] = $cell;
                }
                if ($rowspan_remaining > 1) {
                    $new_downward_growing_cells[] = [$cell, $cellx, $width, $rowspan_remaining - 1];
                } elseif ($rowspan_remaining === 0) {
                    $new_downward_growing_cells[] = [$cell, $cellx, $width, 0]; // Continue indefinite growth
                }
            }
        }
        $downward_growing_cells = $new_downward_growing_cells;

        // 14.2.2
        $ycurrent++;
    }
    // 14.2 downward_growing_cells = [] (handled by reassigning $new_downward_growing_cells)

    // 20. Final check for empty slots is typically for validation and not part of filling the table.

    return $table;
}


/**
 * Return the new position to lstrip the shadow.
 *
 * @param string $shadow The table shadow string.
 * @param int $pos The starting position.
 * @return int The new position after stripping.
 */
function _lstrip_increase(string $shadow, int $pos): int
{
    $length = strlen($shadow);
    // Python: `{0, 9, 10, 13, 32}` correspond to `\0`, `\t`, `\n`, `\r`, ` `.
    $whitespace_chars = ["\0", "\t", "\n", "\r", " "];
    while ($pos < $length && in_array($shadow[$pos], $whitespace_chars)) {
        $pos++;
    }
    return $pos;
}

/**
 * Return the position after the starting row separator line.
 * Also skips any semi-caption lines before and after the separator.
 *
 * @param string $shadow The table shadow string.
 * @param int $pos The starting position.
 * @return int The new position.
 */
function _row_separator_increase(string $shadow, int $pos): int
{
    // `FIRST_NON_CAPTION_LINE` finds the start of the next significant line.
    $search_result = FIRST_NON_CAPTION_LINE($shadow, $pos); // Call FIRST_NON_CAPTION_LINE function
    $ncl = $search_result['start'](); // Start of the next line that is not a caption line

    $lsp = _lstrip_increase($shadow, $ncl); // Strip leading whitespace from this line

    // Check for `|-` row separator.
    while (substr($shadow, $lsp, 2) === '|-') {
        // We are on a row separator line.
        // Find the newline after `lsp + 2` (after `|-`).
        $pos = strpos($shadow, "\n", $lsp + 2);
        if ($pos === false) {
            // No newline found after `|-`, likely end of string.
            return strlen($shadow); // Return end of string
        }
        // Find the start of the next non-caption line after this separator.
        $search_result = FIRST_NON_CAPTION_LINE($shadow, $pos);
        $pos = $search_result['start']();
        $lsp = _lstrip_increase($shadow, $pos);
    }
    return $pos;
}

/**
 * Helper function to create a PHP-friendly match object from `preg_match` results.
 * This function should mirror the behavior of Python's `re.Match` object for used methods.
 * This is a copy of the helper in `_cell.py`, adjusted for use here if needed.
 *
 * @param string $pattern The regex pattern string (already `rc` formatted).
 * @param string $subject The string to search.
 * @param int $offset The offset from which to start the match in the subject.
 * @return array|null An array representing the match object, or null if no match.
 */
function create_match_object_for_cell_parsing(string $pattern, string $subject, int $offset): ?array
{
    $matches = [];
    $result = preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset);

    if ($result === 1) {
        $matchObject = [];
        // Store raw matches for direct access by numeric index or named keys
        foreach ($matches as $key => $value) {
            if (is_int($key)) {
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            } elseif (is_string($key)) {
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            }
        }

        $matchObject['group'] = function ($group) use ($matchObject) {
            return $matchObject[$group] ?? null;
        };
        $matchObject['start'] = function ($group = 0) use ($matchObject) {
            return $matchObject['offset_' . $group] ?? -1;
        };
        $matchObject['end'] = function ($group = 0) use ($matchObject) {
            if (isset($matchObject['offset_' . $group]) && isset($matchObject[$group])) {
                return $matchObject['offset_' . $group] + strlen($matchObject[$group]);
            }
            return -1;
        };
        $matchObject['span'] = function ($group = 0) use ($matchObject) {
            $start = $matchObject['start']($group);
            $end = $matchObject['end']($group);
            return [$start, $end];
        };

        $matchObject['string'] = $subject; // The full subject string it was matched against

        return $matchObject;
    }
    return null;
}
