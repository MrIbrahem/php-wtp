<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText;
use Wtp\Parser\rc;
use Wtp\Parser\_spans\ATTRS_PATTERN;
use Wtp\Parser\_spans\END_TAG_PATTERN;
use Wtp\Parser\_spans\SPACE_CHARS;

// PHP equivalents for regex constants
const DOTALL = 's';
const VERBOSE = 'x';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `span()` و `group()` و `spans()`.
 * تم تقديم نسخة بسيطة في ملف `_wikilink.php`، سيتم استخدام نفس المنطق هنا.
 *
 * This global variable will store the compiled regex pattern for Tag.
 */
$TAG_FULLMATCH_PATTERN = rc(
    '<' . '(?<name>[A-Za-z0-9]++)'
    . ATTRS_PATTERN() // Call ATTRS_PATTERN as a function
    . '[' . SPACE_CHARS() . ']*+' // Call SPACE_CHARS as a function
    . '(?>'
    . '>' . '(?<contents>.*)'
    . str_replace('{name}', '(?<end_name>[A-Za-z0-9]++)', END_TAG_PATTERN()) // Call END_TAG_PATTERN as a function
    . '|>  # only start; no end tag; could be self-closing'
    . ')',
    DOTALL . VERBOSE
);

/**
 * Wrapper for preg_match that mimics Python's regex.fullmatch and Match object.
 *
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function fullmatch(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match('/^' . $pattern . '$/' . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        $matchObject[0] = $matches[0]; // Full match, [matched_string, offset]
        $matchObject['start'] = $matches[0][1]; // Start offset of the entire match

        // Store named and numeric capture groups, and their spans
        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Named capture group
                $matchObject[$key] = $value[0]; // Matched string for the group
                $matchObject['offset_' . $key] = $value[1]; // Offset for the group

                // Collect spans for named groups, handling multiple captures for a group name (e.g., 'attr_name')
                // Python's regex.spans() returns a list of all (start, end) for a given group name.
                // preg_match_all with PREG_PATTERN_ORDER can get all occurrences.
                // For a single preg_match, we only get the last one if name is repeated,
                // or the only one if it's not.
                // For a single match, we store [start, end] as a single array for simplicity.
                if ($value[0] !== null) {
                    $matchObject['span_' . $key] = [$value[1], $value[1] + strlen($value[0])];
                } else {
                    $matchObject['span_' . $key] = [-1, -1]; // Indicate no match
                }
            } elseif (is_int($key) && $key > 0) { // Numeric capture group (excluding full match at index 0)
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
                if ($value[0] !== null) {
                    $matchObject['span_' . $key] = [$value[1], $value[1] + strlen($value[0])];
                } else {
                    $matchObject['span_' . $key] = [-1, -1];
                }
            }
        }

        // Mimic `match.span(group)`
        $matchObject['span'] = function ($group) use ($matchObject) {
            return $matchObject['span_' . $group] ?? [-1, -1];
        };

        // Mimic `match.spans(group_name)` - this needs to be specifically handled for repeating groups like attr_name
        // For TAG_FULLMATCH, 'attr_name' and 'attr_value' can be repeated.
        // We'll need a separate preg_match_all for these, or parse them from the full string.
        // For now, let's assume `spans` will return an array of arrays [start, end].
        $matchObject['spans'] = function ($groupName) use ($subject, $pattern, $flags) {
            $allMatches = [];
            preg_match_all('/' . $pattern . '/' . $flags, $subject, $allMatches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

            $resultSpans = [];
            foreach ($allMatches as $match) {
                if (isset($match[$groupName]) && $match[$groupName][0] !== null) {
                    $resultSpans[] = [$match[$groupName][1], $match[$groupName][1] + strlen($match[$groupName][0])];
                }
            }
            return $resultSpans;
        };


        // Mimic `match[group_number_or_name]`
        // Python's match['name'] returns the string directly.
        $matchObject['get_group'] = function ($group) use ($matchObject) {
            return $matchObject[$group] ?? null;
        };

        // Store the original subject string for cache validation
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}

/**
 * TODO: هذه الوظائف بحاجة إلى تحويل يدوي من ملف _spans.py في Python.
 * المرجع: (لا يوجد توثيق عام لهذا الملف، لذا قد تحتاج إلى البحث في الكود الأصلي)
 */
function ATTRS_PATTERN(): string
{
    return 'TODO_IMPLEMENT_ATTRS_PATTERN';
}

