<?php

declare(strict_types=1);

namespace Wtp\Parser\_wikitextmain;

use Wtp\Parser\WikiTextBase; // Assuming WikiTextBase is in Wtp\Parser namespace
use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans
use Wtp\Parser\_wikitext_utils\SPAN_PARSER_TYPES; // Assuming SPAN_PARSER_TYPES is in Wtp\Parser\_wikitext_utils

// Import other necessary classes from their specific namespaces
use Wtp\Node\Template;
use Wtp\Node\ParserFunction;
use Wtp\Node\Parameter;
use Wtp\Node\ExternalLink;
use Wtp\Node\Bold;
use Wtp\Node\Italic;
use Wtp\Node\Table;
use Wtp\Node\Section;
use Wtp\Node\WikiLink;
use Wtp\Node\WikiList;
use Wtp\Node\Comment;
use Wtp\Node\Tag;


// Import functions/constants from other namespaces
use function Wtp\Parser\insort_right; // From wikitext_base.php
use function Wtp\Parser\bisect_left; // From wikitext_base.php
use function Wtp\Parser\bisect_right; // From wikitext_base.php
use function Wtp\Parser\islice; // From wikitext_base.php
use function Wtp\Compat\unescape; // Assuming unescape from html module is in Compat namespace
use function Wtp\Compat\wcswidth; // Assuming wcswidth from wcwidth module is in Compat namespace

use const Wtp\Parser\_config\KNOWN_FILE_EXTENSIONS; // From _config.php
use function Wtp\Parser\_config\_get_parsable_tag_extensions; // From _config.php
use function Wtp\Parser\_config\_tag_extensions; // From _config.php (this should be a function now)

use const Wtp\Parser\_spans\END_TAG_PATTERN; // From _spans.php
use const Wtp\Parser\_spans\START_TAG_PATTERN; // From _spans.php
use function Wtp\Parser\_spans\ATTRS_MATCH_FUNCTION; // From _spans.php


use function Wtp\Parser\_wikitext_utils\NAME_CAPTURING_HTML_START_TAG_FINDITER; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\EXTERNAL_LINK_FINDITER; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\INVALID_EL_TPP_CHRS_SUB; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\SECTIONS_FULLMATCH; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\SECTIONS_TOP_LEVELS_ONLY; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\TABLE_FINDITER; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\substitute_apostrophes; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\BOLD_FINDITER; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\ITALIC_FINDITER; // From _wikitext_utils.php
use const Wtp\Parser\_wikitext_utils\WS; // From _wikitext_utils.php
use function Wtp\Parser\_wikitext_utils\_table_to_text; // From _wikitext_utils.php
use const Wtp\Node\MULTILINE; // From _wikilist.php / regex constants
use const Wtp\Node\DOTALL; // From _wikilink.php / regex constants
use const Wtp\Node\IGNORECASE; // From _parser.php / regex constants
use const Wtp\Node\REVERSE; // From _template.php / regex constants
use const Wtp\Node\LIST_PATTERN_FORMAT; // From _wikilist.php

// Regex functions for search/finditer
use function Wtp\Node\create_match_object_for_template; // From _template.php (rename/generalize if needed)
use function Wtp\Node\match_with_span_and_group; // From _comment_bold_italic.php (rename/generalize if needed)


/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة html في Python.
 * المرجع: https://docs.python.org/3/library/html.html#html.unescape
 */
function unescape(string $s): string
{
    return \Wtp\Compat\unescape($s);
}

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة wcwidth في Python.
 * المرجع: https://github.com/jquast/wcwidth
 */
function wcswidth(string $str): int
{
    return \Wtp\Compat\wcswidth($str);
}


/**
 * Helper to match a pattern in a string with a given position.
 * This is similar to Python's `regex.match(pattern, string, pos=...)`
 * but for PHP's `preg_match`.
 *
 * @param string $pattern The regex pattern (already formatted with delimiters).
 * @param string $subject The string to search in.
 * @param int $pos The offset to start matching from.
 * @return array|null A match object array if found, null otherwise.
 */
function match_at_pos(string $pattern, string $subject, int $pos): ?array
{
    // Use `preg_match` with the `offset` parameter.
    $matches = [];
    $result = preg_match($pattern, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $pos);

    if ($result === 1) {
        return _create_php_match_object_simple($matches, $subject); // Use a simple helper for match obj
    }
    return null;
}

/**
 * Helper to create a basic match object from `preg_match` results.
 * This is a simplified version for `match_at_pos`.
 * @param array $matches Raw matches from `preg_match`.
 * @param string $original_subject The original string matched against.
 * @return array
 */
function _create_php_match_object_simple(array $matches, string $original_subject): array
{
    $matchObject = [];
    $matchObject[0] = $matches[0][0]; // Full matched string
    $matchObject['offset_0'] = $matches[0][1]; // Full match offset

    $matchObject['group'] = function ($group) use ($matches) {
        return $matches[$group][0] ?? null;
    };
    $matchObject['start'] = function ($group = 0) use ($matches) {
        return $matches[$group][1] ?? -1;
    };
    $matchObject['end'] = function ($group = 0) use ($matches) {
        if (isset($matches[$group][0]) && $matches[$group][1] !== -1) {
            return $matches[$group][1] + strlen($matches[$group][0]);
        }
        return -1;
    };
    $matchObject['span'] = function ($group = 0) use ($matchObject) {
        $start = $matchObject['start']($group);
        $end = $matchObject['end']($group);
        return [$start, $end];
    };
    $matchObject['string'] = $original_subject; // Store original subject string
    return $matchObject;
}

/**
 * Helper function for `finditer` behavior.
 * Returns a Generator that yields match objects.
 *
 * @param string $pattern The regex pattern (already formatted with delimiters).
 * @param string $subject The string to search in.
 * @param int $flags Regex flags like MULTILINE, DOTALL.
 * @param int $offset Where to start search.
 * @param int|null $length How many characters to search (null for to end).
 * @return \Generator<array> Yields match object arrays.
 */
function finditer_php(string $pattern, string $subject, int $flags = 0, int $offset = 0, ?int $length = null): \Generator
{
    // PHP's preg_match_all can get all matches at once.
    // If $flags has REVERSE, need to reverse string and adjust pattern.
    $original_subject = $subject;
    $adjusted_offset = $offset;
    $adjusted_length = $length;
    $use_reverse = false;

    // TODO: This REVERSE flag handling needs to be robust for all patterns that use it.
    // A simple `strrev` for subject and then `strrev` for the pattern is often not sufficient
    // as regex semantics change (e.g., lookaheads/lookbehinds).
    // For now, if REVERSE is passed, a warning.
    if (($flags & REVERSE) === REVERSE) {
        warn('`REVERSE` flag is complex to translate from Python `regex` module. Behavior might differ.');
        // If the pattern itself needs to be reversed and re-interpreted, it's a big task.
        // For example, `(?!...)` becomes `(?<!...)` in reverse.
        // For simplicity here, we'll just remove the flag and proceed, which will likely break patterns
        // designed for reverse matching.
        $flags &= ~REVERSE; // Remove the REVERSE flag for PHP's preg_match_all
        // You might need to explicitly strrev($subject) here and then adjust offsets.
        // `WIKILINK_PARAM_FINDITER` is one that uses it.
    }

    $matches = [];
    preg_match_all(
        $pattern,
        $subject,
        $matches,
        PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        $adjusted_offset
    );

    // Adjust for `length` if provided
    $filtered_matches = [];
    $end_pos = ($adjusted_length === null) ? strlen($subject) : $adjusted_offset + $adjusted_length;

    foreach ($matches[0] as $idx => $full_match_info) {
        $start_pos = $full_match_info[1];
        $end_of_match = $start_pos + strlen($full_match_info[0]);

        if ($start_pos >= $adjusted_offset && $end_of_match <= $end_pos) {
            $current_match_data = [];
            foreach ($matches as $group_key => $group_matches) {
                // $group_matches[$idx] gives [matched_string, offset] for this specific match occurrence.
                $current_match_data[$group_key] = $group_matches[$idx];
            }
            $filtered_matches[] = $current_match_data;
        }
    }

    // Convert to Python-like match objects (arrays with closures for methods)
    foreach ($filtered_matches as $match_data_raw) {
        yield _create_php_match_object_complex($match_data_raw, $original_subject);
    }
}


/**
 * Helper to create a PHP-friendly match object from `preg_match_all` results (for finditer).
 * This provides `group`, `start`, `end`, `span`, and `captures` (though `captures`
 * might still be a placeholder for truly repeating groups in a single match).
 *
 * @param array $match_data_raw Raw match data for one occurrence from `preg_match_all` (e.g., `$matches[0][idx]`, `$matches['name'][idx]`).
 * @param string $original_subject The original string against which the regex was run.
 * @return array
 */
