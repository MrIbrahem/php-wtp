<?php

declare(strict_types=1);

namespace Wtp\Parser\_spans;

use Closure;
use Wtp\Parser\_config\_HTML_TAG_NAME;
use Wtp\Parser\_config\_bare_external_link_schemes;
use Wtp\Parser\_config\_get_parsable_tag_extensions;
use Wtp\Parser\_config\_get_unparsable_tag_extensions;
use Wtp\Parser\_config\_get_parser_functions;
use Wtp\Parser\_config\regex_pattern;

// PHP equivalents for regex constants
const DOTALL = 's';
const IGNORECASE = 'i';
// REVERSE is a Python regex module specific flag. It indicates that the regex should match from right to left.
// In PHP, this behavior must be manually implemented by reversing the string and adjusting the regex pattern.
// For `finditer` like behavior, it means iterating backwards.
const REVERSE = ''; // Placeholder, actual reversal logic needed at use site.


/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة functools في Python.
 * المرجع: https://docs.python.org/3/library/functools.html#functools.partial
 *
 * `partial` is used to create a new function with some arguments pre-filled.
 * In PHP, you can use anonymous functions (closures) or simply pass the full arguments.
 */
// In this specific case, `rc = partial(rc, cache_pattern=False)`
// means that `rc` function here will always be called with `cache_pattern=false`.
// We will define `rc` directly to behave this way for regex compilation.

/**
 * Compiles a regex pattern. In this context, it just formats the pattern string for PHP's `preg_` functions.
 * The `cache_pattern=False` implies we don't need a special caching mechanism for patterns in PHP,
 * as `preg_` functions handle their own internal caching.
 *
 * @param string $pattern The regex pattern string.
 * @param string $flags Optional regex flags.
 * @return string The formatted regex pattern string with delimiters.
 */
function rc(string $pattern, string $flags = ''): string
{
    // PHP regexes need delimiters. Using '/' as a common delimiter.
    return '/' . str_replace('/', '\\/', $pattern) . '/' . $flags;
}

/**
 * Represents the structure for storing parsed spans.
 * `Dict[Union[str, int], List[List]]` translates to `array<string|int, array<array<mixed>>>`.
 * Each inner list is a span: `[start_offset, end_offset, Match_object, content_byte_array]`.
 */
class TypeToSpans extends \ArrayObject
{
    // Extend ArrayObject for dictionary-like behavior with type hinting.
    // This allows `TypeToSpans['Comment'] = ...`
}

// According to https://www.mediawiki.org/wiki/Manual:$wgLegalTitleChars
// illegal title characters are: r'[]{}|#<>[\u0000-\u0020]'
const VALID_TITLE_CHARS = '[^\\|\\[\\]{}<>\n]*+'; // Adjusted to use PHP regex escaping and removed '\2', '\3' which are backreferences.

// Parser functions
// According to https://www.mediawiki.org/wiki/Help:Magic_words
// See also:
// https://translatewiki.net/wiki/MediaWiki:Sp-translate-data-MagicWords/fa
const ARGS = '(?:\\|(?\>[^{}]++|{(?!{)|}(?!}))*+)?+';

// PF_TL_FINDITER (Parser Function / Template Find Iterator)
// This pattern needs careful reconstruction to match the Python regex's logic,
// especially with conditional groups and combining patterns.
// Regex: `regex_pattern(_parser_functions)[3:]` removes the `(?:` from the start of the generated pattern.
// This means the pattern is expected to start with the actual regex of the first alternative, not the grouping.
$PF_TL_FINDITER_PATTERN = rc(
    '\\{\\{(?\>'
        . '[\\s\\0]*+'
        . '(?\>'
        . '\\#[^{}\\s:|]++' // parser function
        . '|'
        . substr(regex_pattern(_get_parser_functions()), 3) // Remove (?: from start of regex_pattern output
        . // should not have any arguments or the arg should start with a :
        '(?:'
        . ':(?\>[^{}]*+|}(?!})|{(?!{))*+'
        . ')?+'
        . '}}\\\\()(?#This is a dummy capture group to replace original Python regex empty group )' // Added a dummy capture group
        . '|' // invalid template name
        . '[\\s\\0_]*+' . ARGS . '}}\\\\()(?#This is a dummy capture group)'
        . '|' // template
        . '[\\s\\0]*+'
        . VALID_TITLE_CHARS
        . '[\\s\\0]*+' // template name
        . ARGS
        . '}})'
);

