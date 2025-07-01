<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()`, `start()`, و `end()`.
 *
 * This global variable will store the compiled regex pattern for section headers.
 */
$HEADER_MATCH_PATTERN = rc('(={1,6})([^\n]+?)\1[ \t]*(\n|\Z)');

/**
 * Wrapper for preg_match that mimics Python's regex.Match object.
 *
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function match(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match($pattern . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        $matchObject[0] = $matches[0]; // Full match, [matched_string, offset]

        // Store named and numeric capture groups, and their spans
        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Named capture group
                $matchObject[$key] = $value[0]; // Matched string for the group
                $matchObject['offset_' . $key] = $value[1]; // Offset for the group
            } elseif (is_int($key) && $key > 0) { // Numeric capture group (excluding full match at index 0)
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            }
        }

        // Mimic `match[group_number_or_name]`
        $matchObject['group'] = function ($group) use ($matchObject) {
            return $matchObject[$group] ?? null;
        };

        // Mimic `match.start(group)`
        $matchObject['start'] = function ($group = 0) use ($matchObject) {
            if (isset($matchObject['offset_' . $group])) {
                return $matchObject['offset_' . $group];
            }
            return -1; // Python returns -1 for unmatched groups
        };

        // Mimic `match.end(group)`
        $matchObject['end'] = function ($group = 0) use ($matchObject) {
            if (isset($matchObject['offset_' . $group]) && isset($matchObject[$group])) {
                return $matchObject['offset_' . $group] + strlen($matchObject[$group]);
            }
            return -1; // Python returns -1 for unmatched groups
        };

        // Store the original subject string for cache validation
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}

/**
 * Class Section
 *
 * Represents a section in wikitext (e.g., ==Section Title==).
 */
class Section extends SubWikiText
{
    // In PHP, properties are declared directly. __slots__ is Python-specific.
    private ?array $_header_match_cache;

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
        $this->_header_match_cache = [null, null]; // Initialize cache
    }

    /**
     * Returns the match object for the section header. Caches the result.
     *
     * @return array<string, mixed>|null The match array for the header, or null if no header.
     */
    private function _header_match(): ?array
    {
        global $HEADER_MATCH_PATTERN; // Access the global compiled regex pattern

        list($cached_match, $cached_shadow) = $this->_header_match_cache;
        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText

        if ($cached_shadow === $shadow) {
            return $cached_match;
        }

        $m = match($HEADER_MATCH_PATTERN, $shadow); // Use the custom match function
        $this->_header_match_cache = [$m, $shadow];
        return $m;
    }

    /**
     * The level of this section.
     *
     * getter: Return level which as an int in range(1,7) or 0 for the lead section.
     * setter: Change the level.
     *
     * @return int
     */
    public function level(): int
    {
        $m = $this->_header_match(); // Call as method
        if ($m) {
            // Group 1 is the sequence of '=' characters (e.g., "==")
            return strlen($m['group'](1));
        }
        return 0; // Lead section or no header
    }

    /**
     * @param int $value The new level for the section (1-6).
     */
    public function set_level(int $value): void
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            // Cannot set level for a lead section (no header).
            // A lead section fundamentally doesn't have a level.
            throw new \RuntimeException("Cannot set level for a lead section.");
        }

        $current_level = strlen($m['group'](1));
        $level_diff = $current_level - $value;

        if ($level_diff === 0) {
            return; // No change needed
        }

        if ($level_diff < 0) {
            // New level is higher (fewer '=' signs needed), so add '=' signs.
            $new_equals = str_repeat('=', -$level_diff);
            $this->insert(0, $new_equals); // Insert at the beginning of the section string

            // Adjust end position for insertion based on original match end and new chars inserted
            $original_end2 = $m['end'](2); // End of the title group
            $this->insert($original_end2 - $level_diff, $new_equals); // Insert after the title

        } else {
            // New level is lower (more '=' signs needed), so remove '=' signs.
            // Remove from the beginning (level_diff characters)
            $this->offsetUnset(0, $level_diff);

            // Remove from the end of the header (after title), adjusting offset
            $original_end2 = $m['end'](2); // End of the title group
            $this->offsetUnset($original_end2 - $level_diff, $level_diff);
        }
    }

    /**
     * The title of this section.
     *
     * getter: Return the title or None for lead sections or sections that don't have any title.
     * setter: Set a new title.
     * deleter: Remove the title, including the equal sign and the newline after it.
     *
     * @return string|null
     */
    public function title(): ?string
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            return null; // Lead section or no header
        }
        // Group 2 is the title content itself
        return $this->__invoke($m['start'](2), $m['end'](2));
    }

    /**
     * @param string $value The new title string.
     */
    public function set_title(string $value): void
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            throw new \RuntimeException(
                "Can't set title for a lead section. "
                . 'Try adding it to contents.'
            );
        }
        // Replace the content of group 2 (title)
        $this->offsetSet($m['start'](2), $m['end'](2) - $m['start'](2), $value);
    }

    /**
     * Deletes the title of the section.
     */
    public function delete_title(): void
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            return; // No title to delete (it's a lead section)
        }
        // Delete the entire header (from its start to its end)
        $this->offsetUnset($m['start'](), $m['end']() - $m['start']());
    }

    /**
     * Contents of this section.
     *
     * getter: return the contents
     * setter: Set contents to a new string value.
     *
     * @return string
     */
    public function contents(): string
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            // For a lead section, the entire string is its content.
            return $this->__invoke(0, null); // Assumes __invoke handles null for end (to end of string)
        }
        // Content starts right after the header ends
        return $this->__invoke($m['end'](), null);
    }

    /**
     * @param string $value The new content string.
     */
    public function set_contents(string $value): void
    {
        $m = $this->_header_match(); // Call as method
        if ($m === null) {
            // For a lead section, replace the entire content.
            $this->offsetSet(0, null, $value); // Assumes offsetSet handles null for length (to end of string)
            return;
        }
        // Replace content from the end of the header to the end of the section
        $this->offsetSet($m['end'](), null, $value);
    }
}