function _create_php_match_object_complex(array $match_data_raw, string $original_subject): array
{
    $matchObject = [];
    $matchObject[0] = $match_data_raw[0][0]; // Full matched string
    $matchObject['offset_0'] = $match_data_raw[0][1]; // Full match offset

    // Store named and numeric group values and their offsets
    foreach ($match_data_raw as $key => $value) {
        // $value is [matched_string, offset]
        if (is_int($key)) { // Numeric group
            $matchObject[$key] = $value[0];
            $matchObject['offset_' . $key] = $value[1];
        } elseif (is_string($key)) { // Named group
            $matchObject[$key] = $value[0];
            $matchObject['offset_' . $key] = $value[1];
        }
    }

    $matchObject['group'] = function ($group_key) use ($matchObject) {
        return $matchObject[$group_key] ?? null;
    };
    $matchObject['start'] = function ($group_key = 0) use ($matchObject) {
        return $matchObject['offset_' . $group_key] ?? -1;
    };
    $matchObject['end'] = function ($group_key = 0) use ($matchObject) {
        if (isset($matchObject['offset_' . $group_key]) && isset($matchObject[$group_key])) {
            return $matchObject['offset_' . $group_key] + strlen($matchObject[$group_key]);
        }
        return -1;
    };
    $matchObject['span'] = function ($group_key = 0) use ($matchObject) {
        $start = $matchObject['start']($group_key);
        $end = $matchObject['end']($group_key);
        return [$start, $end];
    };

    // `spans` method for repeating groups is complex with a single `preg_match_all`.
    // It's typically implemented by re-running `preg_match_all` for specific repeating sub-patterns.
    // For `finditer_php`, each yielded match is for *one* overall occurrence,
    // so `spans` on *that* match refers to repeating groups *within* it.
    // This part is crucial and requires accurate `preg_match_all` with `PREG_PATTERN_ORDER`.
    $matchObject['spans'] = function ($groupName) use ($match_data_raw, $original_subject, $matchObject) {
        // Need to identify if `groupName` is a repeating group in the *original pattern*.
        // This helper for `_create_php_match_object_complex` is used *after* `preg_match_all`
        // so it already has all captures for this *one* top-level match.
        // `match_data_raw` would contain all matches for named groups for this specific instance.

        $spans_list = [];
        if (isset($match_data_raw[$groupName]) && is_array($match_data_raw[$groupName])) {
            // If the group matched multiple times within this single overall match:
            // This is complex. `preg_match_all` with `PREG_PATTERN_ORDER` or `PREG_SET_ORDER`
            // and `UNMATCHED_AS_NULL` will give specific structure.
            // Example for `(?P<arg>...)` in `TL_NAME_ARGS_FULLMATCH_PATTERN`:
            // `matches['arg']` will be `[[match1, off1], [match2, off2], ...]`
            // The `match_data_raw[$groupName]` might already be this list.

            // Assume $match_data_raw[$groupName] is an array of [string, offset] pairs if it's a repeating group.
            foreach ($match_data_raw[$groupName] as $capture_info) {
                if ($capture_info[0] !== null) {
                    $start_offset = $capture_info[1];
                    $end_offset = $start_offset + strlen($capture_info[0]);
                    $spans_list[] = [$start_offset, $end_offset];
                }
            }
        }
        return $spans_list;
    };

    $matchObject['captures'] = function ($groupName) use ($match_data_raw) {
        $captures_list = [];
        if (isset($match_data_raw[$groupName]) && is_array($match_data_raw[$groupName])) {
            foreach ($match_data_raw[$groupName] as $capture_info) {
                if ($capture_info[0] !== null) {
                    $captures_list[] = $capture_info[0];
                }
            }
        }
        return $captures_list;
    };


    $matchObject['string'] = $original_subject; // Store original subject string
    return $matchObject;
}



/**
 * Class WikiText
 *
 * Extends WikiTextBase to provide more specific wikitext parsing and manipulation.
 */
class WikiText extends WikiTextBase
{
    // _type is inherited from WikiTextBase, but overridden here.
    protected string $_type = 'WikiText';

    // __slots__ are inherited implicitly.

    /**
     * Return a plain text string representation of self.
     *
     * @param bool|callable $replace_templates If true, remove templates; if callable, apply function.
     * @param bool|callable $replace_parser_functions If true, remove parser functions; if callable, apply function.
     * @param bool $replace_parameters
     * @param bool $replace_tags
     * @param bool $replace_external_links
     * @param bool $replace_wikilinks
     * @param bool $unescape_html_entities
     * @param bool $replace_bolds_and_italics
     * @param bool|callable $replace_tables If true, remove tables; if callable, apply function.
     * @param bool $_is_root_node Internal flag, do not set manually.
     * @return string
     */
    public function plain_text(
        bool|callable $replace_templates = true,
        bool|callable $replace_parser_functions = true,
        bool $replace_parameters = true,
        bool $replace_tags = true,
        bool $replace_external_links = true,
        bool $replace_wikilinks = true,
        bool $unescape_html_entities = true,
        bool $replace_bolds_and_italics = true,
        bool|callable $replace_tables = null, // Default to _table_to_text function from _wikitext_utils.php
        bool $_is_root_node = false
    ): string {
        global $WS; // Access global WS constant
        global $KNOWN_FILE_EXTENSIONS; // Access global constant

        // Handle default for replace_tables if not explicitly set.
        if ($replace_tables === null) {
            $replace_tables = _table_to_text(...); // Use callable to _table_to_text
        }


        if (!$_is_root_node) {
            list($s, $e, $m, $b) = $this->_span_data;
            $tts = $this->_inner_type_to_spans_copy();

            // Create a new WikiText instance representing the *content* of the current node.
            // This is crucial for operating on a "sub-view" of the main wikitext.
            $parsed = new WikiText([$this->_lststr[0]], $tts);

            $new_end = $e - $s; // Length of the current node's string.

            // Find the span for the 'WikiText' type itself within the new `tts`.
            // This span represents the entire current node's content relative to its new `_lststr`.
            $found_span = false;
            foreach ($tts[$this->_type] as $span_data_item) {
                if ($span_data_item[1] === $new_end) {
                    $parsed->_span_data = $span_data_item;
                    $found_span = true;
                    break;
                }
            }
            if (!$found_span) { // self is a dead span (e.g., if its content was entirely replaced)
                $parsed->_span_data = [0, 0, null, '']; // Empty span
            }
        } else {
            $tts = $this->_type_to_spans; // Use the root's type_to_spans
            $parsed = $this; // Operate on the root node directly
        }

        // Initialize a mutable list of characters, replacing non-removable characters with nulls.
        // This is like Python's `list(parsed.string)`.
        $lst = str_split($parsed->string()); // Get the string representation of the parsed WikiText, then convert to char array

        $remove = function (int $b, int $e) use (&$lst): void {
            for ($i = $b; $i < $e; $i++) {
                $lst[$i] = null;
            }
        };

        // Remove comments first (always removed unless explicitly kept, but plain_text removes them)
        if (isset($tts['Comment'])) {
            foreach ($tts['Comment'] as list($b, $e, $_, $_)) {
                $remove($b, $e);
            }
        }


        // Handle templates
        if (is_callable($replace_templates)) {
            foreach ($parsed->templates() as $template) { // Call templates() method
                list($b, $e) = $template->span(); // Use span() method for current node's span.
                if ($lst[$b] === null) { // Already overwritten by a parent replacement
                    continue;
                }
                $replacement = $replace_templates($template);
                if ($replacement !== null) {
                    $lst[$b] = $replacement; // Replace the first char with the full replacement.
                    $remove($b + 1, $e); // Remove the rest of the original span.
                } else {
                    $remove($b, $e); // If callable returns null, remove the whole template.
                }
            }
        } elseif ($replace_templates) {
            if (isset($tts['Template'])) {
                foreach ($tts['Template'] as list($b, $e, $_, $_)) {
                    $remove($b, $e);
                }
            }
        }

        // Handle parser functions
        if (is_callable($replace_parser_functions)) {
            foreach ($parsed->parser_functions() as $pf) { // Call parser_functions() method
                list($b, $e) = $pf->span();
                if ($lst[$b] === null) {
                    continue;
                }
                $replacement = $replace_parser_functions($pf);
                if ($replacement !== null) {
                    $lst[$b] = $replacement;
                    $remove($b + 1, $e);
                } else {
                    $remove($b, $e);
                }
            }
        } elseif ($replace_parser_functions) {
            if (isset($tts['ParserFunction'])) {
                foreach ($tts['ParserFunction'] as list($b, $e, $_, $_)) {
                    $remove($b, e);
                }
            }
        }

        // Handle external links
        if ($replace_external_links) {
            foreach ($parsed->external_links() as $el) { // Call external_links() method
                if ($el->in_brackets()) { // Call in_brackets() method
                    list($b, $e) = $el->span();
                    $text = $el->text(); // Call text() method
                    if ($text === null) {
                        $remove($b, $e); // Remove the whole link if no display text
                    } else {
                        // Remove everything except the display text
                        // Python: `remove(b, e - 1 - len(text))` removes URL and leading space/pipe
                        // `remove(e - 1, e)` removes the closing bracket.
                        $text_len = strlen($text);
                        $remove($b, $e - 1 - $text_len); // Remove from start up to (close_bracket - 1 - text_len)
                        $remove($e - 1, $e); // Remove the last char (usually ']')
                    }
                }
                // Bare links (e.g. http://example.com) are left as is, as they are "plain text" URLs.
            }
        }

        // Replacing bold and italics should be done before wikilinks and tags.
        if ($replace_bolds_and_italics) {
            foreach ($parsed->get_bolds_and_italics() as $bi) { // Call get_bolds_and_italics() method
                list($b, $e) = $bi->span();
                // `bi._match` is typically Match object, and `span(1)` gets content span.
                list($ib, $ie) = $bi->_match()['span'](1); // Assuming _match returns a match array.
                $remove($b, $b + $ib); // Remove opening markup
                $remove($b + $ie, $e); // Remove closing markup
            }
        }

        // Handle parameters
        if ($replace_parameters) {
            foreach ($parsed->parameters() as $p) { // Call parameters() method
                list($b, $e) = $p->span();
                // `p._shadow.find(124)` finds the pipe character (ord 124).
                $default_start = strpos($p->_shadow, '|'); // Use `strpos` directly on string
                if ($default_start !== false) {
                    // Parameter has a default value. Remove `{{{param|` and `}}}`
                    $remove($b, $b + $default_start + 1); // Remove from start to just after '|'
                    $remove($e - 3, e); // Remove '}}}'
                } else {
                    // No default value. Remove `{{{param}}}` entirely.
                    $remove($b, $e);
                }
            }
        }

        // Handle tags
        if ($replace_tags) {
            foreach ($parsed->get_tags() as $t) { // Call get_tags() method
                list($b, $e) = $t->span();
                // `t._match.span('contents')` gets the span of the tag's content.
                list($cb, $ce) = $t->_match()['span']('contents'); // Assuming _match returns a match array.
                if ($cb !== -1) { // Not a self-closing tag (has contents)
                    $remove($b, $b + $cb); // Remove opening tag markup
                    $remove($b + $ce, $e); // Remove closing tag markup
                } else { // Self-closing tag, remove entirely
                    $remove($b, $e);
                }
            }
        }

        // Handle wikilinks
        if ($replace_wikilinks) {
            foreach ($parsed->wikilinks() as $w) { // Call wikilinks() method
                list($b, $e) = $w->span();
                $title = $w->title(); // Call title() method

                // Check for image links (starting with ':' or having known file extensions)
                if (str_starts_with($title, ':') || in_array(mb_strtolower(mb_substr($title, mb_strrpos($title, '.') + 1)), KNOWN_FILE_EXTENSIONS)) {
                    $remove($b, $e); // Remove image link entirely
                } else {
                    // Regular wikilink. Keep only the display text or target.
                    // `w._match.span(4)` gets span of 'text' group.
                    list($tb, $te) = $w->_match()['span'](4); // Assuming _match returns a match array.
                    if ($tb !== -1) { // Has display text
                        $remove($b, $b + $tb); // Remove leading `[[` and target
                        $remove($b + $te, $e); // Remove trailing `]]`
                    } else {
                        // No display text, keep only the target.
                        // `w._match.span(1)` gets span of 'target' group.
                        list($tb, $te) = $w->_match()['span'](1); // Assuming _match returns a match array.
                        $remove($b, $b + $tb); // Remove leading `[[`
                        $remove($b + $te, e); // Remove trailing `]]`
                    }
                }
            }
        }

        // Handle tables
        if (is_callable($replace_tables)) {
            foreach ($parsed->get_tables() as $table) { // Call get_tables() method
                list($b, $e) = $table->span();
                if ($lst[$b] === null) {
                    continue; // Already overwritten
                }
                // Construct a temporary string from the current `lst` slice for the table's content
                // `''.join([c for c in lst[b:e] if c is not None])`
                $table_content_chars = array_filter(array_slice($lst, $b, $e - $b), fn($c) => $c !== null);
                $table_content_string = implode('', $table_content_chars);

                // Create a new Table object for processing its plain text representation
                $replacement = $replace_tables(new Table($table_content_string));

                if ($replacement !== null) {
                    $lst[$b] = $replacement;
                    $remove($b + 1, $e);
                } else {
                    $remove($b, $e);
                }
            }
        }

        // Final join and unescape HTML entities
        $string = implode('', array_filter($lst, fn($c) => $c !== null)); // Filter out nulls
        if ($unescape_html_entities) {
            $string = unescape($string);
        }
        return $string;
    }

