<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use Wtp\Parser\_spans\TypeToSpans; // Assuming TypeToSpans is in Wtp\Parser\_spans

// PHP equivalents for regex constants
const DOTALL = 's';
const MULTILINE = 'm';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()` (أو `$match[1]`) و `span()`.
 * تم استخدام وظيفة مساعدة `match_with_span_and_group` أدناه لتوحيد هذا السلوك.
 *
 * This global variable will store the compiled regex pattern for bold text.
 */
const COMMENT_PATTERN = '|\Z)';
const COMMA_COMMENT = "'(?:(?:|\Z))*?)'"; // Fixed: Python's r'' string for regex. The '?' after '*' means non-greedy.
const COMMENT_COMMA = '(?:(?:|\Z))*?)' . "'"; // Fixed: Python's r'' string for regex.

$BOLD_FULLMATCH_PATTERN = rc(
    COMMA_COMMENT . COMMA_COMMENT . "'(.*?)(?:'" . COMMENT_COMMA . COMMENT_COMMA . '|$)',
    MULTILINE . DOTALL
);

$ITALIC_FULLMATCH_PATTERN = rc(
    COMMA_COMMENT . "'(.*?)(?:'" . COMMENT_COMMA . '|$)',
    DOTALL
);
$ITALIC_NOEND_FULLMATCH_PATTERN = rc(COMMA_COMMENT . "'(.*)", DOTALL);

/**
 * Wrapper for preg_match that mimics Python's regex.Match object for group and span.
 *
 * @param string $pattern The regex pattern (already 'rc' processed).
 * @param string $subject The string to search.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function match_with_span_and_group(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match($pattern . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        // Store raw matches for direct access by numeric index (Python's match[1])
        foreach ($matches as $key => $value) {
            if (is_int($key)) { // Numeric capture group
                $matchObject[$key] = $value[0]; // Matched string
                $matchObject['offset_' . $key] = $value[1]; // Offset
            }
        }

        // Mimic `match.group(group_number)`
        $matchObject['group'] = function ($group) use ($matchObject) {
            return $matchObject[$group] ?? null;
        };

        // Mimic `match.span(group_number)`
        $matchObject['span'] = function ($group) use ($matchObject) {
            if (isset($matchObject['offset_' . $group]) && isset($matchObject[$group])) {
                $start = $matchObject['offset_' . $group];
                $end = $start + strlen($matchObject[$group]);
                return [$start, $end];
            }
            return [-1, -1]; // Python returns (-1, -1) for unmatched groups
        };

        // Store the original subject string for cache validation (if any)
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}


/**
 * Class Comment
 *
 * Represents an HTML comment in wikitext (e.g., ).
 */
class Comment extends SubWikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent.

    /**
     * Return contents of this comment.
     *
     * @return string
     */
    public function contents(): string
    {
        $s = $this->string;
        if (substr($s, -3) === '-->') {
            return substr($s, 4, -3); // From index 4 to 3 chars from end
        }
        return substr($s, 4); // From index 4 to end (if no closing tag)
    }

    /**
     * Returns an empty list, as comments cannot contain other comments in this model.
     *
     * @return array<Comment>
     */
    public function comments(): array
    {
        return [];
    }
}


/**
 * Class BoldItalic
 *
 * Base class for Bold and Italic text objects.
 */
abstract class BoldItalic extends SubWikiText
{
    // __slots__ is Python-specific.

    /**
     * Abstract method to be implemented by child classes to provide the regex match.
     *
     * @return array<string, mixed> The match array.
     */
    abstract protected function _match(): array;

    /**
     * Return text value of self (without triple quotes).
     *
     * @return string
     */
    public function text(): string
    {
        $match = $this->_match(); // Call as method
        // In Python, match[1] refers to the first captured group.
        // Assuming our match_with_span_and_group provides this via 'group' method or direct index.
        return $match['group'](1) ?? ''; // Return empty string if group 1 is null
    }

    /**
     * @param string $s The new text string.
     */
    public function set_text(string $s): void
    {
        $match = $this->_match(); // Call as method
        list($b, $e) = $match['span'](1); // Get span of group 1
        $this->offsetSet($b, $e - $b, $s); // Replace content
    }

    /**
     * @return array<int> [start_offset, end_offset] relative to current object's string.
     */
    protected function _content_span(): array
    {
        $match = $this->_match(); // Call as method
        return $match['span'](1); // Span of group 1 (the content)
    }
}


/**
 * Class Bold
 *
 * Represents bold text (e.g., '''text''').
 */
class Bold extends BoldItalic
{
    // __slots__ is Python-specific.

    /**
     * Returns the match object for bold text.
     *
     * @return array<string, mixed> The match array.
     */
    protected function _match(): array
    {
        global $BOLD_FULLMATCH_PATTERN;
        // In Python, this matches against `self.string`.
        $match = match_with_span_and_group($BOLD_FULLMATCH_PATTERN, $this->string, MULTILINE . DOTALL);

        if ($match === null) {
            // Handle no match, perhaps throw an exception or return a default empty structure
            return [];
        }
        return $match;
    }
}


/**
 * Class Italic
 *
 * Represents italic text (e.g., ''text'').
 */
class Italic extends BoldItalic
{
    // __slots__ is Python-specific.
    public bool $end_token;

    /**
     * @param string|array<string> $string
     * @param TypeToSpans|null $_typeToSpans
     * @param array<int>|null $_span
     * @param string|int|null $_type
     * @param bool $end_token Set to True if the italic object ends with a '' token, False otherwise.
     */
    public function __construct(
        string|array $string,
        ?TypeToSpans $_typeToSpans = null,
        ?array $_span = null,
        string|int|null $_type = null,
        bool $end_token = true
    ) {
        parent::__construct($string, $_typeToSpans, $_span, $_type);
        $this->end_token = $end_token;
    }

    /**
     * Returns the match object for italic text.
     * Uses different patterns based on whether an end token is expected.
     *
     * @return array<string, mixed> The match array.
     */
    protected function _match(): array
    {
        global $ITALIC_FULLMATCH_PATTERN, $ITALIC_NOEND_FULLMATCH_PATTERN;

        if ($this->end_token) {
            $match = match_with_span_and_group($ITALIC_FULLMATCH_PATTERN, $this->string, DOTALL);
        } else {
            $match = match_with_span_and_group($ITALIC_NOEND_FULLMATCH_PATTERN, $this->string, DOTALL);
        }

        if ($match === null) {
            // Handle no match
            return [];
        }
        return $match;
    }
}
