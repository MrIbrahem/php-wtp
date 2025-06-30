<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans
use Wtp\Parser\_wikitext\SECTION_HEADING; // Assuming SECTION_HEADING is in Wtp\Parser\_wikitext
use Wtp\Node\SubWikiTextWithArgs; // Assuming SubWikiTextWithArgs is in Wtp\Node


// PHP equivalents for regex constants
const DOTALL = 's';
const MULTILINE = 'm';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()` (أو `$match['eq']`), `start()`, و `end()`.
 *
 * This global variable will store the compiled regex pattern for argument shadow matching.
 */
$ARG_SHADOW_FULLMATCH_PATTERN = rc(
    '[|:](?<pre_eq>(?:[^=]*+(?:'
    . SECTION_HEADING() // Call as function
    . '\n)?+)*+)(?:\Z|(?<eq>=)(?<post_eq>.*+))',
    MULTILINE . DOTALL
);

/**
 * Wrapper for preg_match that mimics Python's regex.Match object for group, start, and end.
 *
 * @param string $pattern The regex pattern (already 'rc' processed).
 * @param string $subject The string to search.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function match_argument_shadow(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match('/^' . $pattern . '$/' . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

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

        // Store the original subject string for cache validation
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}


/**
 * Class Argument
 *
 * Create a new Argument Object.
 * Note that in MediaWiki documentation `arguments` are (also) called
 * parameters. In this module the convention is:
 * {{{parameter}}}, {{template|argument}}.
 * See https://www.mediawiki.org/wiki/Help:Templates for more information.
 */
class Argument extends SubWikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent.
    private ?array $_shadow_match_cache;
    private SubWikiTextWithArgs $_parent; // Store parent object

    /**
     * @param string|array<string> $string
     * @param TypeToSpans|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|int|null $_type
     * @param SubWikiTextWithArgs|null $_parent
     */
    public function __construct(
        string|array $string,
        ?TypeToSpans $_typeToSpans = null,
        ?array $_span = null,
        string|int|null $_type = null,
        ?SubWikiTextWithArgs $_parent = null
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        // If no parent is given, assume this Argument is its own parent (e.g. for testing purposes)
        $this->_parent = $_parent ?? $this;
        $this->_shadow_match_cache = [null, null]; // Initialize cache
    }

    /**
     * Returns the match object for the argument's shadow. Caches the result.
     *
     * @return array<string, mixed> The match array for the argument shadow.
     */
    private function _shadow_match(): array
    {
        global $ARG_SHADOW_FULLMATCH_PATTERN; // Access the global compiled regex pattern

        list($cached_shadow_match, $cache_string) = $this->_shadow_match_cache;
        $self_string = strval($this); // Convert to string for comparison

        if ($cache_string === $self_string) {
            return $cached_shadow_match;
        }

        // Python's `parent._shadow[ss - ps : se - ps]`
        // This means taking a slice of the parent's shadow string.
        $ss = $this->_span_data[0]; // Start offset of current argument within the overall text
        $se = $this->_span_data[1]; // End offset of current argument within the overall text

        $parent = $this->_parent;
        $ps = $parent->_span_data[0]; // Start offset of parent within the overall text

        $relative_start = $ss - $ps;
        $relative_length = $se - $ss;

        // Ensure parent._shadow is a string for substr
        $parent_shadow_slice = substr($parent->_shadow, $relative_start, $relative_length);

        $shadow_match = match_argument_shadow($ARG_SHADOW_FULLMATCH_PATTERN, $parent_shadow_slice, MULTILINE . DOTALL);

        $this->_shadow_match_cache = [$shadow_match, $self_string];

        if ($shadow_match === null) {
             // Handle no match, implies malformed argument.
             // Return an empty array or throw an exception based on desired strictness.
            return [];
        }
        return $shadow_match;
    }

    /**
     * Argument's name.
     *
     * getter: return the position as a string, for positional arguments.
     * setter: convert it to keyword argument if positional.
     *
     * @return string
     */
    public function name(): string
    {
        $ss = $this->_span_data[0];
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return ''; // Handle no match case
        }

        // Python's `shadow_match['eq']` checks if the 'eq' group (the '=') matched.
        if ($shadow_match['group']('eq') !== null) {
            // Keyword argument: name is `pre_eq` part
            list($s, $e) = [$shadow_match['start']('pre_eq'), $shadow_match['end']('pre_eq')];
            // Access the original full string content using _lststr[0] and absolute offsets
            return substr($this->_lststr->getString(), $ss + $s, ($ss + $e) - ($ss + $s));
        }

        // Positional argument: determine position
        $position = 1;
        $parent = $this->_parent;
        $parent_start = $parent->_span_data[0];

        // Iterate through all arguments associated with the parent
        // Assuming _type_to_spans and _type are properly managed for parent's arguments
        $parent_args_spans = $this->_type_to_spans[$this->_type] ?? []; // Use $this->_type for current argument's type in spans

        foreach ($parent_args_spans as $s_arg, $e_arg, $_, $_) {
            if ($ss <= $s_arg) {
                // We've reached or passed the current argument's start
                break;
            }
            // Check if this preceding argument is a keyword argument (contains '=')
            // This requires matching the preceding argument's content in the parent's shadow
            $arg_slice_in_parent_shadow = substr($parent->_shadow, $s_arg - $parent_start, $e_arg - $s_arg);

            // Check for '=' within the arg_slice (excluding the leading '|' or ':')
            if (strpos($arg_slice_in_parent_shadow, '=') !== false) {
                // This is a keyword argument, so it doesn't affect the positional count
                continue;
            }
            $position += 1;
        }
        return strval($position);
    }

    /**
     * @param string $newname The new name for the argument.
     */
    public function set_name(string $newname): void
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return; // Handle no match case
        }

        if ($shadow_match['group']('eq') !== null) {
            // It's already a keyword argument, replace its pre_eq part (name)
            $start = 1; // After the leading '|' or ':'
            $length = strlen($shadow_match['group']('pre_eq'));
            $this->offsetSet($start, $length, $newname);
        } else {
            // It's a positional argument, convert to keyword by inserting "newname="
            $this->insert(1, $newname . '='); // After the leading '|' or ':'
        }
    }

    /**
     * True if self is positional, False if keyword.
     *
     * setter: If set to False, convert self to keyword argument.
     * Raise ValueError on trying to convert positional to keyword argument.
     *
     * @return bool
     */
    public function positional(): bool
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return false; // Or throw error, depending on expected behavior for malformed
        }
        return $shadow_match['group']('eq') === null;
    }

    /**
     * @param bool $to_positional True to convert to positional, False to convert to keyword.
     */
    public function set_positional(bool $to_positional): void
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return;
        }

        if ($shadow_match['group']('eq') !== null) {
            // Current is keyword argument
            if ($to_positional) {
                // Convert to positional: delete name and '='
                $start = 1; // After '|' or ':'
                $end_eq = $shadow_match['end']('eq'); // End of the '='
                $this->offsetUnset($start, $end_eq - $start);
            } else {
                // Already keyword and want to stay keyword
                return;
            }
        } else {
            // Current is positional argument
            if ($to_positional) {
                // Already positional and want to stay positional
                return;
            }
            // Trying to convert positional to keyword without a name
            throw new \ValueError(
                'Converting positional argument to keyword argument is not '
                . 'possible without knowing the new name. '
                . 'You can use `self.name = somename` instead.'
            );
        }
    }

    /**
     * Value of self.
     *
     * Support both keyword or positional arguments.
     * getter: Return value of self.
     * setter: Assign a new value to self.
     *
     * @return string
     */
    public function value(): string
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return '';
        }

        if ($shadow_match['group']('eq') !== null) {
            // Keyword argument: value is `post_eq` part
            return $this->__invoke($shadow_match['start']('post_eq'), null); // From start of post_eq to end
        }
        // Positional argument: value is from index 1 to end
        return $this->__invoke(1, null);
    }

    /**
     * @param string $newvalue The new value string.
     */
    public function set_value(string $newvalue): void
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return;
        }

        if ($shadow_match['group']('eq') !== null) {
            // Keyword argument: replace `post_eq` part
            $this->offsetSet($shadow_match['start']('post_eq'), null, $newvalue); // From start of post_eq to end
        } else {
            // Positional argument: replace from index 1 to end
            $this->offsetSet(1, null, $newvalue);
        }
    }

    /**
     * Returns shadow content and its global start offset for list parsing.
     *
     * @return array{string, int} [shadow_content, global_start_offset]
     */
    protected function _lists_shadow_ss(): array
    {
        $shadow_match = $this->_shadow_match(); // Call as method
        if (empty($shadow_match)) {
            return ['', 0]; // Return empty if no match
        }

        $ss = $this->_span_data[0]; // Global start offset of the argument

        if ($shadow_match['group']('eq') !== null) {
            // Keyword argument
            $post_eq_content = $shadow_match['group']('post_eq');
            $ls_post_eq = ltrim($post_eq_content); // Left strip whitespace

            return [
                $ls_post_eq,
                $ss + $shadow_match['start']('post_eq') + (strlen($post_eq_content) - strlen($ls_post_eq))
            ];
        }
        // Positional argument
        // Python's `shadow_match[0][1:]` means slice from index 1 of the full match.
        // This gets the content after the leading '|' or ':'.
        $content_after_separator = substr($shadow_match['group'](0), 1);
        return [$content_after_separator, $ss + 1];
    }
}