    /**
     * Return a pretty-print formatted version of `self.string`.
     *
     * @param string $indent Indentation string.
     * @param bool $remove_comments Whether to remove comments.
     * @return string
     */
    public function pformat(string $indent = '    ', bool $remove_comments = false): string
    {
        global $WS; // Access global WS constant

        $lststr0 = $this->_lststr[0]; // Original full string.
        list($s, $e, $m, $b) = $this->_span_data; // Current object's span data.

        // Create a new WikiText instance for formatting, operating on a copy of the string segment.
        $parsed = new WikiText([substr($lststr0, $s, $e - $s)], $this->_inner_type_to_spans_copy());

        // Set the _span_data for the new parsed object, making it refer to its own content.
        // Python's `b[:] if b is not None else None` makes a copy of bytearray.
        $parsed_span_data = [0, $e - $s, $m, ($b !== null) ? (is_string($b) ? $b : implode('', array_map('chr', $b->getArrayCopy()))) : null];
        $parsed->_span_data = $parsed_span_data;

        // Ensure 'WikiText' type is set for the new parsed object.
        if (!isset($parsed->_type_to_spans['WikiText'])) {
            $parsed->_type_to_spans['WikiText'] = [];
        }
        // This makes the newly created `parsed` object itself discoverable as a 'WikiText' type in its own `tts`.
        // If it already exists, it should be the correct reference.
        // `insort_right` with `span` needs to be used for consistency if the span list isn't manually sorted.
        insort_right($parsed->_type_to_spans['WikiText'], $parsed_span_data, fn($item) => $item[0]);

        // Remove comments
        if ($remove_comments) {
            foreach ($parsed->comments() as $c) { // Call comments() method
                $c->delete_string(); // Call delete_string() method
            }
        } else {
            // Only remove comments that contain whitespace.
            foreach ($parsed->comments() as $c) { // Call comments() method
                if (!trim($c->contents(), $WS)) { // Call contents() method
                    $c->delete_string(); // Call delete_string() method
                }
            }
        }

        // Format templates
        // Iterate in reverse for safe modification.
        foreach (array_reverse($parsed->templates()) as $template) { // Call templates() method
            $stripped_tl_name = trim($template->name(), $WS); // Call name() method

            // Adjust template name formatting
            // Python's `stripped_tl_name[0] == '{'` checks the first char.
            // For templates, name itself is usually not `{`, but it could be if it's `{{{{..}}..}}`.
            // The original logic is for when name *starts* with `{`.
            $template->set_name( // Call set_name() method
                (str_starts_with($stripped_tl_name, '{')) ?
                    ' ' . $stripped_tl_name . ' ' :
                    $stripped_tl_name
            );

            $args = $template->arguments(); // Call arguments() method
            if (empty($args)) {
                continue;
            }

            $not_a_parser_function = null;
            if (str_contains($stripped_tl_name, ':')) {
                // If it contains ':', it might be a parser function. Don't assume.
                $not_a_parser_function = null; // Unsure
            } else {
                $not_a_parser_function = true; // Assume it's a regular template
            }

            // Required for alignment
            $arg_stripped_names = [];
            $arg_positionalities = [];
            $arg_name_lengths = [];

            foreach ($args as $a) {
                $stripped_name = trim($a->name(), $WS); // Call name() method
                $positional_val = $a->positional(); // Call positional() method

                $arg_stripped_names[] = $stripped_name;
                $arg_positionalities[] = $positional_val;

                // wcswidth needs to handle non-ASCII characters, especially Arabic 'لا' (lam-alef ligature)
                // If positional, length is 0 for alignment calculation.
                $arg_name_lengths[] = ($positional_val) ? 0 : wcswidth(str_replace('لا', '?', $stripped_name));
            }
            $max_name_len = empty($arg_name_lengths) ? 0 : max($arg_name_lengths);

            // Format template.name.
            $level = $template->nesting_level(); // Call nesting_level() method
            $newline_indent = "\n" . str_repeat($indent, $level);
            $template->set_name($template->name() . $newline_indent); // Append newline and indent to name

            $last_comment_indent = '';
            if ($level === 1) {
                $last_comment_indent = '';
            } else {
                $last_comment_indent = '';
            }

            // Special formatting for the last argument.
            $last_arg = array_pop($args); // Get last arg, removing it from $args
            if ($last_arg === null) {
                continue; // Should not happen if args was not empty
            }

            $last_is_positional = array_pop($arg_positionalities);
            $last_value = $last_arg->value(); // Call value() method
            $last_stripped_value = trim($last_value, $WS);

            $stop_conversion = false; // Controls whether further stripping/alignment is done.

            if ($last_is_positional && $last_value !== $last_stripped_value) {
                $stop_conversion = true;
                if (!str_ends_with($last_value, "\n" . str_repeat($indent, $level - 1))) {
                    $last_arg->set_value($last_value . $last_comment_indent);
                }
            } elseif ($not_a_parser_function) {
                $stop_conversion = false;
                $last_arg_stripped_name = array_pop($arg_stripped_names);
                $last_arg_name_len = array_pop($arg_name_lengths);

                $last_arg->set_name(
                    ' ' . $last_arg_stripped_name . ' ' .
                        str_repeat(' ', $max_name_len - $last_arg_name_len)
                );
                $last_arg->set_value(
                    ' ' . $last_stripped_value . "\n" . str_repeat($indent, $level - 1)
                );
            } elseif ($last_is_positional) {
                $stop_conversion = true;
                $last_arg->set_value($last_value . $last_comment_indent);
            } else {
                $stop_conversion = true;
                $last_arg->set_name(' ' . ltrim($last_arg->name(), $WS));
                if (!str_ends_with($last_value, "\n" . str_repeat($indent, $level - 1))) {
                    $last_arg->set_value(rtrim($last_value, $WS) . ' ' . $last_comment_indent);
                }
            }

            if (empty($args)) { // If only one argument existed, already processed
                continue;
            }

            $comment_indent = '';

            // Iterate remaining arguments in reverse (original order from template's perspective is from right to left).
            // zip(reversed(args), reversed(arg_stripped_names), ...)
            $reversed_args_data = array_map(
                null,
                array_reverse($args),
                array_reverse($arg_stripped_names),
                array_reverse($arg_positionalities),
                array_reverse($arg_name_lengths)
            );

            foreach ($reversed_args_data as list($arg, $stripped_name, $positional, $arg_name_len)) {
                $value = $arg->value();
                $stripped_value = trim($value, $WS);

                if ($stop_conversion) {
                    if (!str_ends_with($value, $newline_indent)) {
                        $arg->set_value($value . $comment_indent);
                    }
                } elseif ($positional && $value !== $stripped_value) {
                    $stop_conversion = true; // Stop conversion for subsequent args
                    if (!str_ends_with($value, $newline_indent)) {
                        $arg->set_value($value . $comment_indent);
                    }
                } elseif ($not_a_parser_function) {
                    $arg->set_name(
                        ' ' . $stripped_name . ' ' .
                            str_repeat(' ', $max_name_len - $arg_name_len)
                    );
                    $arg->set_value(' ' . $stripped_value . $newline_indent);
                }
            }
        }

        // Format parser functions
        foreach (array_reverse($parsed->parser_functions()) as $func) { // Call parser_functions() method
            $name = $func->name(); // Call name() method
            $ls_name = ltrim($name, $WS);
            $lws = strlen($name) - strlen($ls_name); // Leading whitespace length

            if ($lws > 0) {
                // Delete leading whitespace after `{{`
                $func->offsetUnset(2, $lws);
            }

            // Check for specific parser functions that are whitespace sensitive
            if (mb_strtolower($ls_name) === '#tag' || mb_strtolower($ls_name) === '#invoke' || $ls_name === '') {
                continue; // Skip formatting for these.
            }

            $args = $func->arguments(); // Call arguments() method
            if (empty($args)) {
                continue;
            }

            $level = $func->nesting_level(); // Call nesting_level() method
            $short_indent = "\n" . str_repeat($indent, $level - 1);
            $newline_indent = $short_indent . $indent;

            if (count($args) === 1) {
                $arg = $args[0];
                if ($arg->positional()) {
                    $arg->set_value(
                        $newline_indent . trim($arg->value(), $WS) . $short_indent
                    );
                } else {
                    $arg->set_name($newline_indent . ltrim($arg->name(), $WS));
                    $arg->set_value(rtrim($arg->value(), $WS) . $short_indent);
                }
                continue;
            }

            // Special formatting for the first argument
            $arg = $args[0];
            if ($arg->positional()) {
                $arg->set_value(
                    $newline_indent . trim($arg->value(), $WS) . $newline_indent
                );
            } else {
                $arg->set_name($newline_indent . ltrim($arg->name(), $WS));
                $arg->set_value(rtrim($arg->value(), $WS) . $newline_indent);
            }

            // Formatting the middle arguments
            foreach (array_slice($args, 1, -1) as $arg) { // From second to second-to-last
                if ($arg->positional()) {
                    $arg->set_value(' ' . trim($arg->value(), $WS) . $newline_indent);
                } else {
                    $arg->set_name(' ' . ltrim($arg->name(), $WS));
                    $arg->set_value(rtrim($arg->value(), $WS) . $newline_indent);
                }
            }

            // Special formatting for the last argument
            $arg = end($args); // Last element in the original array
            if ($arg->positional()) {
                $arg->set_value(' ' . trim($arg->value(), $WS) . $short_indent);
            } else {
                $arg->set_name(' ' . ltrim($arg->name(), $WS));
                $arg->set_value(rtrim($arg->value(), $WS) . $short_indent);
            }
        }

        return $parsed->string(); // Return the formatted string
    }

