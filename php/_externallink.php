<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use function Wtp\Parser\BRACKET_EXTERNAL_LINK_URL; // Assuming this function is in Wtp\Parser
use const Wtp\Parser\IGNORECASE; // Assuming IGNORECASE constant is in Wtp\Parser

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دالة `end()` مع تمرير المجموعة.
 *
 * This global variable will store the compiled regex pattern for URL matching within external links.
 */
$URL_MATCH_PATTERN = rc(BRACKET_EXTERNAL_LINK_URL(), IGNORECASE);

/**
 * Wrapper for preg_match that mimics Python's regex.Match object.
 *
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param int $offset The start offset for the search.
 * @param string $flags Regex flags (e.g., 's' for DOTALL).
 * @return array|null An array representing the match object, or null if no match.
 */
function match_for_url(string $pattern, string $subject, int $offset, string $flags = ''): ?array
{
    $sub = substr($subject, $offset); // Slice from offset to end, similar to Python's behavior
    $matches = [];
    $result = preg_match($pattern . $flags, $sub, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        $matchObject[0] = $matches[0]; // Full match, [matched_string, offset]

        foreach ($matches as $key => $value) {
            if (is_string($key)) { // Named capture group
                $matchObject[$key] = $value[0]; // Matched string for the group
                $matchObject['offset_' . $key] = $value[1]; // Offset for the group
            } elseif (is_int($key) && $key > 0) { // Numeric capture group
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            }
        }

        // Mimic `match.end(group)`
        $matchObject['end'] = function ($group = 0) use ($matchObject) {
            if (isset($matchObject['offset_' . $group]) && isset($matchObject[$group])) {
                // Adjust back to original subject's offset
                return $matchObject['offset_' . $group] + strlen($matchObject[$group]);
            }
            return -1; // Python returns -1 for unmatched groups
        };

        // Store the original subject string and its offset for cache validation
        $matchObject['original_subject'] = $subject;
        $matchObject['original_offset'] = $offset;

        return $matchObject;
    }
    return null;
}

/**
 * Class ExternalLink
 *
 * Represents an external link in wikitext (e.g., [http://example.com Link text] or http://example.com).
 */
