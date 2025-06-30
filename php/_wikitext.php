<?php

declare(strict_types=1);

namespace Wtp\Parser;

use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans
use Wtp\Parser\_wikitext_utils\SPAN_PARSER_TYPES; // Assuming SPAN_PARSER_TYPES is in Wtp\Parser\_wikitext_utils
use Wtp\Parser\_wikitextmain\WikiText; // Assuming WikiText is in Wtp\Parser\_wikitextmain

/**
 * TODO: هذه الوظائف بحاجة إلى تحويل يدوي من مكتبة bisect في Python.
 * المرجع: https://docs.python.org/3/library/bisect.html
 *
 * `bisect_left` finds insertion point for x in a to maintain sorted order.
 * `bisect_right` is similar but returns an insertion point which comes after (to the right of) any existing entries of x in a.
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

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة itertools في Python.
 * المرجع: https://docs.python.org/3/library/itertools.html#itertools.islice
 *
 * `islice` returns an iterator that yields selected elements from the iterable.
 */
function islice(array $array, ?int $start = null, ?int $stop = null, ?int $step = null): \Generator
{
    if ($start === null) {
        $start = 0;
    }
    if ($stop === null) {
        $stop = count($array);
    }
    if ($step === null) {
        $step = 1;
    }

    if ($step <= 0) {
        throw new \InvalidArgumentException("Step must be positive for islice.");
    }

    for ($i = $start; $i < $stop; $i += $step) {
        if (isset($array[$i])) {
            yield $array[$i];
        } else {
            break;
        }
    }
}


/**
 * Class SubWikiText
 *
 * Define a class to be inherited by some subclasses of WikiText.
 * Allow focusing on a particular part of WikiText.
 */