    /**
     * Return a list of parameter objects.
     *
     * @return array<Parameter>
     */
    public function parameters(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $parameters = [];
        foreach ($this->_subspans('Parameter') as $span) {
            $parameters[] = new Parameter($lststr, $type_to_spans, $span, 'Parameter');
        }
        return $parameters;
    }

    /**
     * Return a list of parser function objects.
     *
     * @return array<ParserFunction>
     */
    public function parser_functions(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $parser_functions = [];
        foreach ($this->_subspans('ParserFunction') as $span) {
            $parser_functions[] = new ParserFunction($lststr, $type_to_spans, $span, 'ParserFunction');
        }
        return $parser_functions;
    }

    /**
     * Return a list of templates as template objects.
     *
     * @return array<Template>
     */
    public function templates(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $templates = [];
        foreach ($this->_subspans('Template') as $span) {
            $templates[] = new Template($lststr, $type_to_spans, $span, 'Template');
        }
        return $templates;
    }

    /**
     * Return a list of wikilink objects.
     *
     * @return array<WikiLink>
     */
    public function wikilinks(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $wikilinks = [];
        foreach ($this->_subspans('WikiLink') as $span) {
            $wikilinks[] = new WikiLink($lststr, $type_to_spans, $span, 'WikiLink');
        }
        return $wikilinks;
    }

    /**
     * Return a list of comment objects.
     *
     * @return array<Comment>
     */
    public function comments(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $comments = [];
        foreach ($this->_subspans('Comment') as $span) {
            $comments[] = new Comment($lststr, $type_to_spans, $span, 'Comment');
        }
        return $comments;
    }

    /**
     * Returns a byte array with non-markup-apostrophes removed.
     * This logic is complex and closely mimics MediaWiki's internal handling of bold/italic.
     *
     * @return string (mimicking bytearray)
     */
    private function _balanced_quotes_shadow(): string
    {
        global $WS; // Access global WS constant
        global $substitute_apostrophes; // From _wikitext_utils.php

        $bold_starts = [];
        $odd_italics = false;
        $odd_bold_italics = false;

        $process_line = function (string $line) use (&$bold_starts, &$odd_italics, &$odd_bold_italics): string {
            // Restore context from previous line if odd counts
            if ($odd_italics && ((count($bold_starts) + ($odd_bold_italics ? 1 : 0)) % 2)) {
                // One of the bold marks needs to be interpreted as italic
                $first_multi_letter_word_s = null;
                $first_space_s = null;

                foreach ($bold_starts as $s) {
                    if (isset($line[$s - 1]) && ord($line[$s - 1]) === 32) { // space
                        if ($first_space_s === null) {
                            $first_space_s = $s;
                        }
                        continue;
                    }
                    if (isset($line[$s - 2]) && ord($line[$s - 2]) === 32) { // space (indicates a single-letter word before bold)
                        $line = substr_replace($line, ' ', $s, 1); // Replace char at $s with space
                        goto end_process_bold_fix; // Break from nested loops (simulating Python's break with goto)
                    }
                    if ($first_multi_letter_word_s === null) {
                        $first_multi_letter_word_s = $s;
                        continue;
                    }
                }
                // No single-letter word detected before bold
                if ($first_multi_letter_word_s !== null) {
                    $line = substr_replace($line, '_', $first_multi_letter_word_s, 1);
                } elseif ($first_space_s !== null) {
                    $line = substr_replace($line, '_', $first_space_s, 1);
                }
            }
            end_process_bold_fix:

            // Reset state for the next line
            $bold_starts = [];
            $odd_italics = false;
            $odd_bold_italics = false;
            return $line;
        };

        $process_apostrophes = function (array $m) use (&$bold_starts, &$odd_italics, &$odd_bold_italics): string {
            // $m is a match object array from the regex.
            // m.starts(1) gets the start offsets of all captured groups with index 1 (the apostrophe sequence).
            // For `substitute_apostrophes` pattern, group 1 is `('\0*+)`.
            // So `m.starts(1)` gives an array of offsets for each apostrophe sequence.
            $starts = $m['spans'](1); // Assuming 'spans' function for repeating group.
            $starts_offsets = array_column($starts, 0); // Get just the start offsets.

            $n = count($starts_offsets);

            if ($n === 2) { // Italic (two apostrophes: '')
                $odd_italics = !$odd_italics;
                return $m['group'](0); // Return original matched string
            }
            if ($n === 3) { // Bold (three apostrophes: ''')
                $bold_starts[] = $starts_offsets[0]; // Append first apostrophe's start offset
                return $m['group'](0);
            }
            if ($n === 5) { // Bold and Italic (five apostrophes: ''''')
                $odd_bold_italics = !$odd_bold_italics;
                $odd_italics = !$odd_italics;
                return $m['group'](0);
            }
            if ($n === 4) { // Four apostrophes: '''' -> hide the first one.
                $s = $starts_offsets[1]; // Start of the second apostrophe sequence
                $bold_starts[] = $s;
                // Replace characters from starts[0] to starts[1]-1 with '_'
                return str_repeat('_', $s - $starts_offsets[0]) . substr($m['group'](0), $s - $starts_offsets[0]);
            }
            // More than 5 apostrophes -> hide the prior ones.
            $odd_bold_italics = !$odd_bold_italics;
            $odd_italics = !$odd_italics;
            $s = $starts_offsets[count($starts_offsets) - 5]; // Start of the fifth apostrophe from the end
            return str_repeat('_', $s - $starts_offsets[0]) . substr($m['group'](0), $s - $starts_offsets[0]);
        };

        // Get `_shadow` from current WikiText object.
        // It's a string in PHP, mimicking Python's `bytearray`.
        $shadow = $this->_shadow();

        $lines = explode("\n", $shadow); // Split into lines.
        $processed_lines = [];

        foreach ($lines as $line) {
            // Apply `substitute_apostrophes` which uses a callback.
            // `substitute_apostrophes` is a function in _wikitext_utils.php that takes a callback.
            $processed_line = $substitute_apostrophes($process_apostrophes, $line);
            $processed_lines[] = $process_line($processed_line); // Apply `process_line` logic to each line.
        }

        return implode("\n", $processed_lines);
    }