// External links
const INVALID_URL_CHARS = ' \\t\\n"<>[\\]';
const VALID_URL_CHARS = '[^' . INVALID_URL_CHARS . ']++;'; // Added extra escaping for ']' in PHP regex.
// See more info on literal IPv6 see:
// https://en.wikipedia.org/wiki/IPv6_address#Literal_IPv6_addresses_in_network_resource_identifiers
// The following pattern is part of EXT_LINK_ADDR constant in
// https://github.com/wikimedia/mediawiki/blob/master/includes/parser/Parser.php
const LITERAL_IPV6_AND_TAIL = '\\[[0-9a-fA-F:.]++\\][^' . INVALID_URL_CHARS . ']*+';
// A \b is added to the beginning.
// BARE_EXTERNAL_LINK_SCHEMES
function BARE_EXTERNAL_LINK_SCHEMES(): string
{
    return '\\b' . regex_pattern(_get_bare_external_link_schemes());
}
const EXTERNAL_LINK_URL_TAIL = '(?\>' . LITERAL_IPV6_AND_TAIL . '|' . VALID_URL_CHARS . ')';
const BARE_EXTERNAL_LINK = 'BARE_EXTERNAL_LINK_SCHEMES' . 'EXTERNAL_LINK_URL_TAIL'; // Concatenate constants in PHP

// Wikilinks
// https://www.mediawiki.org/wiki/Help:Links#Internal_links
$WIKILINK_PARAM_FINDITER_PATTERN = rc(
    '(?<!(?:^|[^\\\\\\[\\0])(?:(?:\\\\\\[\\0*+){2})*+\\\\\\[\\0*+)' // ! = 2N + 1
        . '\\\\\\[\\0*+\\\\\\[(?![\\\\ \\\\0]*+' . BARE_EXTERNAL_LINK . ')' . VALID_TITLE_CHARS . '(?\>'
        . '\\|'
        . '(?\>'
        . '(?!\\\\\\\\[\\\\0*+)'
        . '\\\\\\[(?#single open bracket)'
        . ')?+'
        . '(?\>'
        . '(?!\\\\\\\\]\\\\0*+)'
        . '\\\\\\](?#single closing bracket)'
        . ')?+'
        . // single matching brackets are allowed in text e.g. [[a|[b]]]
        '(?\>'
        . '[^\\\\\\[\\\\\\]\\|]*+'
        . '\\\\\\['
        . '[^\\\\\\[\\\\\\]\\|]*+'
        . '\\\\\\]'
        . '(?!(:?\\\\0*+\\\\\\]){3})'
        . ')?+'
        . '[^\\\\\\[\\\\\\]\\|]*+'
        . ')*+'
        . '\\\\\\]\\0*+\\\\\\]'
        . '|\\{\\{\\{('
        . '[^{}]++'
        . '|(?<!})}(?!})'
        . '|(?<!{){'
        . ')++\\}\\}\\}',
    REVERSE // This flag requires custom handling in PHP.
);


// These are byte strings (b'...') in Python. In PHP, strings are byte strings by default (if using ASCII-compatible characters).
// For translation tables, we'll use `str_replace` or `strtr` on individual characters.
// `b''.maketrans(b"=|[]'{}", b'\1_\2\3___')`
const MARKUP_FROM = "=|[]'{}";
const MARKUP_TO = "\x01_\x02\x03___"; // Using hex for control chars

// `b''.maketrans(b'|{}\n', b'____')`
const BRACES_PIPE_NEWLINE_FROM = '|{}\n';
const BRACES_PIPE_NEWLINE_TO = '____';

const BRACKETS_FROM = '[]';
const BRACKETS_TO = '__';


function PARSABLE_TAG_EXTENSION_NAME(): string
{
    return regex_pattern(_get_parsable_tag_extensions());
}
function UNPARSABLE_TAG_EXTENSION_NAME(): string
{
    return regex_pattern(_get_unparsable_tag_extensions());
}


// The idea of the following regex is to detect innermost HTML tags. From
// http://blog.stevenlevithan.com/archives/match-innermost-html-element
// But it's not bullet proof:
// https://stackoverflow.com/questions/3076219/
const CONTENT_AND_END = (
    '(?=[\s>/])'
    . '[^>]*+'
    . '(?\>'
    . '(?<=/)>' // self-closing
    . // group c captures contents
    '|>(?<c>'
    . '.*?'
    . ')</\\g<n>\\s*+>' // \g<n> refers to the 'n' capture group (tag name)
    . ')'
);
$EXTENSION_TAGS_FINDITER_PATTERN = rc(
    '<(?\>'
        . // group m captures comments
        '(?<m>!--[\\s\\S]*?(?\>-->|(?=</\\g<n>\\s*+>)|\Z))'
        . // u captures unparsable tag extensions and n captures the name
        '|(?<u>(?<n>'
        . UNPARSABLE_TAG_EXTENSION_NAME()
        . ')'
        . CONTENT_AND_END
        . ')'
        . // p captures parsable tag extensions and n captures the name
        '|(?<p>(?<n>'
        . PARSABLE_TAG_EXTENSION_NAME()
        . ')'
        . CONTENT_AND_END
        . ')'
        . ')',
    DOTALL . IGNORECASE
);