function END_TAG_PATTERN(): string
{
    return 'TODO_IMPLEMENT_END_TAG_PATTERN';
}

function SPACE_CHARS(): string
{
    return 'TODO_IMPLEMENT_SPACE_CHARS';
}


/**
 * Class SubWikiTextWithAttrs
 *
 * Define a class for SubWikiText objects that have attributes.
 * Any class that is going to inherit from SubWikiTextWithAttrs should provide
 * _attrs_match property. Note that matching should be done on shadow.
 * It's usually a good idea to cache the _attrs_match property.
 */
abstract class SubWikiTextWithAttrs extends SubWikiText
{
    // In PHP, properties are declared directly. __slots__ is Python-specific.
    protected ?array $_attrs_match = null; // Changed to protected as it's meant to be set by child classes.

    /**
     * Abstract method to be implemented by child classes to provide the _attrs_match.
     * @return array<string, mixed> The match array for attributes.
     */
    abstract protected function _attrs_match(): array;

    /**
     * Return self attributes as a dictionary.
     *
     * @return array<string, string>
     */
    public function attrs(): array
    {
        $attrs = [];
        $match = $this->_attrs_match(); // Call as method
        if (empty($match)) {
            return $attrs;
        }

        // We need to re-run preg_match_all to get all 'attr_name' and 'attr_value' occurrences.
        // The `fullmatch` function above returns only the last match for repeated named groups.
        // This is a workaround to mimic Python's `match.spans()`.
        preg_match_all(
            '/' . ATTRS_PATTERN() . '/x', // Use ATTRS_PATTERN directly with 'x' for verbose
            $this->_shadow, // Match against the shadow
            $allAttrMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($allAttrMatches as $attrMatch) {
            if (isset($attrMatch['attr_name']) && $attrMatch['attr_name'][0] !== null) {
                $name = $attrMatch['attr_name'][0];
                $value = isset($attrMatch['attr_value']) && $attrMatch['attr_value'][0] !== null ? $attrMatch['attr_value'][0] : '';
                $attrs[$name] = $value;
            }
        }
        return $attrs;
    }

    /**
     * Return True if self contains an attribute with the given name.
     *
     * @param string $attr_name
     * @return bool
     */
    public function has_attr(string $attr_name): bool
    {
        $match = $this->_attrs_match(); // Call as method
        if (empty($match)) {
            return false;
        }

        preg_match_all(
            '/' . ATTRS_PATTERN() . '/x',
            $this->_shadow,
            $allAttrMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($allAttrMatches as $attrMatch) {
            if (isset($attrMatch['attr_name']) && $attrMatch['attr_name'][0] === $attr_name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the value of the last attribute with the given name.
     *
     * Return None if the attr_name does not exist in self.
     * If there are already multiple attributes with the given name, only
     * return the value of the last one.
     * Return an empty string if the mentioned name is an empty attribute.
     *
     * @param string $attr_name
     * @return string|null
     */
    public function get_attr(string $attr_name): ?string
    {
        $match = $this->_attrs_match(); // Call as method
        if (empty($match)) {
            return null;
        }

        preg_match_all(
            '/' . ATTRS_PATTERN() . '/x',
            $this->_shadow,
            $allAttrMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Iterate in reverse to find the last occurrence
        for ($i = count($allAttrMatches) - 1; $i >= 0; $i--) {
            $attrMatch = $allAttrMatches[$i];
            if (isset($attrMatch['attr_name']) && $attrMatch['attr_name'][0] === $attr_name) {
                return isset($attrMatch['attr_value']) && $attrMatch['attr_value'][0] !== null ? $attrMatch['attr_value'][0] : '';
            }
        }
        return null;
    }

    /**
     * Set the value for the given attribute name.
     *
     * If there are already multiple attributes with the given name, only
     * set the value for the last one.
     * If attr_value == '', use the implicit empty attribute syntax.
     *
     * @param string $attr_name
     * @param string $attr_value
     */
    public function set_attr(string $attr_name, string $attr_value): void
    {
        $match = $this->_attrs_match(); // Call as method
        if (empty($match)) {
            // Cannot set attribute if there's no valid tag match to start with.
            // This case implies the Tag object itself is invalid.
            return;
        }

        preg_match_all(
            '/' . ATTRS_PATTERN() . '/x',
            $this->_shadow,
            $allAttrMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $found = false;
        // Iterate in reverse to find and replace the last occurrence
        for ($i = count($allAttrMatches) - 1; $i >= 0; $i--) {
            $attrMatch = $allAttrMatches[$i];
            if (isset($attrMatch['attr_name']) && $attrMatch['attr_name'][0] === $attr_name) {
                $vs = $attrMatch['attr_value'][1];
                $ve = $vs + strlen($attrMatch['attr_value'][0]);

                // Check if the value was quoted
                $q = 0;
                // Assuming the original string is $this->string and we need to check char at $ve
                if (isset($this->string[$ve]) && in_array($this->string[$ve], ['"', "'"])) {
                    $q = 1;
                }

                $replacement = $attr_value ? "\"{$attr_value}\"" : ''; // Add quotes for non-empty

                $this->offsetSet($vs - $q, ($ve + $q) - ($vs - $q), $replacement); // Replace including quotes
                $found = true;
                break; // Only update the last one
            }
        }

        if (!$found) {
            // The attr_name is new, add a new attribute.
            // Python's match.end('attr_insert') gives the position to insert.
            // This corresponds to the end of the opening tag name and its attributes,
            // before the closing '>'.
            $insertPos = $match['span']('attr_insert')[1] ?? -1; // End of the attribute section for insertion

            if ($insertPos !== -1) {
                $toInsert = $attr_value ? " {$attr_name}=\"{$attr_value}\"" : " {$attr_name}";
                $this->insert($insertPos, $toInsert);
            }
        }
    }

    /**
     * Delete all the attributes with the given name.
     *
     * Pass if the attr_name is not found in self.
     *
     * @param string $attr_name
     */
    public function del_attr(string $attr_name): void
    {
        $match = $this->_attrs_match(); // Call as method
        if (empty($match)) {
            return;
        }

        preg_match_all(
            '/' . ATTRS_PATTERN() . '/x',
            $this->_shadow,
            $allAttrMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Must be done in reversed order because the spans change after each deletion.
        for ($i = count($allAttrMatches) - 1; $i >= 0; $i--) {
            $attrMatch = $allAttrMatches[$i];
            if (isset($attrMatch['attr_name']) && $attrMatch['attr_name'][0] === $attr_name) {
                // 'attr' group spans the entire attribute (name and value).
                $start = $attrMatch[0][1]; // Start of the full attribute match
                $stop = $start + strlen($attrMatch[0][0]); // End of the full attribute match

                $this->offsetUnset($start, $stop - $start); // Delete the attribute
            }
        }
    }
}


/**
 * Class Tag
 *
 * Represents an HTML-like tag in wikitext (e.g., <nowiki>, <div>).
 */
class Tag extends SubWikiTextWithAttrs
{
    private ?array $_match_cache;

    /**
     * @param string|array<string> $string
     * @param array|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|null $_type
     */
    public function __construct(
        string|array $string,
        ?array $_typeToSpans = null,
        ?array $_span = null,
        ?string $_type = null
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        $this->_match_cache = [null, null]; // Initialize cache
    }

    /**
     * Return the match object for the current tag. Cache the result.
     *
     * @return array<string, mixed> The match array for the tag.
     */
    protected function _match(): array
    {
        global $TAG_FULLMATCH_PATTERN; // Access the global compiled regex pattern

        list($cached_match, $cached_string) = $this->_match_cache;
        $string = $this->string;

        if ($cached_string === $string) {
            return $cached_match;
        }

        $match = fullmatch($TAG_FULLMATCH_PATTERN, $this->_shadow, DOTALL . VERBOSE);
        $this->_match_cache = [$match, $string];

        if ($match === null) {
            // Handle no match case, perhaps throw an exception or return a default empty structure
            // depending on how strict you want it to be.
            return [];
        }
        return $match;
    }

    // _attrs_match property points to _match, so implement _attrs_match method
    protected function _attrs_match(): array
    {
        return $this->_match();
    }

    /**
     * Tag's name. Support both get and set operations.
     *
     * @return string
     */
    public function name(): string
    {
        $match = $this->_match();
        if (empty($match)) return '';
        // 'name' group is expected to be a string in Python. PHP's fullmatch helper returns direct value.
        return $match['get_group']('name');
    }

    /**
     * @param string $name The new name for the tag.
     */
    public function set_name(string $name): void
    {
        $match = $this->_match();
        if (empty($match)) return;

        // The name in the end tag should be replaced first because the spans
        // of the match object change after each replacement.

        list($start_end_name, $end_end_name) = $match['span']('end_name');
        if ($start_end_name !== -1) {
            $this->offsetSet($start_end_name, $end_end_name - $start_end_name, $name);
        }

        list($start_name, $end_name) = $match['span']('name');
        $this->offsetSet($start_name, $end_name - $start_name, $name);
    }

    /**
     * Tag contents. Support both get and set operations.
     *
     * @return string|null
     */
    public function contents(): ?string
    {
        $match = $this->_match();
        if (empty($match)) return null;

        list($s, $e) = $match['span']('contents');
        if ($s === -1) {
            return null; // No contents group found
        }
        return $this->__invoke($s, $e);
    }

    /**
     * @param string $contents The new contents for the tag.
     */
    public function set_contents(string $contents): void
    {
        $match = $this->_match();
        if (empty($match)) return;

        list($start, $end) = $match['span']('contents');
        if ($start !== -1) {
            $this->offsetSet($start, $end - $start, $contents);
        } else {
            // This is a self-closing tag. Expand it to have start and end tags.
            // Python's slice `self[-1:]` means last character.
            // Python's `match["name"].decode()` means getting the 'name' group value.
            $tagName = $match['get_group']('name');
            $this->offsetSet(strlen($this->string) - 1, 1, ">{$contents}</{$tagName}>"); // Replace last char (/) with > and add contents and end tag
        }
    }

    /**
     * Return the contents as a SubWikiText object.
     *
     * @return SubWikiText
     */
    public function parsed_contents(): SubWikiText
    {
        // _span_data is assumed to be accessible from SubWikiText, e.g., [start, end, type, byte_array]
        list($ss, $_, $_, $byte_array) = $this->_span_data;
        $match = $this->_match();
        if (empty($match)) {
             // If there is no match, it implies a malformed tag.
             // Return an empty SubWikiText or throw an error.
            return new SubWikiText('');
        }

        list($s, $e) = $match['span']('contents');

        if ($s === -1) {
            // No 'contents' group found, return an empty SubWikiText.
            return new SubWikiText('');
        }

        $tts = $this->_type_to_spans;
        // setdefault in Python dictionary for nested list.
        if (!isset($tts['SubWikiText'])) {
            $tts['SubWikiText'] = [];
        }
        $spans = &$tts['SubWikiText'];

        $ps = $ss + $s;
        $pe = $ss + $e;
        $span_tuple = [$ps, $pe];

        $foundIndex = -1;
        foreach ($spans as $index => $spanItem) {
            if (isset($spanItem[0]) && isset($spanItem[1]) && $spanItem[0] === $ps && $spanItem[1] === $pe) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === -1) {
            // Assuming $byte_array is the full string content of the original wikitext.
            // Extract the slice corresponding to the contents.
            $content_slice = substr($this->string, $s, $e - $s); // string slice from Tag's own string
            $span = [$ps, $pe, null, $content_slice]; // Storing the actual content string instead of bytes
            $spans[] = $span;

            // Sort to maintain order if necessary, though Python's sort is for the list of spans,
            // not a requirement for direct access by index later.
            usort($spans, function($a, $b) {
                return $a[0] <=> $b[0]; // Sort by start offset
            });
        } else {
            $span = $spans[$foundIndex];
        }

        // _lststr from SubWikiText is expected to be the main string source.
        return new SubWikiText($this->_lststr, $tts, $span, 'SubWikiText');
    }

    /**
     * Returns a list of extension tags within the current tag, excluding the current tag itself.
     *
     * @return array<Tag>
     */
    protected function _extension_tags(): array
    {
        // Call the parent's _extension_tags method and return all but the first element
        $allExtensionTags = parent::_extension_tags();
        return array_slice($allExtensionTags, 1);
    }

    /**
     * Returns a list of tags within the current tag, excluding the current tag itself.
     *
     * @param string|null $name Optional tag name to filter by.
     * @return array<Tag>
     */
    public function get_tags(?string $name = null): array
    {
        // Call the parent's get_tags method and return all but the first element
        $allTags = parent::get_tags($name);
        return array_slice($allTags, 1);
    }

    /**
     * Returns the start and end offsets of the content *within* the tag
     * (i.e., between the opening '>' and closing '<').
     *
     * @return array<int> [start_offset, end_offset]
     */
    protected function _content_span(): array
    {
        $s = $this->string;
        $firstGt = strpos($s, '>');
        $lastLt = strrpos($s, '<');

        // If either is not found, or they are in wrong order, return empty range
        if ($firstGt === false || $lastLt === false || $firstGt >= $lastLt) {
            return [0, 0];
        }

        return [$firstGt + 1, $lastLt];
    }
}