    /**
     * Helper for `get_bolds_and_italics` to recurse into nested elements.
     *
     * @param array<Bold|Italic> $result Reference to the list where found objects are appended.
     * @param class-string<Bold>|class-string<Italic>|null $filter_cls
     */
    private function _bolds_italics_recurse(array &$result, ?string $filter_cls): void
    {
        // Recursively find bolds/italics in nested elements.
        $props = ['templates', 'parser_functions', 'parameters', 'wikilinks'];
        foreach ($props as $prop) {
            // `getattr(self, prop)` in Python.
            $nested_elements = $this->{$prop}(); // Call the property as a method.
            foreach ($nested_elements as $e) {
                // Recursive call, but with `recursive=False` to avoid infinite recursion.
                $found_bolds_italics = $e->get_bolds_and_italics(filter_cls: $filter_cls, recursive: false);
                foreach ($found_bolds_italics as $item) {
                    $result[] = $item;
                }
            }
        }

        $extension_tags = $this->_extension_tags(); // Call as method
        if (empty($extension_tags)) {
            return;
        }

        // Collect existing result spans to avoid duplicates if extension tags contain already processed items.
        // `{*i._span_data[:2] for i in result}`: create a set of (start, end) tuples.
        $result_spans_set = [];
        foreach ($result as $item) {
            list($s, $e) = $item->span(); // Use span() method
            $result_spans_set[serialize([$s, $e])] = true; // Use serialized array as key for set-like behavior
        }

        foreach ($extension_tags as $e) {
            $found_in_ext_tag = $e->get_bolds_and_italics(filter_cls: $filter_cls, recursive: false);
            foreach ($found_in_ext_tag as $item) {
                list($s, $e) = $item->span(); // Use span() method
                if (!isset($result_spans_set[serialize([$s, $e])])) {
                    $result[] = $item;
                    $result_spans_set[serialize([$s, $e])] = true;
                }
            }
        }
    }

    /**
     * Return a list of bold and italic objects in self.
     *
     * @param bool $recursive If true, also look inside templates, parser functions, extension tags, etc.
     * @param class-string<Bold>|class-string<Italic>|null $filter_cls Only return this type.
     * @return array<Bold|Italic>
     */
    public function get_bolds_and_italics(
        bool $recursive = true,
        ?string $filter_cls = null
    ): array {
        $result = [];
        $lststr = $this->_lststr;
        $s_offset_global = $this->_span_data[0]; // Global start offset of current object
        $type_to_spans = $this->_type_to_spans;

        $tts_setdefault = function (string $key, array $default_value) use (&$type_to_spans): array {
            if (!isset($type_to_spans[$key])) {
                $type_to_spans[$key] = $default_value;
            }
            return $type_to_spans[$key];
        };

        $balanced_shadow = $this->_balanced_quotes_shadow(); // Call as method
        list($rs, $re) = $this->_content_span(); // Relative content span within current object.

        // BOLD processing
        $bold_matches = []; // Store bold matches for later italic processing
        if ($filter_cls === null || $filter_cls === Bold::class) {
            $bold_spans = &$tts_setdefault('Bold', []);
            $get_old_bold_span = function (array $span_tuple) use ($bold_spans): ?array {
                foreach ($bold_spans as $span_item) {
                    if ($span_item[0] === $span_tuple[0] && $span_item[1] === $span_tuple[1]) {
                        return $span_item;
                    }
                }
                return null;
            };

            // `BOLD_FINDITER(balanced_shadow, rs, re)` where `rs, re` are start/end offsets for `finditer`.
            foreach (finditer_php(BOLD_FINDITER(), $balanced_shadow, flags: 0, offset: $rs, length: $re - $rs) as $m) {
                list($ms, $me) = $m['span'](); // Match span relative to $balanced_shadow.
                $b_global = $s_offset_global + $ms;
                $e_global = $s_offset_global + $me;
                $span_tuple = [$b_global, $e_global];

                $old_span = $get_old_bold_span($span_tuple);
                if ($old_span === null) {
                    $span_content = substr($balanced_shadow, $ms, $me - $ms);
                    $span = [$b_global, $e_global, null, $span_content];
                    insort_right($bold_spans, $span, fn($item) => $item[0]); // Sort by start offset
                } else {
                    $span = $old_span;
                }
                $result[] = new Bold($lststr, $type_to_spans, $span, 'Bold');
                $bold_matches[] = $m; // Store this match for italic processing
            }

            if ($recursive) {
                $this->_bolds_italics_recurse($result, $filter_cls);
                // Sort only if this is the final result for Bold specifically.
                if ($filter_cls === Bold::class) {
                    usort($result, fn($a, $b) => $a->span()[0] <=> $b->span()[0]); // Sort by span start.
                    return $result;
                }
            } elseif ($filter_cls === Bold::class) {
                return $result; // If not recursive and only Bolds, return directly.
            }
        } else { // filter_cls is Italic, so we still need bold matches to remove their tokens.
            $bold_matches = []; // Re-initialize for this case
            // `BOLD_FINDITER(balanced_shadow, rs, re)`
            foreach (finditer_php(BOLD_FINDITER(), $balanced_shadow, flags: 0, offset: $rs, length: $re - $rs) as $m) {
                $bold_matches[] = $m;
            }
        }

        // --- ITALIC processing ---
        // Remove bold tokens from `balanced_shadow` before searching for italics.
        // This is done on the `balanced_shadow` *copy* that `_balanced_quotes_shadow` returned.
        // It's crucial that `balanced_shadow` is a mutable copy.
        $mutable_balanced_shadow = $balanced_shadow; // PHP string is immutable, so make a copy to modify.

        foreach ($bold_matches as $m) {
            list($ms, $me) = $m['span']();
            list($cs, $ce) = $m['span'](1); // Content span for bold (group 1)

            // Replace opening `'''` with `_`s
            $mutable_balanced_shadow = substr_replace($mutable_balanced_shadow, str_repeat('_', $cs - $ms), $ms, $cs - $ms);
            // Replace closing `'''` with `_`s
            $mutable_balanced_shadow = substr_replace($mutable_balanced_shadow, str_repeat('_', $me - $ce), $ce, $me - $ce);
        }

        if ($filter_cls === null || $filter_cls === Italic::class) {
            $italic_spans = &$tts_setdefault('Italic', []);
            $get_old_italic_span = function (array $span_tuple) use ($italic_spans): ?array {
                foreach ($italic_spans as $span_item) {
                    if ($span_item[0] === $span_tuple[0] && $span_item[1] === $span_tuple[1]) {
                        return $span_item;
                    }
                }
                return null;
            };

            // `ITALIC_FINDITER(mutable_balanced_shadow, rs, re)`
            foreach (finditer_php(ITALIC_FINDITER(), $mutable_balanced_shadow, flags: 0, offset: $rs, length: $re - $rs) as $m) {
                list($ms, $me) = $m['span'](); // Match span relative to `mutable_balanced_shadow`
                $b_global = $s_offset_global + $ms;
                $e_global = $s_offset_global + $me;
                $span_tuple = [$b_global, $e_global];

                $old_span = $get_old_italic_span($span_tuple);
                if ($old_span === null) {
                    $span_content = substr($mutable_balanced_shadow, $ms, $me - $ms);
                    $span = [$b_global, $e_global, null, $span_content];
                    insort_right($italic_spans, $span, fn($item) => $item[0]);
                } else {
                    $span = $old_span;
                }

                // `me != m.end(1)` checks if the matched italic has an end token.
                // If it doesn't, `m.end(1)` (end of content group) will be same as `me` (end of full match).
                $has_end_token = ($me !== $m['end'](1));
                $result[] = new Italic($lststr, $type_to_spans, $span, 'Italic', $has_end_token);
            }
        }

        if ($recursive && $filter_cls === Italic::class) {
            $this->_bolds_italics_recurse($result, $filter_cls);
            usort($result, fn($a, $b) => $a->span()[0] <=> $b->span()[0]);
            return $result;
        }

        if ($filter_cls === null) { // If both bold and italic were requested
            usort($result, fn($a, $b) => $a->span()[0] <=> $b->span()[0]);
        }
        return $result;
    }

