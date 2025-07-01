<?php

declare(strict_types=1);

namespace Wtp\Parser;

use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans class is in Wtp\Parser\_spans
use Wtp\Parser\_spans\parse_to_spans; // Assuming parse_to_spans function is in Wtp\Parser\_spans
use Wtp\Parser\_wikitext_utils\SPAN_PARSER_TYPES; // Assuming SPAN_PARSER_TYPES constant is in Wtp\Parser\_wikitext_utils
use Wtp\Parser\_wikitext_utils\DEAD_SPAN; // Assuming DEAD_SPAN constant is in Wtp\Parser\_wikitext_utils
use Wtp\Node\Parameter; // Import Parameter class

/**
 * TODO: هذه الوظائف بحاجة إلى تحويل يدوي من مكتبة bisect في Python.
 * المرجع: https://docs.python.org/3/library/bisect.html
 *
 * `bisect_left` finds insertion point for x in a to maintain sorted order.
 * `bisect_right` is similar but returns an insertion point which comes after (to the right of) any existing entries of x in a.
 * `insort_right` inserts an item into a list in sorted order, keeping items with equal value to the right.
 * For arrays, comparison should be based on the first element.
 */
function bisect_left(array $haystack, array $needle, ?int $lo = 0, ?int $hi = null): int
{
    if ($hi === null) {
        $hi = count($haystack);
    }
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1; // Integer division
        if ($haystack[$mid][0] < $needle[0]) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo;
}

function bisect_right(array $haystack, array $needle, ?int $lo = 0, ?int $hi = null): int
{
    if ($hi === null) {
        $hi = count($haystack);
    }
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1; // Integer division
        if ($needle[0] < $haystack[$mid][0]) {
            $hi = $mid;
        } else {
            $lo = $mid + 1;
        }
    }
    return $lo;
}

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
 * Class WikiTextBase
 *
 * Base class for objects representing wikitext segments.
 */
class WikiTextBase implements \ArrayAccess
{
    // __slots__ is Python-specific, no direct PHP equivalent for memory optimization.

    // In subclasses of WikiText _type is used as the key for _type_to_spans
    // Therefore: self._span can be found in self._type_to_spans[self._type].
    // The following class attribute acts as a default value.
    protected string $_type = 'WikiTextBase'; // Changed to protected as it's modified by subclasses.

    protected TypeToSpans $_type_to_spans;
    protected array $_lststr; // MutableSequence[str] represented as array<string> in PHP
    protected array $_span_data; // list[int, int, mixed, string] (start, end, match_obj/null, content_string/byte_array)