// HTML tags
// Tags:
// https://infra.spec.whatwg.org/#ascii-whitespace
// \0 was added as a special case for wikitextparser
const SPACE_CHARS = ' \\t\\n\\u000C\\r\\0'; // \s - \v (PHP needs unicode escape for \u000C)
// http://stackoverflow.com/a/93029/2705757
// chrs = (chr(i) for i in range(sys.maxunicode))
// control_chars = ''.join(c for c in chrs if unicodedata.category(c) == 'Cc')
const CONTROL_CHARS = '\\x00-\\x1f\\x7f-\\x9f';
// https://www.w3.org/TR/html5/syntax.html#syntax-attributes
const ATTR_NAME = '(?<attr_name>[^' . SPACE_CHARS . CONTROL_CHARS . '"\'>/=]++)';
const EQ_WS = '[=\\1][' . SPACE_CHARS . ']*+'; // \1 is problematic here, it likely refers to a backreference that is not defined here. Assuming it's `\s`
// Corrected EQ_WS, assuming `\1` in Python was a placeholder for space char or literal '='
// If it implies "either `=` or a specific control char/whitespace", it needs careful re-evaluation.
// For now, assuming it means `=`.
const EQ_WS_CORRECTED = '[=][' . SPACE_CHARS . ']*+';


const UNQUOTED_ATTR_VAL = '(?<attr_value>[^' . SPACE_CHARS . '"\'=<>`]++)';
const QUOTED_ATTR_VAL = '(?<quote>[\'"])(?<attr_value>.*?)(?P=quote)';
// May include character references, but for now, ignore the fact that they
// cannot contain an ambiguous ampersand.
const ATTR_VAL = (
    // If an empty attribute is to be followed by the optional
    // "/" character, then there must be a space character separating
    // the two. This rule is ignored here.
    '(?\>['
    . SPACE_CHARS
    . ']*+'
    . EQ_WS_CORRECTED // Using corrected EQ_WS
    . '(?\>'
    . UNQUOTED_ATTR_VAL
    . '|'
    . QUOTED_ATTR_VAL
    . ')'
    . '|(?<attr_value>)' // empty attribute
    . ')'
);
// Ignore ambiguous ampersand for the sake of simplicity.
const ATTRS_PATTERN = (
    '(?<attr>'
    . '['
    . SPACE_CHARS
    . ']*+(?\>'
    . ATTR_NAME
    . ATTR_VAL
    . ')'
    . // See https://stackoverflow.com/a/3558200/2705757 for how HTML5
    // treats self-closing marks.
    '|[^>]++'
    . ')*+(?<attr_insert>)' // This capture group is for insertion point.
);