    /**
     * Return bold parts of self.
     *
     * @param bool $recursive If true, also look inside templates, parser functions, etc.
     * @return array<Bold>
     */
    public function get_bolds(bool $recursive = true): array
    {
        // The overload in Python means it returns a specific type list.
        // We ensure type safety by returning `array<Bold>` here.
        return $this->get_bolds_and_italics(filter_cls: Bold::class, recursive: $recursive);
    }

    /**
     * Return italic parts of self.
     *
     * @param bool $recursive If true, also look inside templates, parser functions, etc.
     * @return array<Italic>
     */
    public function get_italics(bool $recursive = true): array
    {
        return $this->get_bolds_and_italics(filter_cls: Italic::class, recursive: $recursive);
    }

    /**
     * Replace the invalid chars of SPAN_PARSER_TYPES with b'_'.
     *
     * For comments, all characters are replaced, but for ('Template',
     * 'ParserFunction', 'Parameter') only invalid characters are replaced.
     *
     * @return string (mimicking bytearray)
     */
    protected function _ext_link_shadow(): string
    {
        global $INVALID_EL_TPP_CHRS_SUB; // From _wikitext_utils.php

        list($ss, $se, $_, $_) = $this->_span_data; // Current object's global span
        // Get the string slice of the current object.
        $byte_array_segment = substr($this->_lststr[0], $ss, $se - $ss);

        // This is a mutable copy for modification.
        $mutable_byte_array = $byte_array_segment;

        // Replace content of Comment spans with '_'
        foreach ($this->_subspans('Comment') as list($s, $e, $_, $_)) {
            // Adjust offsets to be relative to `mutable_byte_array` segment.
            $relative_s = $s - $ss;
            $relative_e = $e - $ss;
            $mutable_byte_array = substr_replace($mutable_byte_array, str_repeat('_', $relative_e - $relative_s), $relative_s, $relative_e - $relative_s);
        }

        // Replace content of WikiLink spans with ' '
        foreach ($this->_subspans('WikiLink') as list($s, $e, $_, $_)) {
            $relative_s = $s - $ss;
            $relative_e = $e - $ss;
            $mutable_byte_array = substr_replace($mutable_byte_array, str_repeat(' ', $relative_e - $relative_s), $relative_s, $relative_e - $relative_s);
        }

        // For Template, ParserFunction, Parameter, replace invalid characters using `INVALID_EL_TPP_CHRS_SUB`.
        // `INVALID_EL_TPP_CHRS_SUB(b' ', byte_array[s:e])` applies a regex substitution to a slice.
        foreach (['Template', 'ParserFunction', 'Parameter'] as $type_name) {
            foreach ($this->_subspans($type_name) as list($s, $e, $_, $_)) {
                $relative_s = $s - $ss;
                $relative_e = $e - $ss;
                $segment_to_sub = substr($mutable_byte_array, $relative_s, $relative_e - $relative_s);
                // `INVALID_EL_TPP_CHRS_SUB` expects a pattern and replacement, and string.
                // Assuming INVALID_EL_TPP_CHRS_SUB is a function that performs the regex replace.
                $replaced_segment = INVALID_EL_TPP_CHRS_SUB(' ', $segment_to_sub); // Replace invalid chars with space.
                $mutable_byte_array = substr_replace($mutable_byte_array, $replaced_segment, $relative_s, $relative_e - $relative_s);
            }
        }
        return $mutable_byte_array;
    }

    /**
     * Return a list of found external link objects.
     *
     * @return array<ExternalLink>
     */
    public function external_links(): array
    {
        $external_links = [];
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $ss = $this->_span_data[0]; // Global start offset of current WikiText object.

        // Get or set 'ExternalLink' span list in type_to_spans.
        if (!isset($type_to_spans['ExternalLink'])) {
            $type_to_spans['ExternalLink'] = [];
        }
        $spans = &$type_to_spans['ExternalLink'];

        // Create a map for quick lookup for existing spans.
        $span_tuple_to_span_get = [];
        foreach ($spans as $s_item) {
            $span_tuple_to_span_get[serialize([$s_item[0], $s_item[1]])] = $s_item;
        }

        $el_shadow = $this->_ext_link_shadow(); // Get the special shadow for external link parsing.

        $extract = function (int $start_offset, ?int $end_offset = null) use (
            &$external_links,
            $lststr,
            $type_to_spans,
            $ss,
            &$spans,
            $span_tuple_to_span_get,
            $el_shadow
        ): void {
            // `EXTERNAL_LINK_FINDITER` needs to be used with `finditer_php`.
            // The `offset` and `length` parameters for `finditer_php` need to be correct for $el_shadow.
            foreach (finditer_php(EXTERNAL_LINK_FINDITER(), $el_shadow, flags: 0, offset: $start_offset, length: ($end_offset === null) ? null : ($end_offset - $start_offset)) as $m) {
                list($ms, $me) = $m['span'](); // Match span relative to $el_shadow.
                $b_global = $ss + $ms;
                $e_global = $ss + $me;
                $span_tuple = [$b_global, $e_global];

                $old_span = $span_tuple_to_span_get[serialize($span_tuple)] ?? null;
                if ($old_span === null) {
                    $span_content = substr($el_shadow, $ms, $me - $ms);
                    $span = [$b_global, $e_global, null, $span_content];
                    insort_right($spans, $span, fn($item) => $item[0]);
                } else {
                    $span = $old_span;
                }
                $external_links[] = new ExternalLink($lststr, $type_to_spans, $span, 'ExternalLink');
            }
        };

        // Extract from extension tags first
        foreach ($this->_subspans('ExtensionTag') as list($s, $e, $_, $_)) {
            $extract($s - $ss, $e - $ss); // Pass relative offsets for extract function
            // After extracting from a tag, mask it in `el_shadow` to prevent re-matching.
            $el_shadow = substr_replace($el_shadow, str_repeat(' ', ($e - $s)), $s - $ss, $e - $ss);
        }

        // Extract from the rest of the shadow.
        $extract(0, null); // Pass absolute offsets for extract function
        return $external_links;
    }

    /**
     * Converts a list of section spans to Section objects.
     *
     * @param array<array<int>> $section_spans List of [start, end] span tuples relative to `shadow`.
     * @param string $shadow The shadow string used for parsing (e.g., `_shadow()` result).
     * @return array<Section>
     */
    private function _section_spans_to_sections(array $section_spans, string $shadow): array
    {
        $type_to_spans = $this->_type_to_spans;
        $sections = [];
        $ss = $this->_span_data[0]; // Global start offset of current WikiText object.

        // Get or set 'Section' span list.
        if (!isset($type_to_spans['Section'])) {
            $type_to_spans['Section'] = [];
        }
        $type_spans = &$type_to_spans['Section'];

        // Create a map for quick lookup for existing spans.
        $span_tuple_to_span = [];
        foreach ($type_spans as $s_item) {
            $span_tuple_to_span[serialize([$s_item[0], $s_item[1]])] = $s_item;
        }

        $lststr = $this->_lststr;

        foreach ($section_spans as list($ms, $me)) { // ms, me are relative to $shadow
            $s_global = $ss + $ms;
            $e_global = $ss + $me;
            $span_tuple = [$s_global, $e_global];

            $old_span = $span_tuple_to_span[serialize($span_tuple)] ?? null;
            if ($old_span === null) {
                $span_content = substr($shadow, $ms, $me - $ms);
                $span = [$s_global, $e_global, null, $span_content];
                insort_right($type_spans, $span, fn($item) => $item[0]);
            } else {
                $span = $old_span;
            }
            $sections[] = new Section($lststr, $type_to_spans, $span, 'Section');
        }
        return $sections;
    }

    /**
     * Return self.get_sections(include_subsections=True).
     *
     * @return array<Section>
     */
    public function sections(): array
    {
        return $this->get_sections();
    }