class SubWikiText extends WikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent for memory optimization.
    protected string|int|null $_type; // Property to store the type identifier

    /**
     * @param string|array<string> $string
     * @param TypeToSpans|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|int|null $_type
     */
    public function __construct(
        string|array $string,
        ?TypeToSpans $_typeToSpans = null,
        ?array $_span = null,
        string|int|null $_type = null
    ) {
        if ($_type === null) {
            // Python's type(self).__name__
            $this->_type = $_type = (new \ReflectionClass($this))->getShortName();
            parent::__construct($string);
        } else {
            $this->_type = $_type;
            parent::__construct($string, $_typeToSpans);
            // Type hint requires property declaration in parent for _span_data if it's protected/public
            $this->_span_data = $_span;
        }
    }

    /**
     * Helper to set a specific element of _span_data.
     * This is used to mimic Python's direct array/list assignment.
     *
     * @param int $index
     * @param mixed $value
     */
    public function set_span_data_element(int $index, mixed $value): void
    {
        if (isset($this->_span_data[$index])) {
            $this->_span_data[$index] = $value;
        } else {
            // Handle error or append if the index is out of bounds
            // Python lists allow setting beyond current length, creating nulls. PHP arrays don't.
            // For now, simple assignment, but if strict array size is needed, pre-fill or pad.
            $this->_span_data[$index] = $value;
        }
    }


    /**
     * Yield all the sub-span indices excluding self._span.
     *
     * @param string $type The type of spans to retrieve.
     * @return array<array<int>>
     */
    protected function _subspans(string $type): array
    {
        // _span_data is [start, end, type_identifier, content_string/byte_array]
        list($ss, $se, $_, $_) = $this->_span_data;
        $spans = $this->_type_to_spans[$type] ?? []; // Ensure it's an array

        // bisect_left for s < ss
        $b = bisect_left($spans, [$ss]);
        // bisect_right for s <= se
        $e_idx = bisect_right($spans, [$se], $b); // Start search from $b for efficiency

        $result_spans = [];
        foreach (islice($spans, $b, $e_idx) as $span) {
            // Ensure the entire sub-span is within the current object's span.
            // Python's `span[1] <= se` checks if the end of the sub-span is less than or equal to the end of the parent span.
            if ($span[1] <= $se) {
                $result_spans[] = $span;
            }
        }
        return $result_spans;
    }

    /**
     * Return the ancestors of the current node.
     *
     * @param string|null $type The type of the desired ancestors.
     * Currently the following types are supported: {Template,
     * ParserFunction, WikiLink, Comment, Parameter, ExtensionTag}.
     * The default is null and means all the ancestors of any type above.
     * @return array<WikiText>
     */
    public function ancestors(?string $type = null): array
    {
        global $SPAN_PARSER_TYPES; // Access the global constant

        if ($type === null) {
            $types = $SPAN_PARSER_TYPES;
        } else {
            $types = [$type];
        }

        $lststr = $this->_lststr; // Main string from WikiText
        $type_to_spans = $this->_type_to_spans; // TypeToSpans object

        // _span_data is [start, end, type_identifier, content_string/byte_array]
        list($ss, $se, $_, $_) = $this->_span_data;
        $ancestors = [];

        foreach ($types as $current_type) {
            $clsName = __NAMESPACE__ . '\\' . $current_type; // Fully qualified class name
            if (!class_exists($clsName)) {
                // If the class for the type doesn't exist, skip it.
                // This can happen if SPAN_PARSER_TYPES includes classes not yet defined.
                continue;
            }

            $spans = $type_to_spans[$current_type] ?? [];

            // Find spans whose end is after or at current span's end, and start is before current span's start.
            // `bisect_right(spans, [ss])` finds index where elements are >= ss.
            // We need spans that *contain* the current span, so start before $ss and end after $se.
            $b_idx = bisect_right($spans, [$ss]); // Find where $ss would be inserted, gives us potential parent candidates.

            // Iterate backwards from $b_idx-1 to find containing spans more efficiently.
            for ($i = $b_idx - 1; $i >= 0; $i--) {
                $span = $spans[$i];
                if ($span[0] <= $ss && $se < $span[1]) { // If parent starts before or at child, and ends after child
                    // Create an instance of the ancestor class
                    $ancestors[] = new $clsName($lststr, $type_to_spans, $span, $current_type);
                }
            }
        }
        // Sort by the difference between child's start and ancestor's start (closer ancestors first)
        usort($ancestors, function (WikiText $a, WikiText $b) use ($ss) {
            return ($ss - $a->_span_data[0]) <=> ($ss - $b->_span_data[0]);
        });

        return $ancestors;
    }

    /**
     * Return the parent node of the current object.
     *
     * @param string|null $type The type of the desired parent object.
     * Currently the following types are supported: {Template,
     * ParserFunction, WikiLink, Comment, Parameter, ExtensionTag}.
     * The default is null and means the first parent, of any type above.
     * @return WikiText|null
     */
    public function parent(?string $type = null): ?WikiText
    {
        $ancestors = $this->ancestors($type); // Call ancestors as method
        if (!empty($ancestors)) {
            return $ancestors[0]; // The closest ancestor (first after sorting)
        }
        return null;
    }

    /**
     * Return the nesting level of self.
     * This method is added here as it was defined in the original `_parser_function.py` for `SubWikiTextWithArgs`,
     * and it seems logical for `SubWikiText` to have it or for the hierarchy to be managed.
     * Moved to `SubWikiText` for broader availability if needed.
     *
     * @param array<string>|null $types Types of nodes to consider for nesting (e.g., ['Template', 'ParserFunction']).
     * @return int
     */
    public function _nesting_level(?array $types = null): int
    {
        if ($types === null) {
            // If types are not specified, consider all known span parser types for nesting.
            global $SPAN_PARSER_TYPES;
            $types = $SPAN_PARSER_TYPES;
        }

        $level = 0;
        $current = $this;

        // Keep searching for a parent as long as one exists and matches the specified types
        while (($parent = $current->parent(null)) !== null) { // Pass null to parent to get any type of parent
            if (in_array((new \ReflectionClass($parent))->getShortName(), $types)) {
                $level++;
            }
            $current = $parent;
        }
        return $level;
    }
}


/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة itertools في Python.
 * المرجع: https://docs.python.org/3/library/itertools.html
 *
 * `_outer_spans` yields the outermost intervals.
 * This is a generator function in Python.
 */
function _outer_spans(array $sorted_spans): \Generator
{
    foreach ($sorted_spans as $i => $span) {
        $se = $span[1]; // End of current span
        $is_outermost = true;

        // Check if any previous span fully contains the current span.
        foreach (islice($sorted_spans, null, $i) as $prev_span) {
            $ps = $prev_span[0]; // Start of previous span
            $pe = $prev_span[1]; // End of previous span

            if ($ps <= $span[0] && $se <= $pe) { // If previous span contains current span
                $is_outermost = false;
                break;
            }
        }

        if ($is_outermost) {
            yield $span;
        }
    }
}


/**
 * Return a string with wiki markup removed/replaced.
 *
 * @param string $s The wikitext string.
 * @param mixed ...$kwargs Additional keyword arguments to pass to plain_text method.
 * @return string
 */
function remove_markup(string $s, ...$kwargs): string
{
    $wikiText = new WikiText($s);
    // Python's **kwargs means all remaining keyword arguments.
    // In PHP, this is handled by `...$kwargs`.
    // The `_is_root_node=True` might be a flag for internal logic within `plain_text`.
    return $wikiText->plain_text(array_merge($kwargs, ['_is_root_node' => true]));
}