    /**
     * @param string|array<string> $string The string to be parsed or a list containing the string of the parent object.
     * @param TypeToSpans|null $_typeToSpans If the lststr is already parsed, pass its _type_to_spans property to avoid parsing it again.
     */
    public function __construct(
        string|array $string,
        ?TypeToSpans $_typeToSpans = null
    ) {
        global $SPAN_PARSER_TYPES; // Access global constant

        if ($_typeToSpans !== null) {
            $this->_type_to_spans = $_typeToSpans;
            // If string is already an array (mutable sequence), use it directly.
            // Otherwise, wrap it in an array to represent MutableSequence.
            $this->_lststr = is_array($string) ? $string : [$string];
            return;
        }

        // Initialize _lststr as a mutable array containing the string.
        $this->_lststr = [$string];

        // Python's `bytearray(string, 'ascii', 'replace')`
        $byte_array_string = $string; // Keep original string for context.
        $byte_array = new \ArrayObject(array_map('ord', str_split($string))); // Simulate bytearray for in-place modifications

        $span = $this->_span_data = [0, strlen($string), null, $byte_array_string]; // Store original string in span[3] (like byte_array)
        $type = $this->_type;

        // Special handling for types in SPAN_PARSER_TYPES
        if (in_array($type, $SPAN_PARSER_TYPES)) {
            // These types need their outer markers (`{{`, `}}`, `[[`, `]]`, `{{{`, `}}}`) masked before parsing
            // to prevent the parser from "seeing" them and marking the whole span.
            // After parsing, the masks are removed. This is a complex workaround in Python.

            // Note: `type(self) is Parameter` and other specific types
            // This requires `_parser_function.py`, `_wikilink.py`, `_tag.py` etc. to be loaded
            // or for this logic to be encapsulated in a way that respects class hierarchy.
            // The `if (is_a($this, Parameter::class))` will dynamically check the class.

            if (is_a($this, Parameter::class)) { // Parameter class uses `{{{` `}}}`
                $head_len = 3; // Length of '{{{'
                $tail_len = 3; // Length of '}}}'
                $mask_char = '_'; // Character to mask with
            } else { // For other types like WikiLink, Template, ParserFunction etc.
                     // WikiLink: `[[...]]`, Template/ParserFunction: `{{...}}`
                $head_len = 2; // Length of '{{' or '[['
                $tail_len = 2; // Length of '}}' or ']]'
                $mask_char = '_'; // Or 'X' depending on what is done for actual parse_to_spans
                                   // The Python code uses `b'__'` or `b'_'`
            }

            // Mask the delimiters in the bytearray copy for parsing
            $original_head = array_slice($byte_array->getArrayCopy(), 0, $head_len);
            $original_tail = array_slice($byte_array->getArrayCopy(), -$tail_len);

            for ($i = 0; $i < $head_len; $i++) {
                $byte_array[$i] = ord($mask_char);
            }
            for ($i = 0; $i < $tail_len; $i++) {
                $byte_array[strlen($string) - $tail_len + $i] = ord($mask_char);
            }

            // Convert bytearray-like object back to string for parse_to_spans
            $masked_string = implode('', array_map('chr', $byte_array->getArrayCopy()));

            $type_to_spans = $this->_type_to_spans = parse_to_spans($masked_string);

            // Add `self._span` back manually, as it was masked during parsing
            // Python's `insert(0, span)` ensures it's at the start.
            // Using `array_unshift` to insert at the beginning of the type's span list.
            if (!isset($type_to_spans[$type])) {
                $type_to_spans[$type] = [];
            }
            array_unshift($type_to_spans[$type], $span);

            // Restore the original head and tail in the bytearray copy.
            for ($i = 0; $i < $head_len; $i++) {
                $byte_array[$i] = $original_head[$i];
            }
            for ($i = 0; $i < $tail_len; $i++) {
                $byte_array[strlen($string) - $tail_len + $i] = $original_tail[$i];
            }

            // Update span[3] (which holds the byte_array/content) to contain the *restored* content.
            // This is effectively `shadow = span_data[3] = bytearray(...)` in Python's _shadow.
            $this->_span_data[3] = implode('', array_map('chr', $byte_array->getArrayCopy()));

        } else {
            // Not a SPAN_PARSER_TYPE, so parse the whole string directly.
            $string_for_parsing = (string)$string; // Ensure it's a string
            $type_to_spans = $this->_type_to_spans = parse_to_spans($string_for_parsing);

            // For WikiTextBase (and subclasses not in SPAN_PARSER_TYPES), `self._span` is the whole string.
            // It gets added to its own type.
            if (!isset($type_to_spans[$type])) {
                $type_to_spans[$type] = [];
            }
            $type_to_spans[$type][] = $span; // Append the full span.
        }
    }

    // --- ArrayAccess Interface Methods ---
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // $offset can be int or slice (handled by _check_index and then this method is called internally)
        // If $offset is a slice, __setitem__ calls _check_index first, then performs replacement.
        // We need to implement __setitem__ logic here, as this is the direct entry point for ArrayAccess.

        // This mirrors __setitem__ from Python
        if ($offset === null) { // Equivalent to `arr[] = value` for appending
            // Not supported as this is string manipulation.
            throw new \BadMethodCallException("Appending to WikiTextBase is not supported via ArrayAccess.");
        }