    /**
     * Return a list of sections in current wikitext.
     *
     * @param mixed ...$args Deprecated positional arguments for `include_subsections` and `level`.
     * @param bool $include_subsections If true, include the text of subsections in each Section object.
     * @param int|null $level Only return sections where section.level == level. Return all levels if null (default).
     * @param bool $top_levels_only Only return sections that are not subsections of other sections.
     * @return array<Section>
     */
    public function get_sections(
        ...$args,
        bool $include_subsections = true,
        ?int $level = null,
        bool $top_levels_only = false
    ): array {
        if (!empty($args)) {
            warn('calling get_sections with positional arguments is deprecated', \E_USER_DEPRECATED);
            if (count($args) === 1) {
                $include_subsections = $args[0];
            } else {
                list($include_subsections, $level) = $args;
            }
        }

        $shadow = $this->_shadow(); // Call as method.
        $full_match = null;
        $section_spans = [];
        $levels = [];

        if ($top_levels_only) {
            // `assert` for development-time checks. In production, consider graceful handling or exceptions.
            if ($level !== null) {
                throw new \AssertionError('Level cannot be specified with top_levels_only=True.');
            }
            if (!$include_subsections) {
                throw new \AssertionError('`include_subsections` must be True with top_levels_only=True.');
            }
            $full_match = SECTIONS_TOP_LEVELS_ONLY($shadow); // Call from _wikitext_utils.php
            if ($full_match === null) {
                return [];
            }
            $section_spans = $full_match['spans']('section');
            return $this->_section_spans_to_sections($section_spans, $shadow);
        }

        $full_match = SECTIONS_FULLMATCH($shadow); // Call from _wikitext_utils.php
        if ($full_match === null) {
            return [];
        }

        $section_spans = $full_match['spans']('section');
        $levels = array_map('strlen', $full_match['captures']('equals')); // Length of 'equals' capture group.

        if ($include_subsections) {
            $z = []; // Combined array of [span, level] tuples.
            foreach ($section_spans as $idx => $span) {
                $z[] = [$span, $levels[$idx]];
            }

            // Iterate from the second section.
            // Python's `islice(z, 1, None)` skips the first element.
            for ($pi = 1; $pi < count($z); $pi++) {
                list($ps_span, $pl) = $z[$pi]; // Current parent candidate section's span and level.
                list($ps, $pe) = $ps_span;

                // Iterate through subsequent sections.
                for ($si = $pi + 1; $si < count($z); $si++) {
                    list($ss_span, $sl) = $z[$si]; // Current subsection candidate's span and level.
                    list($ss, $se) = $ss_span;

                    if ($sl > $pl) { // If subsection level is deeper than parent level
                        // Extend the parent's span to include this subsection.
                        $section_spans[$pi] = [$ps, $se]; // Update the span in the original section_spans array
                        $ps = $section_spans[$pi][0]; // Update ps for next iteration if needed
                        $pe = $section_spans[$pi][1]; // Update pe for next iteration if needed
                    } else {
                        break; // Subsection is not deeper, so it's not part of this parent.
                    }
                }
            }
        }

        if ($level !== null) {
            $filtered_section_spans = [];
            foreach ($section_spans as $idx => $span) {
                if ($levels[$idx] === $level) {
                    $filtered_section_spans[] = $span;
                }
            }
            $section_spans = $filtered_section_spans;
        }

        return $this->_section_spans_to_sections($section_spans, $shadow);
    }

    /**
     * Return a list of all tables.
     *
     * @return array<Table>
     */
    public function tables(): array
    {
        return $this->get_tables(true);
    }

    /**
     * Return tables. Include nested tables if `recursive` is `True`.
     *
     * @param bool $recursive
     * @return array<Table>
     */
    public function get_tables(bool $recursive = false): array
    {
        $type_to_spans = $this->_type_to_spans;
        $lststr = $this->_lststr;
        $ss_global = $this->_span_data[0]; // Global start offset of current WikiText object.

        // Get a mutable copy of the shadow, as tables parsing modifies it.
        $shadow_copy = $this->_shadow(); // Call as method to get the base shadow.
        $shadow_copy_initial_content = $shadow_copy; // Keep initial state for content extraction later.

        // Ensure 'Table' span list exists.
        if (!isset($type_to_spans['Table'])) {
            $type_to_spans['Table'] = [];
        }
        $spans = &$type_to_spans['Table'];

        $span_tuple_to_span_get = [];
        foreach ($spans as $s_item) {
            $span_tuple_to_span_get[serialize([$s_item[0], $s_item[1]])] = $s_item;
        }

        $return_spans = []; // Spans to be returned as Table objects.

        // Helper closure to extract tables from a given shadow segment.
        $extract_tables_from_shadow = function () use (
            &$shadow_copy, // This shadow_copy will be modified.
            &$ss_global,
            &$spans,
            $span_tuple_to_span_get,
            &$return_spans,
            $shadow_copy_initial_content // Reference original content for data.
        ): void {
            // TABLE_FINDITER is a global function in _wikitext_utils.php that returns a regex pattern.
            // Use `finditer_php` for iteration.

            // `m = True` and `while m:` is Python's way to force at least one loop iteration.
            // For PHP, we'll just run finditer and loop.
            // `skip_self_span` is a flag to `TABLE_FINDITER` in Python, indicating if the current span should be skipped.
            // This flag is related to recursing into nested tables of `Table` object itself.
            // In WikiText's `get_tables`, `skip_self_span` is true if `self._type == 'Table'`, otherwise false.
            // For WikiText, it's false, so all tables are found.

            foreach (finditer_php(TABLE_FINDITER(), $shadow_copy, flags: 0) as $m) {
                list($ms, $me) = $m['span'](); // Match span relative to `shadow_copy`.

                $s_global = $ss_global + $ms; // Global start offset.
                $e_global = $ss_global + $me; // Global end offset.
                $span_tuple = [$s_global, $e_global];

                $old_span = $span_tuple_to_span_get[serialize($span_tuple)] ?? null;
                if ($old_span === null) {
                    $span_content = substr($shadow_copy_initial_content, $ms, $me - $ms); // Content from original full shadow.
                    $span = [$s_global, $e_global, null, $span_content];
                    insort_right($spans, $span, fn($item) => $item[0]); // Add to global spans list.
                    $return_spans[] = $span; // Add to list for returning.
                } else {
                    $return_spans[] = $old_span; // Use existing span reference.
                }

                // Mask the found table in `shadow_copy` to prevent re-matching in subsequent iterations.
                $shadow_copy = substr_replace($shadow_copy, str_repeat('_', $me - $ms), $ms, $me - $ms);
            }
        };

        // First, extract tables from the main `shadow_copy`.
        $extract_tables_from_shadow();

        // Then, extract tables from content of parsable extension tags.
        // This is a recursive step.
        foreach ($this->_extension_tags() as $tag) { // Call _extension_tags() method
            // Check if this is a parsable extension tag (e.g. <poem>, <gallery>)
            if (in_array($tag->name(), _get_parsable_tag_extensions())) {
                // Get the shadow of the tag's content.
                // Python's `tag._shadow[:]` makes a copy of tag's shadow.
                // We need the tag's actual content string, not its masked shadow.
                // `tag.parsed_contents()` gives a SubWikiText object of contents.
                // Its `string()` will provide the content, and its `_shadow()` will give the right shadow for `TABLE_FINDITER`.
                $tag_content_obj = $tag->parsed_contents();
                $shadow_copy = $tag_content_obj->_shadow(); // Get the shadow of the tag's content
                $shadow_copy_initial_content = $shadow_copy; // For content extraction.

                // Adjust global start offset for tables found within this tag.
                // `ss = tag._span_data[0]` means global start of the tag.
                $ss_global_for_tag_content = $tag->span()[0] + $tag->_content_span()[0];
                $original_ss_global = $ss_global; // Save original for restoring.
                $ss_global = $ss_global_for_tag_content; // Update for closure.

                $extract_tables_from_shadow(); // Recurse for tables inside this tag.

                $ss_global = $original_ss_global; // Restore global start offset.
            }
        }

        // Sort all collected spans (global offsets)
        usort($return_spans, fn($a, $b) => $a[0] <=> $b[0]);
        // Sort main spans list as well.
        usort($spans, fn($a, $b) => $a[0] <=> $b[0]);

        if (!$recursive) {
            // Filter to return only outermost tables if not recursive.
            // `_outer_spans` is a generator.
            $outer_spans_filtered = [];
            foreach (\Wtp\Parser\_wikitext\_outer_spans($return_spans) as $span_item) {
                $outer_spans_filtered[] = $span_item;
            }
            $return_spans = $outer_spans_filtered;
        }

        $tables = [];
        foreach ($return_spans as $sp) {
            $tables[] = new Table($lststr, $type_to_spans, $sp, 'Table');
        }
        return $tables;
    }

    /**
     * Return appropriate shadow and its offset to be used by `lists`.
     *
     * @return array{string, int} [shadow_content, global_start_offset]
     */
    protected function _lists_shadow_ss(): array
    {
        return [$this->_shadow(), $this->_span_data[0]];
    }

