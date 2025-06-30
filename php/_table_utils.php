<?php

declare(strict_types=1);

namespace Wtp\Parser\_table_utils;

use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser

// PHP equivalents for regex constants
const DOTALL = 's';
const VERBOSE = 'x';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()`, `start()`, `end()`, و `span()`.
 *
 * Helper function to create a Match object alike array for PHP.
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param string $flags Regex flags.
 * @return array|null
 */
function create_match_object(string $pattern, string $subject, string $flags = ''): ?array
{
    $matches = [];
    $result = preg_match($pattern . $flags, $subject, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

    if ($result === 1) {
        $matchObject = [];
        foreach ($matches as $key => $value) {
            if (is_int($key)) {
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            } elseif (is_string($key)) {
                $matchObject[$key] = $value[0];
                $matchObject['offset_' . $key] = $value[1];
            }
        }

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

        $matchObject['string'] = $subject; // Store original subject string for reference

        return $matchObject;
    }
    return null;
}

/**
 * Regex pattern for table caption matching.
 */
$CAPTION_MATCH_PATTERN = rc(
    '# Everything until the caption line
    (?P<preattrs>
        # Start of table
        \\{\\|
        (?:
            (?:
                (?!\\n\\s*+\\|)
                [\\s\\S]
            )*?
        )
        # Start of caption line
        \\n\\s*+\\|\\+
    )
    # Optional caption attrs
    (?:
        (?P<attrs>[^\\n|]*+)
        \\|(?!\\|)
    )?
    (?P<caption>.*?)
    (?:\\n[\\|\\!]|\\|\\|)
    ',
    DOTALL . VERBOSE
);

// TypeVar T: used for generics, in PHP this is handled with `@template` annotation.
// class T {} // Not needed for PHP

/**
 * Regex pattern for finding table rows (starting with `|-`).
 */
$FIND_ROWS_PATTERN = rc('\\|-\\s*(.*)');


/**
 * Regex pattern for leading digits.
 */
$HEAD_DIGITS_PATTERN = rc('\\s*+\\d+');

/**
 * Captions are optional and only one should be placed between table-start
 * and the first row. Others captions are not part of the table and will
 * be ignored.
 */
$FIRST_NON_CAPTION_LINE_PATTERN = rc('\\n[\\t \\0]*+(\\|(?!\\+)|!)|\\Z');

// PHP functions to wrap the patterns for external usage
function CAPTION_MATCH(string $subject): ?array
{
    global $CAPTION_MATCH_PATTERN;
    return create_match_object($CAPTION_MATCH_PATTERN, $subject);
}

/**
 * @param string $subject
 * @return \Generator<array>
 */
function FIND_ROWS(string $subject): \Generator
{
    global $FIND_ROWS_PATTERN;
    $matches = [];
    preg_match_all($FIND_ROWS_PATTERN, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        yield create_match_object($FIND_ROWS_PATTERN, $subject); // Pass the subject as well for contextual info if needed
    }
}

function HEAD_DIGITS(string $subject): ?array
{
    global $HEAD_DIGITS_PATTERN;
    return create_match_object($HEAD_DIGITS_PATTERN, $subject);
}

function FIRST_NON_CAPTION_LINE(string $subject): ?array
{
    global $FIRST_NON_CAPTION_LINE_PATTERN;
    // For `search` behavior, `preg_match` is fine, as it finds the first match.
    return create_match_object($FIRST_NON_CAPTION_LINE_PATTERN, $subject);
}