class ExternalLink extends SubWikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent for memory optimization.

    /**
     * URL of the current ExternalLink object.
     *
     * getter: Return the URL.
     * setter: Set a new value for URL. Convert add brackets for bare external links.
     *
     * @return string
     */
    public function url(): string
    {
        // self(0) is equivalent to $this->string[0]
        if ($this->string[0] === '[') {
            $match = match_for_url($URL_MATCH_PATTERN, $this->_ext_link_shadow(), 1); // Call _ext_link_shadow as method, start from index 1 for bracketed links
            if ($match === null) {
                return ''; // Should not happen for valid links, but handle it
            }
            return $this->__invoke(1, $match['end']()); // From index 1 up to the end of the URL match
        }
        return $this->string; // For bare links, the whole string is the URL
    }

    /**
     * @param string $newurl The new URL string.
     */
    public function set_url(string $newurl): void
    {
        if ($this->string[0] === '[') {
            // If it's a bracketed link, replace the URL part inside brackets
            // len('[' + self.url) corresponds to the index after the URL and before the text/closing bracket
            $url_start_in_string = 1;
            $url_end_in_string = $url_start_in_string + strlen($this->url()); // Call url as method
            $this->offsetSet($url_start_in_string, $url_end_in_string - $url_start_in_string, $newurl);
        } else {
            // If it's a bare link, replace the entire string with the new URL
            $this->offsetSet(0, strlen($this->string), $newurl);
        }
    }

    /**
     * The text part (the part after the url).
     *
     * getter: Return null if this is a bare link or has no associated text.
     * setter: Automatically put the ExternalLink in brackets if it's not already.
     * deleter: Delete self.text, including the space before it.
     *
     * @return string|null
     */
    public function text(): ?string
    {
        $string = $this->string;
        if ($string[0] === '[') {
            $match = match_for_url($URL_MATCH_PATTERN, $this->_ext_link_shadow(), 1); // Start from index 1 for URL in bracketed links
            if ($match === null) {
                return null; // Malformed bracketed link
            }
            $url_end = $match['end'](); // End index relative to the sliced shadow string (from index 1)

            // Adjust url_end to be relative to the original string
            $url_end_in_original_string = 1 + $url_end;

            if (!isset($string[$url_end_in_original_string])) {
                return null; // No character after the URL, likely malformed or empty text
            }
            $end_char = $string[$url_end_in_original_string];

            if ($end_char === ']') {
                return null; // No text, just closing bracket
            }
            if ($end_char === ' ') {
                // Text starts after the space
                return substr($string, $url_end_in_original_string + 1, -1 - ($url_end_in_original_string + 1)); // From space+1 to last char -1
            }
            // Text starts immediately after URL (no space)
            return substr($string, $url_end_in_original_string, -1 - ($url_end_in_original_string)); // From url_end to last char -1
        }
        return null; // Not a bracketed link, so no text
    }

    /**
     * @param string $newtext The new text string.
     */
    public function set_text(string $newtext): void
    {
        $string = $this->string;
        if ($string[0] === '[') {
            $text = $this->text(); // Call text as method
            if ($text !== null) { // If there's existing text, replace it
                // Python's `self[-len(text) - 1 : -1]` means from (len - len(text) - 1) to (len - 1)
                $start_index = strlen($string) - strlen($text) - 1;
                $length_to_replace = strlen($text); // Just the text part, not the space or brackets
                $this->offsetSet($start_index, $length_to_replace, $newtext);
                return;
            }
            // No existing text, insert it before the closing bracket
            $this->insert(strlen($string) - 1, ' ' . $newtext); // Insert before the last char (']')
            return;
        }
        // If it's a bare link, convert to bracketed and add text
        $this->insert(strlen($string), ' ' . $newtext . ']'); // Add space, text, and closing bracket
        $this->insert(0, '['); // Add opening bracket at the beginning
    }

    /**
     * Deletes the text part of the external link.
     */
    public function delete_text(): void
    {
        $string = $this->string;
        if ($string[0] !== '[') {
            return; // Not a bracketed link, nothing to delete
        }
        $text = $this->text(); // Call text as method
        if ($text !== null) {
            // Python's `self[-len(text) - 2 : -1]` means from (len - len(text) - 2) to (len - 1)
            // This range includes the space before the text and the text itself.
            $start_index = strlen($string) - strlen($text) - 2;
            $length_to_delete = strlen($text) + 1; // Text length + space length
            $this->offsetUnset($start_index, $length_to_delete);
        }
    }

    /**
     * Return true if the ExternalLink is in brackets. False otherwise.
     *
     * @return bool
     */
    public function in_brackets(): bool
    {
        return $this->string[0] === '[';
    }

    /**
     * Returns an empty list, as external links cannot contain other external links in this model.
     *
     * @return array<ExternalLink>
     */
    public function external_links(): array
    {
        return [];
    }

    /**
     * Helper method to generate the shadow string used for URL matching.
     * In the original Python, this was `_ext_link_shadow`.
     * If there's no complex logic beyond using `_shadow` from SubWikiText,
     * this might simply return `_shadow`. However, the Python code uses `self._ext_link_shadow`,
     * implying a specific shadow or a different processing.
     * For now, assuming it refers to the standard shadow of the SubWikiText.
     * If `_ext_link_shadow` is more complex, it needs to be defined in SubWikiText or here.
     *
     * @return string
     */
    private function _ext_link_shadow(): string
    {
        // If there's no special shadow for external links, use the default _shadow.
        // If it needs to be different (e.g., removing certain characters), implement that here.
        return $this->_shadow; // Assuming _shadow property exists in SubWikiText
    }
}