    /**
     * Return a list of WikiList objects.
     *
     * @param string|iterable<string> $pattern The starting pattern for list items.
     * @return array<WikiList>
     */
    public function get_lists(string|iterable $pattern = ['\#', '\*', '[:;]']): array
    {
        // Ensure pattern is an array for iteration.
        if (is_string($pattern)) {
            $patterns = [$pattern];
        } else {
            $patterns = is_array($pattern) ? $pattern : iterator_to_array($pattern);
        }

        $lists = [];
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;

        // Ensure 'WikiList' span list exists.
        if (!isset($type_to_spans['WikiList'])) {
            $type_to_spans['WikiList'] = [];
        }
        $spans = &$type_to_spans['WikiList'];

        // Create a map for quick lookup for existing spans.
        $span_tuple_to_span_get = [];
        foreach ($spans as $s_item) {
            $span_tuple_to_span_get[serialize([$s_item[0], $s_item[1]])] = $s_item;
        }

        list($shadow, $ss) = $this->_lists_shadow_ss(); // Call as method.

        // Handle external links replacement for definition list parsing.
        // `any(':' in pattern for pattern in patterns)`
        $has_colon_pattern = false;
        foreach ($patterns as $p) {
            if (str_contains($p, ':')) {
                $has_colon_pattern = true;
                break;
            }
        }

        if ($has_colon_pattern) {
            // Replace external links with underscores in the shadow if colon is in pattern.
            // `EXTERNAL_LINK_FINDITER(shadow)`
            $mutable_shadow = $shadow; // Make a mutable copy
            foreach (finditer_php(EXTERNAL_LINK_FINDITER(), $mutable_shadow) as $m) {
                list($s_match, $e_match) = $m['span']();
                $mutable_shadow = substr_replace($mutable_shadow, str_repeat('_', $e_match - $s_match), $s_match, $e_match - $s_match);
            }
            $shadow = $mutable_shadow; // Update shadow to the modified version.
        }

        // Iterate through each pattern to find lists.
        foreach ($patterns as $pattern_str) {
            // `LIST_PATTERN_FORMAT.replace(b'{pattern}', pattern.encode(), 1)`
            // This replaces `{pattern}` placeholder in the format string.
            $list_pattern = str_replace('{pattern}', preg_quote($pattern_str, '/'), LIST_PATTERN_FORMAT);

            // `finditer(..., shadow, MULTILINE)`
            foreach (finditer_php(rc($list_pattern), $shadow, flags: MULTILINE) as $m) {
                list($ms, $me) = $m['span'](); // Match span relative to $shadow.
                $s_global = $ss + $ms;
                $e_global = $ss + $me;
                $span_tuple = [$s_global, $e_global];

                $old_span = $span_tuple_to_span_get[serialize($span_tuple)] ?? null;
                if ($old_span === null) {
                    $span_content = substr($shadow, $ms, $me - $ms);
                    $span = [$s_global, $e_global, $m, $span_content]; // Store match object in span
                    insort_right($spans, $span, fn($item) => $item[0]);
                } else {
                    $span = $old_span;
                }

                $lists[] = new WikiList($lststr, $pattern_str, $m, $type_to_spans, $span, 'WikiList');
            }
        }

        // Sort lists by their span data.
        usort($lists, fn($a, $b) => $a->span()[0] <=> $b->span()[0]);
        return $lists;
    }

    /**
     * Returns a list of extension tag objects.
     *
     * @return array<Tag>
     */
    protected function _extension_tags(): array
    {
        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $extension_tags = [];
        foreach ($this->_subspans('ExtensionTag') as $span) {
            $extension_tags[] = new Tag($lststr, $type_to_spans, $span, 'ExtensionTag');
        }
        return $extension_tags;
    }

    /**
     * Return all tags with the given name.
     *
     * @param string|null $name Optional tag name to filter by.
     * @return array<Tag>
     */
    public function get_tags(?string $name = null): array
    {
        global $NAME_CAPTURING_HTML_START_TAG_FINDITER; // From _wikitext_utils.php
        global $START_TAG_PATTERN, $END_TAG_PATTERN; // From _spans.php
        global $WARN; // Assuming a global warning function

        $lststr = $this->_lststr;
        $type_to_spans = $this->_type_to_spans;
        $tags = [];

        // Handle extension tags first, if name is provided and is an extension tag.
        if ($name !== null) {
            // `_tag_extensions` is now a function.
            if (in_array(mb_strtolower($name), _tag_extensions())) {
                $string = $lststr[0]; // Full original string.
                // Filter already parsed ExtensionTag spans if they match the name.
                foreach ($type_to_spans['ExtensionTag'] ?? [] as $span) {
                    // `match(r'<' + name + r'\b', string, pos=span[0])`
                    // This checks if the tag starts exactly at the span's start and has the correct name.
                    $pattern_for_name_check = rc('<' . preg_quote($name, '/') . '\\b');
                    if (match_at_pos($pattern_for_name_check, $string, $span[0]) !== null) {
                        $tags[] = new Tag($lststr, $type_to_spans, $span, 'ExtensionTag');
                    }
                }
                return $tags; // Return filtered extension tags if name was specified.
            }
        } else {
            // If no name, add all already-parsed extension tags.
            $tags = $this->_extension_tags();
        }

        // Get the left-most start tag, match it to right-most end tag.
        $ss_global = $this->_span_data[0]; // Global start offset.
        $byte_array_copy = $this->string(); // Mutable copy of current object's string.

        // Determine which start tag iterator to use.
        $start_matches_generator = null;
        if ($name !== null) {
            // Build a specific pattern for the named HTML tag.
            $start_pattern_for_name = str_replace('{name}', '(?P<name>' . preg_quote($name, '/') . ')', START_TAG_PATTERN);
            $start_matches_generator = finditer_php(rc($start_pattern_for_name), $byte_array_copy);
        } else {
            // Use the general HTML start tag iterator.
            $start_matches_generator = finditer_php(NAME_CAPTURING_HTML_START_TAG_FINDITER(), $byte_array_copy);
        }

        // Reverse the generator (consume and reverse list)
        $reversed_start_matches = array_reverse(iterator_to_array($start_matches_generator));

        // Ensure 'Tag' span list exists.
        if (!isset($type_to_spans['Tag'])) {
            $type_to_spans['Tag'] = [];
        }
        $spans = &$type_to_spans['Tag']; // Reference to the global Tag spans list.

        // Create a map for quick lookup for existing spans.
        $span_tuple_to_span_get = [];
        foreach ($spans as $s_item) {
            $span_tuple_to_span_get[serialize([$s_item[0], $s_item[1]])] = $s_item;
        }

        foreach ($reversed_start_matches as $start_match) {
            // Check for self-closing tag syntax: e.g., `<br/>`
            // `start_match[0].rstrip(b' \t\n>')[-1] == 47` checks if last non-whitespace before `>` is `/`.
            // In PHP, ord('/') is 47.
            $full_start_tag_str = $start_match['group'](0);
            $stripped_start_tag = rtrim($full_start_tag_str, " \t\n>");
            $is_self_closing = (strlen($stripped_start_tag) > 0 && ord($stripped_start_tag[strlen($stripped_start_tag) - 1]) === 47);

            if ($is_self_closing) {
                // Self-closing tag. No need to look for an end tag.
                list($ms, $me) = $start_match['span'](); // Span relative to `byte_array_copy`.
                $span_content = substr($byte_array_copy, $ms, $me - $ms);
                $span = [$ss_global + $ms, $ss_global + $me, null, $span_content]; // Store null for match obj.
            } else {
                // Look for the end-tag.
                list($sms, $sme) = $start_match['span'](); // Start tag match span.
                $end_match = null;
                $tag_name_bytes = $start_match['group']('name'); // Get captured tag name.

                if ($name !== null) {
                    // If a specific name was requested, use its pre-compiled end_search.
                    // This uses `rc(END_TAG_PATTERN.replace(b'{name}', name.encode()))`
                    $end_search_pattern = rc(str_replace('{name}', preg_quote($name, '/'), END_TAG_PATTERN));
                    $end_match = match_at_pos($end_search_pattern, $byte_array_copy, $sme);
                } else {
                    // Build end_search according to start tag name.
                    $end_search_pattern = rc(str_replace('{name}', preg_quote($tag_name_bytes, '/'), END_TAG_PATTERN));
                    $end_match = match_at_pos($end_search_pattern, $byte_array_copy, $sme);
                }

                if ($end_match !== null) {
                    list($ems, $eme) = $end_match['span'](); // End tag match span.
                    // Mask the end tag in `byte_array_copy` to prevent re-matching.
                    $byte_array_copy = substr_replace($byte_array_copy, str_repeat('_', $eme - $ems), $ems, $eme - $ems);

                    $span_content = substr($byte_array_copy, $sms, $eme - $sms);
                    $span = [$ss_global + $sms, $ss_global + $eme, null, $span_content];
                } else {
                    // Assume start-only tag (unclosed).
                    $span_content = substr($byte_array_copy, $sms, $sme - $sms);
                    $span = [$ss_global + $sms, $ss_global + $sme, null, $span_content];
                }
            }

            // Check if this span already exists in `spans`.
            $old_span = $span_tuple_to_span_get[serialize([$span[0], $span[1]])] ?? null;
            if ($old_span === null) {
                insort_right($spans, $span, fn($item) => $item[0]);
            } else {
                $span = $old_span;
            }
            $tags[] = new Tag($lststr, $type_to_spans, $span, 'Tag');
        }

        // Sort both `spans` (global list) and `tags` (local result list)
        usort($spans, fn($a, $b) => $a[0] <=> $b[0]);
        usort($tags, fn($a, $b) => $a->span()[0] <=> $b->span()[0]); // Sort by span start using attrgetter for PHP object.

        return $tags;
    }

    /**
     * Return None (The parent of the root node is None).
     *
     * @param string|null $type
     * @return WikiText|null
     */
    public function parent(?string $type = null): ?WikiText
    {
        return null; // Root node has no parent.
    }

    /**
     * Return [] (the root node has no ancestors).
     *
     * @param string|null $type
     * @return array<WikiText>
     */
    public function ancestors(?string $type = null): array
    {
        return []; // Root node has no ancestors.
    }
}