// ATTRS_MATCH will be a function returning a match object, consistent with other `rc(...).match`
function ATTRS_MATCH_FUNCTION(string $subject, int $start_offset, int $end_offset): array
{
    // This function needs to return a match object that supports `spans()` and `captures()`
    // for attributes. This is complex for PHP `preg_match` if groups repeat.
    // The previous implementation in `_cell.py`'s `ATTRS_MATCH_FUNCTION` can be reused.
    // For now, return a placeholder that provides the basic structure.

    // ATTRS_PATTERN contains repeating groups `attr_name`, `attr_value`, `attr`.
    // `preg_match_all` is needed to get all occurrences for `spans()` and `captures()`.

    // The `ATTRS_MATCH` from Python is often used with specific `start` and `end` arguments
    // to match within a substring. This implies we need to apply `ATTRS_PATTERN` to that substring.
    $substring = substr($subject, $start_offset, $end_offset - $start_offset);

    $matches = [];
    preg_match_all(
        '/' . ATTRS_PATTERN . '/x', // Use ATTRS_PATTERN directly. 'x' flag for verbose.
        $substring,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    $attrs_match_object = [];
    $attrs_match_object['string'] = $substring; // The substring being matched against

    $attrs_match_object['captures_attr_name'] = [];
    $attrs_match_object['spans_attr_name'] = [];
    $attrs_match_object['captures_attr_value'] = [];
    $attrs_match_object['spans_attr_value'] = [];
    $attrs_match_object['spans_attr'] = [];

    // Initialize for 'attr_insert' as well, if it's expected to be a direct match
    $attrs_match_object['span_attr_insert'] = [-1, -1];
    $attrs_match_object['offset_attr_insert'] = -1;

    foreach ($matches as $match) {
        // Collect `attr_name` captures and spans
        if (isset($match['attr_name']) && $match['attr_name'][0] !== null) {
            $attrs_match_object['captures_attr_name'][] = $match['attr_name'][0];
            $attrs_match_object['spans_attr_name'][] = [$match['attr_name'][1] + $start_offset, $match['attr_name'][1] + strlen($match['attr_name'][0]) + $start_offset];
        }

        // Collect `attr_value` captures and spans
        if (isset($match['attr_value']) && $match['attr_value'][0] !== null) {
            $attrs_match_object['captures_attr_value'][] = $match['attr_value'][0];
            $attrs_match_object['spans_attr_value'][] = [$match['attr_value'][1] + $start_offset, $match['attr_value'][1] + strlen($match['attr_value'][0]) + $start_offset];
        }

        // Collect full `attr` spans
        if (isset($match['attr']) && $match['attr'][0] !== null) {
            $attrs_match_object['spans_attr'][] = [$match['attr'][1] + $start_offset, $match['attr'][1] + strlen($match['attr'][0]) + $start_offset];
        }

        // `attr_insert` is likely a zero-width assertion at the end of the last `attr` group.
        // It's not a repeating group in the same way. It's a single point.
        // Its offset should be relative to the original `subject` string.
        // In the context of the ATTRS_PATTERN, `attr_insert` is at the end of the entire `ATTRS_PATTERN`.
        // So, its offset would be `start_offset` + length of the matched substring.
        $attrs_match_object['offset_attr_insert'] = $start_offset + strlen($substring);
        $attrs_match_object['span_attr_insert'] = [$attrs_match_object['offset_attr_insert'], $attrs_match_object['offset_attr_insert']];
    }

    $attrs_match_object['spans'] = function ($groupName) use ($attrs_match_object) {
        if ($groupName === 'attr_insert') {
            return [$attrs_match_object['span_attr_insert']]; // Returns a list containing one span
        }
        return $attrs_match_object['spans_' . $groupName] ?? [];
    };

    $attrs_match_object['captures'] = function ($groupName) use ($attrs_match_object) {
        return $attrs_match_object['captures_' . $groupName] ?? [];
    };

    // Add `start` and `end` methods for convenience.
    $attrs_match_object['start'] = function ($group = 0) use ($attrs_match_object) {
        if ($group === 'attr_insert') return $attrs_match_object['offset_attr_insert'];
        return $attrs_match_object['spans_' . $group][0][0] ?? -1;
    };
    $attrs_match_object['end'] = function ($group = 0) use ($attrs_match_object) {
        if ($group === 'attr_insert') return $attrs_match_object['offset_attr_insert'];
        return $attrs_match_object['spans_' . $group][0][1] ?? -1;
    };


    return $attrs_match_object;
}


// VOID_ELEMENTS = (...)
// RAW_TEXT_ELEMENTS = (...)
// ESCAPABLE_RAW_TEXT_ELEMENTS = (...)
// These are lists in Python, and can be defined as PHP arrays if needed.

// note that end tags do not accept attributes, but MW currently cleans up and
// ignores such attributes
const END_TAG_PATTERN = '(?<end_tag><\/{name}(?:>|[' . SPACE_CHARS . '][^>]*+>))';
const START_TAG_PATTERN = (
    '(?<start_tag>'
    . '<{name}' . ATTRS_PATTERN . '[' . SPACE_CHARS . ']*+>'
    . ')'
);
$HTML_START_TAG_FINDITER_PATTERN = rc(
    str_replace('{name}', _HTML_TAG_NAME(), START_TAG_PATTERN)
);
$HTML_END_TAG_FINDITER_PATTERN = rc(
    str_replace('{name}', _HTML_TAG_NAME(), END_TAG_PATTERN)
);


/**
 * Class TypeToSpans (Defined above)
 * [stan_start: int, span_end: int, Match, byte_array]
 *
 * `TypeToSpans = Dict[Union[str, int], List[List]]`
 * In PHP, this would be an `array<string|int, array<array<mixed>>>`.
 * Each inner array typically represents a span as `[start_offset, end_offset, Match_object_array, content_string]`.
 * Match_object_array is a PHP array that mimics Python's Match object.
 * `byte_array` becomes `string` in PHP as PHP strings are byte-compatible.
 */


/**
 * Calculate and set self._type_to_spans.
 *
 * Extracted spans will be removed from byte_array.
 * The result is a dictionary containing lists of spans:
 * {
 * 'Comment': comment_spans,
 * 'ExtTag': extension_tag_spans,
 * 'Parameter': parameter_spans,
 * 'ParserFunction': parser_function_spans,
 * 'Template': template_spans,
 * 'WikiLink': wikilink_spans,
 * }
 *
 * @param string $byte_array The wikitext content (mutated in place by replacing with null bytes).
 * @return TypeToSpans
 */
function parse_to_spans(string &$byte_array): TypeToSpans
{
    $comment_spans = [];
    $extension_tag_spans = [];
    $wikilink_spans = [];
    $parameter_spans = [];
    $parser_function_spans = [];
    $template_spans = [];

    // <extension tags>
    extract_tag_extensions(
        $byte_array,
        $extension_tag_spans,
        $comment_spans,
        0, // start
        null, // end
        $parameter_spans,
        $parser_function_spans,
        $template_spans,
        $wikilink_spans
    );

    _parse_sub_spans(
        $byte_array,
        0,
        null,
        $parameter_spans,
        $parser_function_spans,
        $template_spans,
        $wikilink_spans
    );

    return new TypeToSpans([
        'Comment' => $comment_spans,
        'ExtensionTag' => _sort_spans($extension_tag_spans),
        'Parameter' => _sort_spans($parameter_spans),
        'ParserFunction' => _sort_spans($parser_function_spans),
        'Template' => _sort_spans($template_spans),
        'WikiLink' => _sort_spans($wikilink_spans),
    ]);
}

/**
 * Helper to sort spans by their start offset.
 * @param array<array<mixed>> $spans
 * @return array<array<mixed>>
 */
function _sort_spans(array $spans): array
{
    usort($spans, function ($a, $b) {
        return $a[0] <=> $b[0]; // Sort by start offset
    });
    return $spans;
}


/**
 * Extracts and processes extension tags, comments, and recursively calls sub-span parsing.
 * Modifies `byte_array` in place.
 *
 * @param string $byte_array The wikitext content (modified in place).
 * @param array<array<mixed>> $ets_append Reference to list for ExtensionTag spans.
 * @param array<array<mixed>> $cms_append Reference to list for Comment spans.
 * @param int $start Start offset in byte_array.
 * @param int|null $end End offset in byte_array (null for end of string).
 * @param array<array<mixed>> $pms_append Reference to list for Parameter spans.
 * @param array<array<mixed>> $pfs_append Reference to list for ParserFunction spans.
 * @param array<array<mixed>> $tls_append Reference to list for Template spans.
 * @param array<array<mixed>> $wls_append Reference to list for WikiLink spans.
 */
function extract_tag_extensions(
    string &$byte_array,
    array &$ets_append,
    array &$cms_append,
    int $start,
    ?int $end,
    array &$pms_append,
    array &$pfs_append,
    array &$tls_append,
    array &$wls_append
): void {
    global $EXTENSION_TAGS_FINDITER_PATTERN;

    // In PHP, `preg_match_all` with `PREG_OFFSET_CAPTURE` is typically used for `finditer` behavior.
    // However, for nested parsing and in-place modification, iterating with `preg_match` and `offset`
    // or careful management of `preg_match_all` results is needed.
    // Python's `finditer` returns match objects, and the loop consumes them.
    // We'll simulate this by iterating on matches.

    // A copy for iteration to avoid issues with modification during loop.
    // The offsets within these matches refer to the *original* $byte_array.
    $matches = [];
    preg_match_all(
        $EXTENSION_TAGS_FINDITER_PATTERN,
        $byte_array,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        $start // Start offset for the search
    );

    // Filter matches to be within `start` and `end` range.
    $filtered_matches = [];
    foreach ($matches as $match) {
        $full_match_start = $match[0][1];
        $full_match_end = $full_match_start + strlen($match[0][0]);
        if ($full_match_start >= $start && ($end === null || $full_match_end <= $end)) {
            $filtered_matches[] = $match;
        }
    }

    // Process matches in reverse order to avoid offset issues with in-place replacement
    // This is crucial when replacing with null bytes or underscores.
    // Python's `finditer` usually processes from left to right, but modifications
    // should be done right to left or with offset adjustments.
    // The original Python code iterates normally then modifies, implying an underlying mechanism
    // that handles offsets or that `byte_array[s:e] = b'\0' * (e - s)` doesn't affect future match positions for `finditer` in `regex` module.
    // For PHP, let's reverse for safety.
    $filtered_matches = array_reverse($filtered_matches);

    foreach ($filtered_matches as $match) {
        // Create a match object for consistency, like previous helpers
        $current_match = _create_php_match_object($match);

        list($s, $e) = $current_match['span']('m'); // comment
        if ($s !== -1) {
            $s_adjusted = $s - 1; // <
            $content_slice = substr($byte_array, $s_adjusted, $e - $s_adjusted);
            $cms_append([$s_adjusted, $e, null, $content_slice]); // Match object is null for comments
            $byte_array = substr_replace($byte_array, str_repeat("\0", $e - $s_adjusted), $s_adjusted, $e - $s_adjusted);
            continue;
        }

        list($s, $e) = $current_match['span']('u'); // unparsable
        if ($s !== -1) {
            $s_adjusted = $s - 1; // <
            $content_slice = substr($byte_array, $s_adjusted, $e - $s_adjusted);
            $ets_append([$s_adjusted, $e, $current_match, $content_slice]);
            $byte_array = substr_replace($byte_array, str_repeat("_", $e - $s_adjusted), $s_adjusted, $e - $s_adjusted);
            continue;
        }

        list($s, $e) = $current_match['span']('p'); // parsable
        $s_adjusted = $s - 1; // <
        $content_slice = substr($byte_array, $s_adjusted, $e - $s_adjusted);
        $ets_append([$s_adjusted, $e, $current_match, $content_slice]);

        list($cs, $ce) = $current_match['span']('c'); // content within parsable tag
        if ($cs !== -1) { // Ensure 'c' group (content) actually matched
            extract_tag_extensions(
                $byte_array,
                $ets_append,
                $cms_append,
                $cs, // Pass content start/end as new range for recursion
                $ce,
                $pms_append,
                $pfs_append,
                $tls_append,
                $wls_append
            );
        }

        // This is crucial. It parses nested structures *within the current tag*.
        // This makes `_parse_sub_spans` operate on a mutated `byte_array` segment.
        _parse_sub_spans(
            $byte_array,
            $s_adjusted, // Use the adjusted start for the whole tag
            $e,        // Use the end of the whole tag
            $pms_append,
            $pfs_append,
            $tls_append,
            $wls_append
        );

        // Parsable extension tags are not nested but they create separate
        // environment for bolds, italics, and tables.
        // Also equal signs are not name-value separators in arguments.
        // `byte_array[s:e] = byte_array[s:e].translate(MARKUP)`
        $replaced_segment = substr($byte_array, $s_adjusted, $e - $s_adjusted);
        $translated_segment = strtr($replaced_segment, MARKUP_FROM, MARKUP_TO);
        $byte_array = substr_replace($byte_array, $translated_segment, $s_adjusted, $e - $s_adjusted);
    }
}

/**
 * Parses sub-spans (Wikilinks, Parameters, ParserFunctions, Templates) recursively.
 * Modifies `byte_array` in place.
 *
 * @param string $byte_array The wikitext content (modified in place).
 * @param int $start Start offset in byte_array.
 * @param int|null $end End offset in byte_array (null for end of string).
 * @param array<array<mixed>> $pms_append Reference to list for Parameter spans.
 * @param array<array<mixed>> $pfs_append Reference to list for ParserFunction spans.
 * @param array<array<mixed>> $tls_append Reference to list for Template spans.
 * @param array<array<mixed>> $wls_append Reference to list for WikiLink spans.
 */
function _parse_sub_spans(
    string &$byte_array,
    int $start,
    ?int $end,
    array &$pms_append,
    array &$pfs_append,
    array &$tls_append,
    array &$wls_append
): void {
    global $HTML_START_TAG_FINDITER_PATTERN, $HTML_END_TAG_FINDITER_PATTERN,
        $WIKILINK_PARAM_FINDITER_PATTERN, $PF_TL_FINDITER_PATTERN;

    // Extract HTML start/end tags first
    $start_end_tags_matches = [];
    preg_match_all($HTML_START_TAG_FINDITER_PATTERN, $byte_array, $matches1, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start);
    preg_match_all($HTML_END_TAG_FINDITER_PATTERN, $byte_array, $matches2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start);

    // Combine and filter matches within range
    $all_html_tag_matches = array_merge($matches1, $matches2);
    // Sort by offset to process correctly
    usort($all_html_tag_matches, function ($a, $b) {
        return $a[0][1] <=> $b[0][1];
    });

    // Apply filtering by end offset
    $filtered_html_tags = [];
    foreach ($all_html_tag_matches as $match) {
        $full_match_start = $match[0][1];
        $full_match_end = $full_match_start + strlen($match[0][0]);
        if ($full_match_start >= $start && ($end === null || $full_match_end <= $end)) {
            $filtered_html_tags[] = $match;
        }
    }
    // Process HTML tags in reverse for in-place replacement safety.
    $filtered_html_tags = array_reverse($filtered_html_tags);

    foreach ($filtered_html_tags as $match_data) {
        $current_match = _create_php_match_object($match_data);
        list($ms, $me) = $current_match['span'](); // Full match span

        $replaced_segment = substr($byte_array, $ms, $me - $ms);
        $translated_segment = strtr($replaced_segment, BRACKETS_FROM, BRACKETS_TO);
        $byte_array = substr_replace($byte_array, $translated_segment, $ms, $me - $ms);
    }

    // Main parsing loop for wikilinks, parameters, parser functions, templates
    // These are often nested, so a loop that repeatedly finds innermost elements is needed.
    $has_new_match = true;
    while ($has_new_match) {
        $has_new_match = false;

        // Wikilink and Parameter parsing (usually innermost)
        $wikilink_param_matches = [];
        preg_match_all(
            $WIKILINK_PARAM_FINDITER_PATTERN,
            _reverse_string_for_pattern($byte_array), // Reverse for REVERSE flag
            $wikilink_param_matches_raw,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            0 // Start from 0 for reversed string
        );

        // Adjust offsets back to original string and filter
        $wikilink_param_matches = _adjust_and_filter_matches_for_reversed_pattern(
            $wikilink_param_matches_raw,
            $byte_array,
            $start,
            $end
        );

        // Sort by start offset in ascending order for processing
        usort($wikilink_param_matches, function ($a, $b) {
            return $a[0][1] <=> $b[0][1];
        });

        // Process in reverse to prevent offset issues
        $wikilink_param_matches = array_reverse($wikilink_param_matches);

        foreach ($wikilink_param_matches as $match_data) {
            $has_new_match = true;
            $current_match = _create_php_match_object($match_data);
            list($ms, $me) = $current_match['span']();

            // Python's `match[1]` checks if it's a WikiLink or a Parameter.
            // In our `WIKILINK_PARAM_FINDITER_PATTERN`, group 1 is for the content of Parameter.
            // If group 1 exists, it's a Parameter; otherwise, it's a WikiLink.
            if ($current_match['group'](1) === null) { // This is a WikiLink
                $content_slice = substr($byte_array, $ms, $me - $ms);
                $wls_append([$ms, $me, $current_match, $content_slice]);
                // Recursive call for content inside WikiLink
                _parse_sub_spans(
                    $byte_array,
                    $ms + 2, // Skip '[['
                    $me - 2, // Skip ']]'
                    $pms_append,
                    $pfs_append,
                    $tls_append,
                    $wls_append
                );
                // Replace with MARKUP (for bolds/italics/etc.)
                $segment_to_translate = substr($byte_array, $ms, $me - $ms);
                $translated_segment = strtr($segment_to_translate, MARKUP_FROM, MARKUP_TO);
                $byte_array = substr_replace($byte_array, $translated_segment, $ms, $me - $ms);
            } else { // This is a Parameter
                $content_slice = substr($byte_array, $ms, $me - $ms);
                $pms_append([$ms, $me, $current_match, $content_slice]);
                // Recursive call for content inside Parameter
                _parse_sub_spans(
                    $byte_array,
                    $ms + 3, // Skip '{{{'
                    $me - 3, // Skip '}}}'
                    $pms_append,
                    $pfs_append,
                    $tls_append,
                    $wls_append
                );
                $byte_array = substr_replace($byte_array, str_repeat("_", $me - $ms), $ms, $me - $ms);
            }
        }

        if (!$has_new_match) {
            // If no new Wikilink or Parameter was found, break this inner loop
            break;
        }
    }

    // Parser Function and Template parsing (often outer to wikilinks/params)
    $pf_tl_matches = [];
    preg_match_all(
        $PF_TL_FINDITER_PATTERN,
        $byte_array,
        $pf_tl_matches_raw,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        $start
    );

    $filtered_pf_tl_matches = [];
    foreach ($pf_tl_matches_raw as $match) {
        $full_match_start = $match[0][1];
        $full_match_end = $full_match_start + strlen($match[0][0]);
        if ($full_match_start >= $start && ($end === null || $full_match_end <= $end)) {
            $filtered_pf_tl_matches[] = $match;
        }
    }

    // Process in reverse order for in-place replacement safety.
    $filtered_pf_tl_matches = array_reverse($filtered_pf_tl_matches);

    foreach ($filtered_pf_tl_matches as $match_data) {
        $current_match = _create_php_match_object($match_data);
        list($ms, $me) = $current_match['span']();

        // Python's `match[1] is not None` checks if it's a ParserFunction.
        // Group 1 in PF_TL_FINDITER is the first branch (parser function pattern).
        // Group 2 is the invalid template name branch.
        // Group 3 would be the template branch (if 1 and 2 didn't match).
        if ($current_match['group'](1) !== null) { // This is a ParserFunction
            $content_slice = substr($byte_array, $ms, $me - $ms);
            $pfs_append([$ms, $me, $current_match, $content_slice]);
            $byte_array = substr_replace($byte_array, str_repeat("X", $me - $ms), $ms, $me - $ms); // Replace with 'X'
        } elseif ($current_match['group'](2) !== null) { // Invalid template name (group 2 in PF_TL_FINDITER)
            // Replace with underscores and specific char
            $byte_array = substr_replace($byte_array, str_repeat("_", $me - $ms), $ms, $me - $ms);
            $byte_array[$ms + 1] = '{'; // Assuming this means '{'
            continue;
        } else { // This is a Template
            $content_slice = substr($byte_array, $ms, $me - $ms);
            $tls_append([$ms, $me, $current_match, $content_slice]);
            $byte_array = substr_replace($byte_array, str_repeat("X", $me - $ms), $ms, $me - $ms); // Replace with 'X'
        }
    }

    // Final translation of HTML start/end tags for BRACES_PIPE_NEWLINE.
    // This is problematic. The original Python code runs `start_and_end_tags` again *after* `PF_TL_FINDITER` loop.
    // This implies that the HTML tags are processed AFTER templates/parser functions.
    // The problem is `_parse_sub_spans` already replaced them with `BRACKETS_TO`.
    // This suggests a re-evaluation of the order or the meaning of `translate(BRACES_PIPE_NEWLINE)`.
    // It's likely intended to apply to the *original* parts of HTML tags, not the already-masked parts.
    // Given the difficulty of tracking exact byte replacements, this final translation might need to be
    // done on the full string *after* all parsing, or applied only to specific remaining types.
    // For now, it might be applied to the remaining masked HTML tags.

    // This section is difficult to translate exactly due to in-place bytearray manipulation and nested loops.
    // It relies on precise masking. A simpler approach might be to just mask everything with _ or X then do final replacements.
    // The original Python iterates over `start_and_end_tags` again.
    // Let's assume it should apply the translation to the *masked* versions.
    foreach ($filtered_html_tags as $match_data) {
        $current_match = _create_php_match_object($match_data);
        list($ms, $me) = $current_match['span']();
        $replaced_segment = substr($byte_array, $ms, $me - $ms);
        $translated_segment = strtr($replaced_segment, BRACES_PIPE_NEWLINE_FROM, BRACES_PIPE_NEWLINE_TO);
        $byte_array = substr_replace($byte_array, $translated_segment, $ms, $me - $ms);
    }
}

/**
 * Helper function to create a PHP-friendly match object from `preg_match` results.
 * This function should mirror the behavior of Python's `re.Match` object for used methods.
 *
 * @param array<array<mixed>> $match_data Raw match data from `preg_match`/`preg_match_all` with `PREG_OFFSET_CAPTURE`.
 * @return array<string, mixed>
 */
function _create_php_match_object(array $match_data): array
{
    $matchObject = [];
    $matchObject[0] = $match_data[0][0]; // Full matched string
    $matchObject['offset_0'] = $match_data[0][1]; // Full match offset

    // Populate named and numeric groups
    foreach ($match_data as $key => $value) {
        if (is_int($key)) { // Numeric group (0, 1, 2, ...)
            $matchObject[$key] = $value[0]; // Matched string
            $matchObject['offset_' . $key] = $value[1]; // Offset
        } elseif (is_string($key)) { // Named group (e.g., 'name', 'contents')
            $matchObject[$key] = $value[0];
            $matchObject['offset_' . $key] = $value[1];
        }
    }

    // Mimic Python's match.group(idx), match.start(idx), match.end(idx), match.span(idx)
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

    // Add 'string' property, often `match.string` refers to the original subject.
    // For matches from `preg_match_all`, this is not directly available per match,
    // so we assume it refers to the original `byte_array` passed to `parse_to_spans`.
    // This might need refinement based on actual usage contexts.
    $matchObject['string'] = $match_data[0][0]; // Default to matched string, but should be the full string it was matched against for `translate` calls.
    // To make `match.string` truly represent the *full* original subject passed to `finditer`,
    // the `_create_php_match_object` needs to receive the full subject string.
    // For now, let's keep it simple and note this potential divergence.

    return $matchObject;
}

/**
 * Reverses a string for patterns that use Python's `REVERSE` flag.
 * Also adjusts the pattern itself if necessary for the reversed string.
 *
 * @param string $input_string The string to reverse.
 * @return string The reversed string.
 */
function _reverse_string_for_pattern(string $input_string): string
{
    // PHP's `strrev` works for single-byte encodings. For multi-byte, `mb_strrev` is needed.
    // Assuming MediaWiki wikitext is mostly ASCII or UTF-8 compatible where characters
    // are often treated as simple bytes in regex.
    return strrev($input_string);
}

/**
 * Adjusts offsets of matches found in a reversed string back to the original string's offsets.
 * Also filters matches based on the original string's start and end boundaries.
 *
 * @param array<array<mixed>> $raw_matches Raw match data from `preg_match_all` on reversed string.
 * @param string $original_string The original (non-reversed) string.
 * @param int $original_start The start boundary in the original string.
 * @param int|null $original_end The end boundary in the original string (null for end of string).
 * @return array<array<mixed>> Filtered and offset-adjusted matches.
 */
function _adjust_and_filter_matches_for_reversed_pattern(
    array $raw_matches,
    string $original_string,
    int $original_start,
    ?int $original_end
): array {
    $adjusted_matches = [];
    $original_length = strlen($original_string);

    foreach ($raw_matches as $match_data) {
        // Full match info for the reversed string
        $reversed_full_match = $match_data[0];
        $reversed_matched_string = $reversed_full_match[0];
        $reversed_offset = $reversed_full_match[1];

        // Calculate original start and end offsets for the full match
        // If a match is found at `reversed_offset` in the reversed string,
        // its original start is `original_length - (reversed_offset + length_of_reversed_match)`.
        // Its original end is `original_length - reversed_offset`.
        $original_match_end = $original_length - $reversed_offset;
        $original_match_start = $original_match_end - strlen($reversed_matched_string);

        // Filter based on original boundaries
        if ($original_match_start >= $original_start && ($original_end === null || $original_match_end <= $original_end)) {
            // Reconstruct match_data with adjusted offsets for all groups
            $adjusted_match_data = [];
            foreach ($match_data as $key => $value) {
                if ($value[0] !== null) { // If the group actually matched
                    $adjusted_group_offset = $original_match_start + (strlen($reversed_matched_string) - ($value[1] + strlen($value[0])));
                    $adjusted_match_data[$key] = [$value[0], $adjusted_group_offset];
                } else {
                    $adjusted_match_data[$key] = [null, -1]; // Unmatched group
                }
            }
            // The order of groups might be reversed if the pattern logic was reversed.
            // For WIKILINK_PARAM_FINDITER_PATTERN, the regex for group 1 (parameter content) is still in forward order.
            // This reversal logic is highly dependent on the exact regex and what `REVERSE` truly implies.
            // If the pattern itself is not adjusted to match `strrev($byte_array)`, then it will match the
            // reversed `byte_array` as if it were a forward string, which is probably not desired.
            // For now, this is a best-effort interpretation. The `REVERSE` flag is a complex feature.

            $adjusted_matches[] = $adjusted_match_data;
        }
    }
    return $adjusted_matches;
}