        // Handle slice assignment, e.g. `$this[start:stop] = value`
        if (is_array($offset) && count($offset) === 2 && is_int($offset[0]) && (is_int($offset[1]) || $offset[1] === null)) {
            // Custom slice-like array: [$start, $stop]
            $abs_start = $this->_span_data[0] + $offset[0];
            $abs_stop = $this->_span_data[0] + ($offset[1] ?? (strlen($this->string()) + $offset[0])); // Handle null stop as end of current string
        } elseif (is_int($offset)) { // Single index assignment, e.g. `$this[index] = char`
            $abs_start = $this->_span_data[0] + $offset;
            $abs_stop = $abs_start + 1;
        } else {
            throw new \InvalidArgumentException("Invalid offset type for WikiTextBase assignment.");
        }

        $this->_replace_internal($abs_start, $abs_stop, (string)$value);
    }

    public function offsetExists(mixed $offset): bool
    {
        // Checks if an index or slice is within the bounds of the current object's string.
        list($ss, $se, $_, $_) = $this->_span_data;
        $relative_length = $se - $ss;

        if (is_int($offset)) {
            if ($offset < 0) {
                $offset += $relative_length;
            }
            return $offset >= 0 && $offset < $relative_length;
        } elseif (is_array($offset) && count($offset) === 2) { // Slice-like array
            $start = $offset[0] ?? 0;
            $stop = $offset[1] ?? $relative_length;

            if ($start < 0) $start += $relative_length;
            if ($stop < 0) $stop += $relative_length;

            return $start >= 0 && $stop <= $relative_length && $start <= $stop;
        }
        return false;
    }

    public function offsetUnset(mixed $offset): void
    {
        // This mirrors __delitem__ from Python
        if (is_array($offset) && count($offset) === 2 && is_int($offset[0]) && (is_int($offset[1]) || $offset[1] === null)) {
            // Custom slice-like array: [$start, $stop]
            $abs_start = $this->_span_data[0] + $offset[0];
            $abs_stop = $this->_span_data[0] + ($offset[1] ?? (strlen($this->string()) + $offset[0])); // Handle null stop as end of current string
        } elseif (is_int($offset)) { // Single index unsetting
            $abs_start = $this->_span_data[0] + $offset;
            $abs_stop = $abs_start + 1;
        } else {
            throw new \InvalidArgumentException("Invalid offset type for WikiTextBase unsetting.");
        }

        $this->_delete_internal($abs_start, $abs_stop);
    }

    public function offsetGet(mixed $offset): mixed
    {
        // This mirrors __call__ from Python which functions as `self(start, stop)`
        // `offsetGet` in PHP is for `[]` access, not `()` calls.
        // We need to decide if `offsetGet` should be for single characters or if it needs to support slices.
        // PHP's native array/string access with `[]` on an ArrayAccess object only passes `int` offsets.
        // So `__call` is still needed for slicing.

        if (is_int($offset)) {
            if ($offset < 0) {
                $offset += strlen($this->string());
            }
            return $this->string()[$offset] ?? null;
        }
        throw new \BadMethodCallException("Slice access not supported via offsetGet. Use __call for slicing.");
    }
    // --- End ArrayAccess Interface Methods ---


    // __str__ method
    public function __toString(): string
    {
        return $this->string();
    }

    // __repr__ method
    public function __debugInfo(): array
    {
        return [
            'type' => (new \ReflectionClass($this))->getShortName(),
            'string' => $this->string(),
        ];
    }

    /**
     * Return `self.string[start]` or `self.string[start:stop]`.
     * This method mimics the `__call__` behavior from Python.
     *
     * @param int $start
     * @param int|null|false $stop If `false`, returns single char. If `null`, returns to end.
     * @param int|null $step Not implemented for slicing.
     * @return string
     */
    public function __invoke(int $start, int|null|bool $stop = false, ?int $step = null): string
    {
        if ($step !== null) {
            throw new \NotImplementedException('step is not implemented for WikiTextBase slicing.');
        }

        list($ss, $se, $_, $_) = $this->_span_data;
        $full_string = $this->_lststr[0]; // Get the main string

        if ($stop === false) { // Single character access
            $abs_index = ($start >= 0) ? ($ss + $start) : ($se + $start);
            return $full_string[$abs_index] ?? ''; // Return empty string if index out of bounds
        }

        $abs_start = ($start === null) ? $ss : (($start >= 0) ? ($ss + $start) : ($se + $start));
        $abs_end = ($stop === null) ? $se : (($stop >= 0) ? ($ss + $stop) : ($se + $stop));

        // Adjust for Python's slice behavior (slice `[start:stop]` effectively means `up to but not including stop`)
        // PHP's substr is inclusive of start, and exclusive of end based on length.
        $length = $abs_end - $abs_start;
        if ($length < 0) {
            return ''; // Empty slice if start is after stop
        }
        return substr($full_string, $abs_start, $length);
    }


    /**
     * Internal method to perform the string replacement and update spans.
     * Used by `offsetSet`.
     *
     * @param int $abs_start Absolute start index in the root string.
     * @param int $abs_stop Absolute end index in the root string.
     * @param string $value The string to insert.
     */
    protected function _replace_internal(int $abs_start, int $abs_stop, string $value): void
    {
        $lststr = $this->_lststr;
        $lststr[0] = substr($lststr[0], 0, $abs_start) . $value . substr($lststr[0], $abs_stop);

        // Close sub-spans within the replaced range (mark them as dead/invalid).
        $this->_close_subspans($abs_start, $abs_stop);

        $len_change = strlen($value) - ($abs_stop - $abs_start);

        if ($len_change > 0) {
            $this->_insert_update($abs_start, $len_change);
        } elseif ($len_change < 0) {
            $this->_del_update(
                rmstart: $abs_stop + $len_change, // New stop position after deletion
                rmstop: $abs_stop // Old stop position
            );
        }

        // Add newly added spans contained in the `value` string.
        $type_to_spans = $this->_type_to_spans;
        $new_spans_data = parse_to_spans($value); // This mutates $value if passed by reference.
                                                   // Python's `bytearray` here would be passed by reference and mutated.
                                                   // In PHP, passing $value directly will not mutate it, as it's a string.
                                                   // `parse_to_spans` might need to be adjusted to take a mutable buffer or return a new one.
                                                   // For now, let's assume `parse_to_spans` receives a copy and returns new spans.

        foreach ($new_spans_data as $type_name => $spans_list) {
            if (!isset($type_to_spans[$type_name])) {
                $type_to_spans[$type_name] = [];
            }
            foreach ($spans_list as $s, $e, $m, $ba) {
                // Adjust offsets for new spans to be absolute in the main string.
                try {
                    // insort_right expects the span `[s, e, m, ba]`
                    insort_right($type_to_spans[$type_name], [$abs_start + $s, $abs_start + $e, $m, $ba]);
                } catch (\TypeError $e) {
                    // Python's `TypeError` implies comparing `Match` objects.
                    // This means a span with the same start/end already exists.
                    // For PHP, if a span is identical, just skip insertion.
                    // This means `insort_right` comparison needs to ensure it doesn't try to compare the 'Match' object.
                    // Our `insort_right` currently only compares on $item[0] (start offset).
                    // So this catch block might not be strictly needed unless `insort_right` tries deep comparison.
                }
            }
        }
    }

    /**
     * Internal method to perform string deletion and update spans.
     * Used by `offsetUnset`.
     *
     * @param int $start Absolute start index in the root string.
     * @param int $stop Absolute end index in the root string.
     */
    protected function _delete_internal(int $start, int $stop): void
    {
        $lststr = $this->_lststr;
        $lststr[0] = substr($lststr[0], 0, $start) . substr($lststr[0], $stop);

        $this->_del_update($start, $stop);
    }

    /**
     * Insert the given string before the specified index.
     *
     * @param int $index Absolute index in the root string where to insert.
     * @param string $string The string to insert.
     */
    public function insert(int $index, string $string): void
    {
        list($ss, $se, $_, $_) = $this->_span_data;
        $lststr = $this->_lststr;
        $lststr0 = $lststr[0]; // The main string

        // Adjust index to be absolute in the current object's string bounds.
        // Python's logic: if index is negative, count from end of own span.
        // If index is outside bounds (too large), cap it at end of own span.
        $relative_length = $se - $ss;
        $absolute_insert_pos = 0;
        if ($index < 0) {
            $adjusted_relative_index = $index + $relative_length;
            $absolute_insert_pos = $ss + max(0, $adjusted_relative_index);
        } elseif ($index > $relative_length) {
            $absolute_insert_pos = $se; // Insert at the end of the current object's string segment
        } else {
            $absolute_insert_pos = $ss + $index;
        }

        // Update lststr
        $lststr[0] = substr($lststr0, 0, $absolute_insert_pos) . $string . substr($lststr0, $absolute_insert_pos);
        $string_len = strlen($string);

        // Update spans
        $this->_insert_update($absolute_insert_pos, $string_len);

        // Remember newly added spans by the string.
        $type_to_spans = $this->_type_to_spans;
        $byte_array_for_new_spans = $string; // String itself will be used for content, no explicit bytearray needed.

        foreach (parse_to_spans($byte_array_for_new_spans) as $type_name => $spans_list) {
            if (!isset($type_to_spans[$type_name])) {
                $type_to_spans[$type_name] = [];
            }
            foreach ($spans_list as $s, $e, $m, $ba) {
                // `ba` (byte_array in Python) might be used to reconstruct the original segment.
                // In PHP, we just store the string segment.
                $content = substr($string, $s, $e - $s);
                insort_right(
                    $type_to_spans[$type_name],
                    [$absolute_insert_pos + $s, $absolute_insert_pos + $e, $m, $content]
                );
            }
        }
    }

    /**
     * Return the span of self relative to the start of the root node.
     *
     * @return array<int> [start_offset, end_offset]
     */
    public function span(): array
    {
        return [$this->_span_data[0], $this->_span_data[1]];
    }

    /**
     * Return str(self). Support get, set, and delete operations.
     *
     * @return string
     */
    public function string(): string
    {
        list($start, $end, $_, $_) = $this->_span_data;
        return substr($this->_lststr[0], $start, $end - $start);
    }

    /**
     * @param string $newstring The new string value.
     */
    public function set_string(string $newstring): void
    {
        $this->offsetSet([0, null], $newstring); // Equivalent to `self[:] = newstring`
    }

    /**
     * Deletes the string content.
     */
    public function delete_string(): void
    {
        $this->offsetUnset([0, null]); // Equivalent to `del self[:]`
    }

    /**
     * Return all the sub-span for a given type, including self._span.
     *
     * @param string $type The type of spans to return.
     * @return array<array<mixed>>
     */
    protected function _subspans(string $type): array
    {
        return $this->_type_to_spans[$type] ?? []; // Return empty array if type not found
    }

    /**
     * Close all sub-spans within the given (start, stop) range by marking them as DEAD_SPAN.
     *
     * @param int $start Absolute start index in the root string.
     * @param int $stop Absolute end index in the root string.
     */
    protected function _close_subspans(int $start, int $stop): void
    {
        global $DEAD_SPAN; // Access global constant

        list($ss, $se, $_, $_) = $this->_span_data; // Current object's span.

        foreach ($this->_type_to_spans as $type_name => &$spans) { // Use reference to modify original list
            // Find start and end indices of spans that might overlap with the deletion range.
            $b = bisect_left($spans, [$start]);
            $e_idx = bisect_right($spans, [$stop], $b); // Start search from $b for efficiency

            // Iterate backwards to safely remove elements by index from the list.
            for ($i = $e_idx - 1; $i >= $b; $i--) {
                list($s, $e, $_, $_) = $spans[$i];
                if ($s >= $start && $e <= $stop) { // If the sub-span is entirely within the deleted range
                    // Check if it's not the current object's own span (if this method is used by a child class).
                    // Python: `if ss != s or se != e` checks if it's not the exact span of `self`.
                    // This is for `WikiTextBase` itself. If a child class calls it, `ss` and `se`
                    // refer to the child's span.
                    if (!($ss === $s && $se === $e && $type_name === $this->_type)) {
                         // Mark as dead span by replacing its content directly.
                         // PHP's array_splice is better for removal, but direct assignment is okay for a fixed `DEAD_SPAN`.
                        $spans[$i] = $DEAD_SPAN; // Set the specific span entry to DEAD_SPAN
                    }
                }
            }
            // After marking, one might need to filter out DEAD_SPANs explicitly later if the array becomes sparse.
            // Or just leave them and filter them when iterating/accessing.
        }
    }

    /**
     * Update self._type_to_spans according to the removed span.
     * This is a complex logic that adjusts offsets of all subsequent spans.
     *
     * @param int $rmstart Absolute start index of the removed range.
     * @param int $rmstop Absolute end index of the removed range.
     */
    protected function _del_update(int $rmstart, int $rmstop): void
    {
        global $DEAD_SPAN; // Access global constant
        $rmlength = $rmstop - $rmstart;

        foreach ($this->_type_to_spans as &$spans) { // Use reference to modify original lists
            // Iterate backwards, similar to Python's `while i >= 0: ... break ... while True: ... break`.
            // This is a direct translation of the Python `_del_update` loop logic.
            // The Python logic is optimized for bisecting and then iterating in a specific way.

            // Find the starting point for iteration
            $i = count($spans) - 1;
            while ($i >= 0) {
                $span = &$spans[$i]; // Get reference to the current span
                list($s, $e, $_, $_) = $span;

                if ($s === null || $s === $DEAD_SPAN[0]) { // Skip already dead spans
                    $i--;
                    continue;
                }

                if ($rmstop <= $s) {
                    // Case 1: Removed range is entirely before the current span.
                    // Shift the span to the left.
                    $span[0] -= $rmlength;
                    $span[1] -= $rmlength;
                    // Invalidate cached content/match data as original offsets are now wrong.
                    $span[2] = null; // Match object
                    $span[3] = null; // Byte array/content string
                    $i--;
                    continue;
                } elseif ($rmstart <= $s && $rmstop < $e) {
                    // Case 2: Removed range starts before or at current span, and ends before current span ends.
                    // This means current span's start is within or after removed range, and its end is after removed range.
                    // Shrink the span and shift it.
                    $span[0] = $rmstart; // New start is where deletion began
                    $span[1] -= $rmlength; // New end is shifted
                    $span[2] = null;
                    $span[3] = null;
                    $i--;
                    continue;
                } elseif ($rmstart <= $s && $rmstop >= $e) {
                    // Case 3: Removed range completely contains or starts before and ends at or after current span.
                    // This span is effectively deleted. Mark it as DEAD_SPAN.
                    $spans[$i] = $DEAD_SPAN; // Assign DEAD_SPAN directly
                    $i--;
                    continue;
                } elseif ($e <= $rmstart) {
                    // Case 4: Current span is entirely before the removed range. No change to this span.
                    $i--;
                    continue;
                } elseif ($s < $rmstart && $e > $rmstop) {
                    // Case 5: Removed range is entirely within the current span.
                    // Shrink the current span from both sides (only end changes here in terms of shift).
                    $span[1] -= $rmlength;
                    $span[2] = null;
                    $span[3] = null;
                    $i--;
                    continue;
                } elseif ($s < $rmstart && $rmstop >= $e) {
                    // Case 6: Removed range overlaps start of current span and extends past or to its end.
                    // Current span's start is before removed range, its end is within or before removed range.
                    // Shrink from the end (end shifts).
                    $span[1] = $rmstart;
                    $span[2] = null;
                    $span[3] = null;
                    $i--;
                    continue;
                }

                // If none of the above, it means `s < rmstart` but also `e > rmstart`.
                // This implies partial overlap or being completely contained.
                // The `while True` loop and `break` conditions in Python are a bit tricky.
                // The `else: continue` after inner `break` means continue outer loop if inner loop completes without break.
                // PHP's `foreach` does not have `else` on loops.
                // The current structure tries to simplify this.
                $i--; // Fallback to next span if no specific case matched.
            }
        }
        // After updating spans, filter out DEAD_SPANs.
        foreach ($this->_type_to_spans as $type_name => &$spans) {
            $spans = array_filter($spans, fn($s) => $s !== $DEAD_SPAN);
            $spans = array_values($spans); // Re-index array
        }
    }


    /**
     * Update self._type_to_spans according to the added length.
     *
     * @param int $index Absolute index where insertion occurred.
     * @param int $length Length of the inserted string.
     */
    protected function _insert_update(int $index, int $length): void
    {
        list($ss, $se, $_, $_) = $this->_span_data; // Current object's span.

        foreach ($this->_type_to_spans as $span_type => &$spans) { // Use reference to modify original lists
            foreach ($spans as &$span) { // Use reference to modify individual span array
                list($s0, $s1, $_, $_) = $span;

                if ($s0 === null || $s0 === $DEAD_SPAN[0]) { // Skip dead spans
                    continue;
                }

                // If insertion point is before the span's end (or at the end for current object's span)
                // This includes spans that start after the insertion point, or overlap it.
                if ($index < $s1 || ($s1 === $index && $index === $se)) {
                    $span[1] += $length; // Extend the end of the span.
                    $span[3] = null; // Invalidate cached content.

                    // If insertion is before the span's start, or at its start but it's not the current object's span,
                    // shift the start of the span.
                    // `self_span is not span` means not the exact same array object in Python.
                    // `span_type != 'WikiText'` means `span_type` is not the special root type.
                    // Here, `span_type != 'WikiTextBase'` for the base class.
                    if ($index < $s0 || (
                        $s0 === $index &&
                        ($span !== $this->_span_data) && // If it's not the exact object's _span_data
                        $span_type !== 'WikiTextBase' // If it's not the root WikiTextBase itself being updated
                    )) {
                        $span[0] += $length; // Shift the start of the span.
                    }
                }
            }
        }
    }


    /**
     * Calculates the nesting level of the current object based on parent types.
     *
     * @param array<string> $parent_types A list of type names (e.g., ['Template', 'ParserFunction']) to consider as parents.
     * @return int
     */
    protected function _nesting_level(array $parent_types): int
    {
        list($ss, $se, $_, $_) = $this->_span_data; // Current object's global span.
        $level = 0;
        $type_to_spans = $this->_type_to_spans;

        foreach ($parent_types as $type_name) {
            $spans_of_type = $type_to_spans[$type_name] ?? [];
            // Find all spans of `type_name` that start before or at `ss + 1` (allowing for zero-width spans that start at ss).
            $b_idx = bisect_right($spans_of_type, [$ss + 1]);

            // Iterate backwards from `b_idx - 1` to find potential containing parents.
            for ($i = $b_idx - 1; $i >= 0; $i--) {
                list($s, $e, $_, $_) = $spans_of_type[$i];
                // Check if the current object's span is *contained* within this span.
                // `se <= e` (child ends before or at parent end)
                if ($ss >= $s && $se <= $e) {
                    $level++;
                }
            }
        }
        return $level;
    }


    /**
     * Returns the start and end offsets of the content within the current object.
     * Default implementation returns the entire span. Subclasses override for specific content.
     *
     * @return array<int> [content_start_offset_relative_to_self, content_end_offset_relative_to_self]
     */
    protected function _content_span(): array
    {
        // Default: entire span is content.
        return [0, $this->__len()]; // Call __len__ method.
    }


    /**
     * Return a copy of self.string with specific sub-spans replaced for parsing.
     *
     * Comments blocks are replaced by spaces. Other sub-spans are replaced
     * by underscores.
     *
     * The replaced sub-spans are: (
     * 'Template', 'WikiLink', 'ParserFunction', 'ExtensionTag',
     * 'Comment',
     * )
     *
     * This function is called upon extracting tables or extracting the data
     * inside them.
     *
     * @return string The mutated shadow string.
     */
    protected function _shadow(): string
    {
        global $SPAN_PARSER_TYPES; // Access global constant

        list($ss, $se, $match_obj, $cached_shadow) = $this->_span_data; // Get current object's span data

        if ($cached_shadow !== null && $cached_shadow !== false) { // Assuming cached_shadow is string or false, not ArrayObject.
            // Python's `bytearray[:]` means a copy.
            // PHP strings are immutable, so return a copy.
            return $cached_shadow;
        }

        // Create a mutable copy of the string segment for modification.
        $shadow = substr($this->_lststr[0], $ss, $se - $ss);

        // Python's `span_data[3] = bytearray(...)` updates the `ba` in the span data.
        // We will update `_span_data[3]` with this new shadow string after modifications.

        // If the current object's type is one of the SPAN_PARSER_TYPES
        // (meaning it's a WikiLink, Template, ParserFunction, etc.)
        if (in_array($this->_type, $SPAN_PARSER_TYPES)) {
            list($cs, $ce) = $this->_content_span(); // Get content span relative to self
            $original_head = substr($shadow, 0, $cs);
            $original_tail = substr($shadow, $ce);

            // Replace head and tail with underscores for internal parsing
            $shadow = str_repeat('_', $cs) . substr($shadow, $cs, $ce - $cs) . str_repeat('_', strlen($shadow) - $ce);

            // Recursively parse the shadow string. This will modify `shadow` in place by marking inner elements.
            // `parse_to_spans` expects a string that it can mutate internally (e.g., replace chars).
            // This is the core of the masking logic.
            parse_to_spans($shadow); // Pass by reference

            // Restore head and tail
            $shadow = $original_head . substr($shadow, $cs, $ce - $cs) . $original_tail;
        } else {
            // For other types (like WikiTextBase, Section, Table, etc.)
            // parse the whole shadow.
            parse_to_spans($shadow); // Pass by reference
        }

        // Cache the result in _span_data[3] for future calls.
        $this->_span_data[3] = $shadow;
        return $shadow;
    }

    /**
     * Create the arguments for the parse function used in pformat method.
     * Only return sub-spans and change them to fit the new scope, i.e self.string.
     *
     * @return TypeToSpans
     */
    protected function _inner_type_to_spans_copy(): TypeToSpans
    {
        list($ss, $se, $_, $_) = $this->_span_data; // Current object's global span.
        $new_type_to_spans = new TypeToSpans();

        foreach ($this->_type_to_spans as $type_name => $spans) {
            $filtered_spans = [];
            // Find spans that are children of the current object.
            $b_idx = bisect_right($spans, [$ss]);
            $e_idx = bisect_right($spans, [$se], $b_idx);

            foreach ($spans as $index => $span_item) {
                if ($index >= $b_idx && $index < $e_idx) {
                     // Check if span is actually within current object's range.
                     // Python's slice [bisect_right(spans, [ss]) : bisect_right(spans, [se])]
                     // selects spans whose start is >= ss and end is <= se.
                    list($s, $e, $m, $ba) = $span_item;
                    if ($s >= $ss && $e <= $se) {
                        // Adjust offsets to be relative to the *new* string (self.string starts at 0).
                        // Create a copy of the bytearray/content data to avoid modification issues.
                        $copied_content = ($ba !== null) ? $ba : null; // Copy is assumed for immutability.
                        $filtered_spans[] = [$s - $ss, $e - $ss, $m, $copied_content];
                    }
                }
            }
            $new_type_to_spans[$type_name] = $filtered_spans;
        }
        return $new_type_to_spans;
    }
}
