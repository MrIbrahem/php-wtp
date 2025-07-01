<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser

// PHP equivalents for regex constants
const DOTALL = 's';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `span()` و `group()`.
 * هذا يحتاج إلى بناء كلاس Match في PHP أو تعديل دالة fullmatch لتوفير هذه المعلومات.
 *
 * `fullmatch` from regex library in Python.
 * This global variable will store the compiled regex pattern for WikiLink.
 */
$FULLMATCH_PATTERN = rc(
    '[\[\2]\0*+[\[\2]'
    . '('    // 1: target
    . '([^|#\]\3]*+)'    // 2: title
    . '(?>#([^|\]\3]*+))?'    // 3: fragment
    . ')'
    . '(?:\|(.*))?'    // 4: text
    . '[\]\3]\0*+[\]\3]',
    DOTALL
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
            if (is_string($key) || (is_int($key) && $key > 0)) {
                $matchObject[$key] = $value[0]; // Matched string for the group
                $matchObject['offset_' . $key] = $value[1]; // Offset for the group
            }
        }

        // Mimic `match.span(group)`
        $matchObject['span'] = function ($group) use ($matches) {
            if (isset($matches[$group]) && $matches[$group][0] !== null) {
                $start = $matches[$group][1];
                $end = $start + strlen($matches[$group][0]);
                return [$start, $end];
            }
            return [-1, -1]; // Python returns (-1, -1) for unmatched groups
        };

        // Mimic `match[group_number_or_name]`
        $matchObject['group'] = function ($group) use ($matches) {
            return $matches[$group][0] ?? null;
        };

        // Store the original subject string for cache validation
        $matchObject['string'] = $subject;

        return $matchObject;
    }
    return null;
}

/**
 * Class WikiLink
 *
 * Represents a MediaWiki internal link (e.g., [[Target|Text]]).
 */
class WikiLink extends SubWikiText
{
    // In PHP, properties are declared directly. __slots__ is Python-specific.
    private ?array $_cached_match = null;

    /**
     * @return array<int> The start and end offsets of the link's content within its string.
     */
    private function _content_span(): array
    {
        $s = $this->string;
        // Find the index of the first '[' after the initial '[['
        $firstBracket = strpos($s, '[', 0);
        $secondBracket = strpos($s, '[', $firstBracket + 1);

        // Find the index of the last ']' before the final ']]'
        $lastBracket = strrpos($s, ']');
        $secondLastBracket = strrpos($s, ']', 0, $lastBracket - strlen($s) -1);

        return [$secondBracket + 1, $secondLastBracket];
    }

    /**
     * Returns the match object for the current WikiLink's shadow string.
     * Caches the result to avoid re-matching if the string hasn't changed.
     *
     * @return array<string, mixed> The match object array.
     */
    private function _match(): array
    {
        global $FULLMATCH_PATTERN; // Access the global compiled regex pattern

        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText

        // Check if the cached match is valid for the current shadow string
        if ($this->_cached_match !== null && ($this->_cached_match['string'] ?? null) === $shadow) {
            return $this->_cached_match;
        }

        // Perform the full match and cache it
        $this->_cached_match = fullmatch($FULLMATCH_PATTERN, $shadow, DOTALL);

        // Python's `fullmatch` returns a Match object or None.
        // If it's None, this would typically raise an error or be handled.
        // For now, if no match, we return an empty array or throw.
        if ($this->_cached_match === null) {
            // This indicates a malformed WikiLink that doesn't match the pattern.
            // You might want to throw an exception or return a specific error state.
            // For now, returning an empty array to prevent fatal errors, but it's important
            // that consumers of this method handle cases where the match is null.
            return [];
        }
        return $this->_cached_match;
    }

    /**
     * WikiLink's target, including the fragment.
     * Do not include the pipe (|) in setter and getter.
     * Deleter: delete the link target, including the pipe character.
     * Use `self.target = ''` if you don't want to remove the pipe.
     *
     * @return string
     */
    public function getTarget(): string
    {
        $match = $this->_match();
        if (empty($match)) return ''; // Handle no match case

        list($b, $e) = $match['span'](1); // Group 1: target
        return $this->__invoke($b, $e); // Assuming __invoke is equivalent to self(b, e)
    }

    /**
     * @param string $s The new target string.
     */
    public function setTarget(string $s): void
    {
        $match = $this->_match();
        if (empty($match)) return; // Handle no match case

        list($b, $e) = $match['span'](1);
        $this->offsetSet($b, $e, $s); // Assuming offsetSet handles slicing like $this[b:e] = s
    }

    /**
     * Deletes the target of the WikiLink.
     */
    public function deleteTarget(): void
    {
        $m = $this->_match();
        if (empty($m)) return; // Handle no match case

        list($b, $e) = $m['span'](1);

        // Group 4 (text) check: m[4] is None in Python means no match,
        // which corresponds to $m->group(4) returning null or empty string.
        if ($m['group'](4) === null) {
            $this->offsetUnset($b, $e); // Assuming offsetUnset handles del self[b:e]
            return;
        }
        $this->offsetUnset($b, $e + 1); // Delete target and the pipe if text exists
    }

    /**
     * The [[inner text| of WikiLink ]] (not including the [[link]]trail).
     * setter: set a new value for self.text. Do not include the pipe.
     * deleter: delete self.text, including the pipe.
     *
     * @return string|null
     */
    public function getText(): ?string
    {
        $match = $this->_match();
        if (empty($match)) return null; // Handle no match case

        list($b, $e) = $match['span'](4); // Group 4: text
        if ($b === -1) { // -1 means group did not participate in the match
            return null;
        }
        return $this->__invoke($b, $e);
    }

    /**
     * @param string $s The new text string.
     */
    public function setText(string $s): void
    {
        $m = $this->_match();
        if (empty($m)) return; // Handle no match case

        list($b, $e) = $m['span'](4);
        if ($b === -1) { // If text group doesn't exist, insert pipe and text
            list($target_end, $_) = $m['span'](1); // End of target group
            $this->insert($target_end, '|' . $s); // Assuming insert handles insertion
            return;
        }
        $this->offsetSet($b, $e, $s);
    }

    /**
     * Deletes the text of the WikiLink.
     */
    public function deleteText(): void
    {
        $b, $e;
        $match = $this->_match();
        if (empty($match)) return; // Handle no match case

        list($b, $e) = $match['span'](4);
        if ($b === -1) {
            return; // No text to delete
        }
        $this->offsetUnset($b - 1, $e - ($b -1)); // Delete from (b-1) to e, effectively deleting pipe and text
    }

    /**
     * Fragment identifier.
     * getter: target's fragment identifier (do not include the # character)
     * setter: set a new fragment (do not include the # character)
     * deleter: delete fragment, including the # character.
     *
     * @return string|null
     */
    public function getFragment(): ?string
    {
        $match = $this->_match();
        if (empty($match)) return null; // Handle no match case

        list($b, $e) = $match['span'](3); // Group 3: fragment
        if ($b === -1) {
            return null;
        }
        return $this->__invoke($b, $e);
    }

    /**
     * @param string $s The new fragment string.
     */
    public function setFragment(string $s): void
    {
        $m = $this->_match();
        if (empty($m)) return; // Handle no match case

        list($b, $e) = $m['span'](3);
        if ($b === -1) { // If fragment group doesn't exist, insert hash and fragment
            list($title_end, $_) = $m['span'](2); // End of title group
            $this->insert($title_end, '#' . $s);
            return;
        }
        $this->offsetSet($b, $e, $s);
    }

    /**
     * Deletes the fragment of the WikiLink.
     */
    public function deleteFragment(): void
    {
        $match = $this->_match();
        if (empty($match)) return; // Handle no match case

        list($b, $e) = $match['span'](3);
        if ($b === -1) {
            return; // No fragment to delete
        }
        $this->offsetUnset($b - 1, $e - ($b-1)); // Delete from (b-1) to e, effectively deleting hash and fragment
    }

    /**
     * Target's title
     * getter: get target's title (do not include the # character)
     * setter: set a new title (do not include the # character)
     * deleter: return new title, including the # character.
     *
     * @return string
     */
    public function getTitle(): string
    {
        $match = $this->_match();
        if (empty($match)) return ''; // Handle no match case

        list($s, $e) = $match['span'](2); // Group 2: title
        return $this->__invoke($s, $e);
    }

    /**
     * @param string $s The new title string.
     */
    public function setTitle(string $s): void
    {
        $match = $this->_match();
        if (empty($match)) return; // Handle no match case

        list($b, $e) = $match['span'](2);
        $this->offsetSet($b, $e, $s);
    }

    /**
     * Deletes the title of the WikiLink.
     */
    public function deleteTitle(): void
    {
        $m = $this->_match();
        if (empty($m)) return; // Handle no match case

        list($s, $e) = $m['span'](2);

        // Check if group 3 (fragment) exists
        if ($m['group'](3) === null) {
            $this->offsetUnset($s, $e - $s); // Delete only the title
        } else {
            $this->offsetUnset($s, ($e + 1) - $s); // Delete title and the hash if fragment exists
        }
    }

    /**
     * Returns a list of WikiLink objects found within the current WikiLink.
     * Excludes the current WikiLink itself (Python's [1:] slice).
     *
     * @return array<WikiLink>
     */
    public function wikilinks(): array
    {
        // Call the parent's wikilinks method and return all but the first element
        $allWikilinks = parent::wikilinks();
        return array_slice($allWikilinks, 1);
    }
}
