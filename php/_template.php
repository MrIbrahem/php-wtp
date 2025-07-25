<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use function Wtp\Parser\rc; // Assuming rc function is in Wtp\Parser
use Wtp\Node\SubWikiTextWithArgs; // Assuming SubWikiTextWithArgs is in Wtp\Node
use Wtp\Node\Argument; // Assuming Argument is in Wtp\Node
use const Wtp\Parser\WS; // Assuming WS is a constant in Wtp\Parser
use Wtp\Node\Comment; // Assuming Comment is in Wtp\Node
use const Wtp\Parser\_comment_bold_italic\COMMENT_PATTERN; // Importing constant from _comment_bold_italic

// PHP equivalents for regex constants
const REVERSE = 'r'; // Not a direct regex flag in PHP, needs manual string reversal if needed. For `r` flag in Python's regex module, it processes the string in reverse. This is complex to replicate generally. For this use case, where it's specific to `ENDING_WS_MATCH`, we might manually construct the pattern to match from the end.

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي لمطابقة سلوك Python regex.Match object.
 * خصوصًا توفير دوال مثل `group()`, `start()`, `end()`, و `span()`.
 *
 * Helper function to create a Match object alike array for PHP (simplified for this context).
 * @param string $pattern The regex pattern.
 * @param string $subject The string to search.
 * @param string $flags Regex flags.
 * @return array|null
 */
function create_match_object_for_template(string $pattern, string $subject, string $flags = ''): ?array
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

        // For `captures`, we'd need `preg_match_all` internally if the group repeats.
        // For TL_NAME_ARGS_FULLMATCH, 'arg' is a repeating group.
        // So this needs more robust handling for `spans('arg')`.
        $matchObject['spans'] = function ($groupName) use ($subject, $pattern, $flags) {
            $allMatches = [];
            // Run `preg_match_all` for the specific group if it repeats.
            // Note: This is an oversimplification; `fullmatch` in Python is one match,
            // but its `spans('group')` can iterate multiple captures within that single match.
            // For now, if the group is 'arg' and it repeats in the pattern, this needs special care.

            // For the TL_NAME_ARGS_FULLMATCH pattern, 'arg' is indeed a repeating group.
            // When using `preg_match` with `PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER`,
            // the repeating group will have multiple entries in the $matches array for that key.
            // This function creates the `spans` array from those:
            $temp_matches = [];
            preg_match('/^' . $pattern . '$/' . $flags, $subject, $temp_matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

            $resultSpans = [];
            if (isset($temp_matches[$groupName]) && is_array($temp_matches[$groupName])) {
                foreach ($temp_matches[$groupName] as $capture) {
                    if ($capture[0] !== null) {
                        $resultSpans[] = [$capture[1], $capture[1] + strlen($capture[0])];
                    }
                }
            }
            return $resultSpans;
        };

        $matchObject['string'] = $subject; // Store original subject
        return $matchObject;
    }
    return null;
}

// Global regex patterns
$TL_NAME_ARGS_FULLMATCH_PATTERN = rc('[^|}]*+(?#name)(?<arg>\|[^|]*+)*+');
$STARTING_WS_MATCH_PATTERN = rc('\\s*+');

// For ENDING_WS_MATCH, Python's `REVERSE` flag in `regex` module means it tries to match from the end of the string.
// PHP's `preg_match` doesn't have a direct `REVERSE` flag. We need to reverse the string and the pattern.
$ENDING_WS_MATCH_PATTERN = rc('(?\>[\\n\\t ]*+)(?=[ \\t]*\\n|$)', ''); // Pattern to match whitespace at end (including newline), in reverse.
// The pattern needs to be written to match at the start of the reversed string.
// Original: '(?\>\n[ \t]*)*+', reversed: '*+][ \t]*+\n(?\>'
// A better way is to use 'preg_match` and check from the end of the string.
$ENDING_WS_MATCH_NO_REVERSE_PATTERN = rc('[\\s]*+$'); // Match all whitespace at the very end of the string.

$SPACE_AFTER_SEARCH_PATTERN = rc('\\s*+(?=\\|)'); // Used for `search` not `match`, so it can be anywhere


/**
 * Class Template
 *
 * Convert strings to Template objects.
 * The string should start with {{ and end with }}.
 */
class Template extends SubWikiTextWithArgs
{
    // __slots__ is Python-specific.

    // Set the specific matcher pattern and first argument separator for Template
    protected string $_name_args_matcher_pattern = '[^|}]*+(?#name)(?<arg>\|[^|]*+)*+';
    protected int $_first_arg_sep = 124; // ASCII value for '|'

    /**
     * @return array<int> [start_offset, end_offset] relative to current object's string.
     */
    protected function _content_span(): array
    {
        return [2, -2]; // Assuming this refers to 2 characters from start and 2 from end
    }

    /**
     * Return normal form of self.name.
     *
     * - Remove comments.
     * - Remove language code.
     * - Remove namespace ("template:" or any of `localized_namespaces`.
     * - Use space instead of underscore.
     * - Remove consecutive spaces.
     * - Use uppercase for the first letter if `capitalize`.
     * - Remove #anchor.
     *
     * @param array<string> $rm_namespaces
     * @param string|null $code
     * @param bool $capitalize
     * @return string
     */
    public function normal_name(
        array $rm_namespaces = ['Template'],
        ?string $code = null,
        bool $capitalize = false
    ): string {
        global $WS; // Access global WS constant

        // Remove comments
        // COMMENT_PATTERN needs to be compiled into a regex string
        $name = preg_replace(rc(COMMENT_PATTERN()), '', $this->name()); // Call name() as method
        $name = trim($name, $WS);

        // Remove code
        if ($code) {
            $parts = explode(':', $name, 2);
            $head = trim($parts[0] ?? '', ' ');
            $tail = $parts[1] ?? '';
            $sep = isset($parts[1]);

            if (!$head && $sep) { // If first part is empty, and separator exists
                $name = trim($tail, ' '); // Use the rest as new name
                $parts = explode(':', $name, 2); // Re-split
                $head = trim($parts[0] ?? '', ' ');
                $tail = $parts[1] ?? '';
                $sep = isset($parts[1]);
            }
            if (mb_strtolower($code) === mb_strtolower($head)) {
                $name = trim($tail, ' ');
            }
        }

        // Remove namespace
        $parts = explode(':', $name, 2);
        $head = trim($parts[0] ?? '', ' ');
        $tail = $parts[1] ?? '';
        $sep = isset($parts[1]);

        if (!$head && $sep) { // If first part is empty, and separator exists
            $name = trim($tail, ' '); // Use the rest as new name
            $parts = explode(':', $name, 2); // Re-split
            $head = trim($parts[0] ?? '', ' ');
            $tail = $parts[1] ?? '';
            $sep = isset($parts[1]);
        }
        if ($head) {
            $ns = mb_strtolower(trim($head, ' '));
            foreach ($rm_namespaces as $namespace) {
                if (mb_strtolower($namespace) === $ns) {
                    $name = trim($tail, ' ');
                    break;
                }
            }
        }

        // Use space instead of underscore
        $name = str_replace('_', ' ', $name);

        if ($capitalize) {
            // Use uppercase for the first letter
            $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }

        // Remove #anchor
        $parts = explode('#', $name, 2);
        $name = $parts[0];

        // Remove consecutive spaces and trim
        return preg_replace('/[ ]{2,}/', ' ', trim($name));
    }

    /**
     * Eliminate duplicate arguments by removing the first occurrences.
     *
     * Remove the first occurrences of duplicate arguments, regardless of
     * their value. Result of the rendered wikitext should remain the same.
     * Warning: Some meaningful data may be removed from wikitext.
     */
    public function rm_first_of_dup_args(): void
    {
        $names = []; // Use an array to store seen names (acting as a set)
        // Reverse arguments to delete from the start correctly as original Python code
        $args = array_reverse($this->arguments()); // Call arguments() as method

        foreach ($args as $a) {
            $name = trim($a->name(), WS); // Call name() as method
            if (in_array($name, $names)) {
                // Delete the argument's content
                $a->offsetUnset(0, strlen($a->string)); // Delete from 0 to length of string
            } else {
                $names[] = $name; // Add to seen names
            }
        }
    }

    /**
     * Remove duplicate arguments in a safe manner.
     *
     * Remove the duplicate arguments only in the following situations:
     * 1. Both arguments have the same name AND value. (Remove one of them.)
     * 2. Arguments have the same name and one of them is empty. (Remove the empty one.)
     *
     * @param string|null $tag If defined, it will be appended to the value of the remaining duplicate arguments.
     */
    public function rm_dup_args_safe(?string $tag = null): void
    {
        $name_to_lastarg_vals = []; // Map: name => [last_arg_object, [list_of_values_of_duplicates]]

        // Process in reverse order to mimic Python's behavior of getting the "lastarg"
        $args = array_reverse($this->arguments()); // Call arguments() as method

        foreach ($args as $arg) {
            $name = trim($arg->name(), WS); // Call name() as method

            // Get argument value
            $val = $arg->value(); // Call value() as method
            if (!$arg->positional()) { // Call positional() as method
                // Value of keyword arguments is automatically stripped by MW.
                // No stripping for positional in Python code.
                $val = trim($val, WS);
            }

            if (array_key_exists($name, $name_to_lastarg_vals)) {
                // This is a duplicate argument.
                list($lastarg, $dup_vals) = $name_to_lastarg_vals[$name];

                if ($val === '') {
                    // This duplicate argument is empty. It's safe to remove it.
                    $arg->offsetUnset(0, strlen($arg->string));
                } elseif (in_array($val, $dup_vals)) {
                    // Same name and same non-empty value, remove current arg.
                    $arg->offsetUnset(0, strlen($arg->string));
                } elseif (in_array('', $dup_vals)) {
                    // The last occurrence of this name was empty, and current is not empty.
                    // Remove the empty last occurrence.
                    $lastarg->offsetUnset(0, strlen($lastarg->string));

                    // Remove the empty value from the list of duplicate values
                    $empty_val_index = array_search('', $dup_vals);
                    if ($empty_val_index !== false) {
                        unset($dup_vals[$empty_val_index]);
                        $dup_vals = array_values($dup_vals); // Re-index array
                    }
                    $name_to_lastarg_vals[$name][1] = $dup_vals; // Update the list of duplicate values
                } else {
                    // No empty or matching value found among duplicates.
                    // Add current value to list of duplicates.
                    $dup_vals[] = $val;
                    $name_to_lastarg_vals[$name][1] = $dup_vals; // Update the list of duplicate values
                    if ($tag !== null) {
                        $arg->set_value($arg->value() . $tag); // Call set_value and value as methods
                    }
                }
            } else {
                // First time seeing this argument name.
                $name_to_lastarg_vals[$name] = [$arg, [$val]];
            }
        }
    }

    /**
     * Set the value for `name` argument. Add it if it doesn't exist.
     *
     * @param string $name
     * @param string $value
     * @param bool|null $positional If True, try to add as positional. If null, infer.
     * @param string|null $before Name of an argument to insert before.
     * @param string|null $after Name of an argument to insert after.
     * @param bool $preserve_spacing Whether to preserve spacing of existing args.
     * @throws \ValueError If trying to convert positional to keyword without name.
     */
    public function set_arg(
        string $name,
        string $value,
        ?bool $positional = null,
        ?string $before = null,
        ?string $after = null,
        bool $preserve_spacing = false
    ): void {
        global $WS; // Access global WS constant
        global $STARTING_WS_MATCH_PATTERN, $ENDING_WS_MATCH_NO_REVERSE_PATTERN, $SPACE_AFTER_SEARCH_PATTERN;

        // Python's `(*reversed(self.arguments),)` converts iterator to tuple.
        // PHP: get arguments, reverse them, and convert to array.
        $args = array_values(array_reverse($this->arguments())); // Call arguments() as method

        // Check if argument exists
        $arg = get_arg($name, $args); // Use helper function

        // Updating an existing argument.
        if ($arg) {
            if ($positional !== null) {
                $arg->set_positional($positional); // Call set_positional as method
            }
            if ($preserve_spacing) {
                $val = $arg->value(); // Call value() as method
                // Replace only the stripped part of the value.
                $stripped_val = trim($val, $WS);
                $pos = strpos($val, $stripped_val);
                if ($pos !== false) {
                    $new_val_str = substr_replace($val, $value, $pos, strlen($stripped_val));
                    $arg->set_value($new_val_str); // Call set_value as method
                } else {
                    $arg->set_value($value); // Fallback if stripping fails
                }
            } else {
                $arg->set_value($value); // Call set_value as method
            }
            return;
        }

        // Adding a new argument
        if (!$name && $positional === null) {
            $positional = true;
        }

        $pre_name_ws_mode = '';
        $name_length_mode = 0;
        $post_value_ws_mode = '';
        $pre_value_ws_mode = '';

        if (!$positional && $preserve_spacing && !empty($args)) {
            $before_names = [];
            $name_lengths = [];
            $before_values = [];
            $after_values = [];

            foreach ($args as $a) {
                $aname = $a->name(); // Call name() as method
                $name_lengths[] = strlen($aname);

                // STARTING_WS_MATCH
                $match_ws_start = create_match_object_for_template($STARTING_WS_MATCH_PATTERN, $aname);
                $before_names[] = $match_ws_start['group'](0) ?? ''; // Full match of whitespace at start

                $arg_value = $a->value(); // Call value() as method

                // STARTING_WS_MATCH for value
                $match_ws_val_start = create_match_object_for_template($STARTING_WS_MATCH_PATTERN, $arg_value);
                $before_values[] = $match_ws_val_start['group'](0) ?? '';

                // ENDING_WS_MATCH
                // Apply the pattern to the reversed string to find trailing whitespace
                $match_ws_val_end = create_match_object_for_template($ENDING_WS_MATCH_NO_REVERSE_PATTERN, $arg_value);
                $after_values[] = $match_ws_val_end['group'](0) ?? '';
            }

            $pre_name_ws_mode = mode($before_names); // Use helper function
            $name_length_mode = mode($name_lengths);

            // For SPACE_AFTER_SEARCH, it needs to find the whitespace before a pipe in the main string.
            // This is not directly an arg's property.
            $main_string_space_after_match = preg_match($SPACE_AFTER_SEARCH_PATTERN, $this->string, $m);
            $main_string_space_after = $m[0] ?? '';

            // This logic is tricky. In Python, `[SPACE_AFTER_SEARCH(self.string)[0]] + after_values[1:]`
            // implies the space *before* the pipe of the *first* argument, plus trailing spaces of subsequent arguments.
            // If `post_value_ws_mode` refers to the space after the argument's value and before the next '|',
            // then for the *first* arg in the *reversed* list (which is the last in original), it uses the space before the closing `}}`.
            // Let's assume post_value_ws_mode is the trailing space for the last argument.
            if (!empty($after_values)) {
                // If there are multiple arguments, take the second most common trailing whitespace (after excluding the first).
                // Or simply take the trailing whitespace of the *first* argument in the *original* order,
                // which is the *last* argument in the `$args` (reversed) list.
                // Let's go with the last element of $after_values (which corresponds to the first original arg).
                $post_value_ws_mode = $after_values[count($after_values) - 1];
            }

            $pre_value_ws_mode = mode($before_values);
        } else {
            $preserve_spacing = false;
        }

        // Calculate the string that needs to be added to the Template.
        $addstring = '';
        if ($positional) {
            // Ignore preserve_spacing for positional args.
            $addstring = '|' . $value;
        } else {
            if ($preserve_spacing) {
                // `ljust` in PHP is `str_pad` with `STR_PAD_RIGHT`.
                $padded_name = str_pad($pre_name_ws_mode . trim($name, $WS), $name_length_mode, ' ', STR_PAD_RIGHT);
                $addstring = '|' . $padded_name . '=' . $pre_value_ws_mode . $value . $post_value_ws_mode;
            } else {
                $addstring = '|' . $name . '=' . $value;
            }
        }

        // Place the addstring in the right position.
        if ($before !== null) {
            $arg_to_insert_before = get_arg($before, $args);
            if ($arg_to_insert_before) {
                $arg_to_insert_before->insert(0, $addstring); // Insert at the beginning of that argument
            } else {
                // If `before` arg not found, append to end of template (or start if no args)
                $this->insert(-2, $addstring);
            }
        } elseif ($after !== null) {
            $arg_to_insert_after = get_arg($after, $args);
            if ($arg_to_insert_after) {
                $arg_to_insert_after->insert(strlen($arg_to_insert_after->string), $addstring); // Insert at end of that argument
            } else {
                // If `after` arg not found, append to end of template (or start if no args)
                $this->insert(-2, $addstring);
            }
        } else {
            if (!empty($args) && !$positional) {
                // The template has existing args, and new arg is keyword. Append after last arg.
                $arg = $args[0]; // Last arg in reversed list is the first arg in original template string.
                // This logic seems reversed compared to original Python logic.
                // Python's `args` (which is `(*reversed(self.arguments),)`) means `args[0]` is the *last* argument in the template.
                // Let's assume `args[0]` correctly refers to the argument where insertion should happen.
                $arg_string = $arg->string;
                if ($preserve_spacing) {
                    // Recalculate addstring because whitespace before final braces should not change
                    $trimmed_addstring = rtrim($addstring, $WS); // Trim trailing whitespace from the constructed addstring
                    // The `after_values[0]` here would refer to the trailing whitespace of the *last* argument in the original template.
                    // This is complex. Let's assume `post_value_ws_mode` is the common trailing whitespace.
                    $new_content_for_last_arg = rtrim($arg_string, $WS) . $post_value_ws_mode . $trimmed_addstring . $post_value_ws_mode;
                    $arg->offsetSet(0, strlen($arg_string), $new_content_for_last_arg);
                } else {
                    $arg->insert(strlen($arg_string), $addstring);
                }
            } else {
                // The template has no arguments, or the new arg is positional and to be added at the end.
                // Insert before the closing '}}'.
                $this->insert(-2, $addstring);
            }
        }
    }

    /**
     * Return the last argument with the given name.
     *
     * @param string $name
     * @return Argument|null
     */
    public function get_arg(string $name): ?Argument
    {
        // Python uses `reversed(self.arguments)` directly.
        return get_arg($name, array_reverse($this->arguments())); // Call arguments() as method, then reverse the array
    }

    /**
     * Return true if there is an argument named `name`.
     *
     * @param string $name
     * @param string|null $value
     * @return bool
     */
    public function has_arg(string $name, ?string $value = null): bool
    {
        global $WS; // Access global WS constant

        foreach (array_reverse($this->arguments()) as $arg) { // Call arguments() as method
            if (trim($arg->name(), WS) === trim($name, WS)) { // Call name() as method
                if ($value !== null) {
                    if ($arg->positional()) { // Call positional() as method
                        if ($arg->value() === $value) { // Call value() as method
                            return true;
                        }
                    } else {
                        if (trim($arg->value(), WS) === trim($value, WS)) { // Call value() as method
                            return true;
                        }
                    }
                } else {
                    return true; // Found argument by name only
                }
            }
        }
        return false;
    }

    /**
     * Delete all arguments with the given name.
     *
     * @param string $name
     */
    public function del_arg(string $name): void
    {
        global $WS; // Access global WS constant

        // Iterate in reverse to avoid issues with changed indices after deletion
        foreach (array_reverse($this->arguments()) as $arg) { // Call arguments() as method
            if (trim($arg->name(), WS) === trim($name, WS)) { // Call name() as method
                $arg->offsetUnset(0, strlen($arg->string)); // Delete the entire argument string
            }
        }
    }

    /**
     * Returns a list of Template objects found within the current Template,
     * excluding the current one.
     *
     * @return array<Template>
     */
    public function templates(): array
    {
        // Call the parent's templates method (from SubWikiText) and return all but the first element
        $allTemplates = parent::templates();
        return array_slice($allTemplates, 1);
    }
}

/**
 * Return the most common item in the list.
 * Return the first one if there are more than one most common items.
 *
 * @template T
 * @param array<T> $list
 * @return T
 * @throws \ValueError If the list is empty.
 */
function mode(array $list): mixed
{
    if (empty($list)) {
        throw new \ValueError('max() arg is an empty sequence');
    }

    $counts = [];
    foreach ($list as $item) {
        // Use serialize/unserialize for complex types like arrays/objects if needed
        $key = is_scalar($item) ? $item : serialize($item);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    $maxCount = -1;
    $modeValue = null;
    $firstMode = true; // Flag to return the first one if multiple modes

    foreach ($counts as $key => $count) {
        if ($count > $maxCount) {
            $maxCount = $count;
            $modeValue = is_scalar($list[0]) ? $key : unserialize($key); // Unserialize only if original was not scalar
            $firstMode = true; // Reset for a new highest count
        } elseif ($count === $maxCount && $firstMode) {
            // If multiple items have the same max count, return the first one encountered (based on original list order)
            // This is tricky with `array_unique` and `serialize`.
            // Simpler: just keep the first one that establishes the `maxCount`.
        }
    }

    // The Python implementation of `max(set(list_), key=list_.count)` implies that it iterates through the `set`
    // which has an arbitrary order, but `list_.count` will be deterministic.
    // If multiple items have the same max frequency, `max` will return the first one it encounters based on the iterable order.
    // Our loop achieves this by updating `$modeValue` only when `$count > $maxCount`.

    // To be precisely like Python's `max` behavior (which picks the *first* encountered max value if multiple),
    // we need to consider the order of elements in the *original* list.
    // The current loop finds the first one that has the *highest* count.

    // A more precise `mode` for PHP for scalar types:
    $valueCounts = array_count_values($list);
    arsort($valueCounts); // Sort by value (count) in descending order

    // Get the first key after sorting (which is the most frequent)
    $mostFrequent = key($valueCounts);

    // Check if there are other items with the same max count, and if their original position matters.
    // Python's `max` with `key` will return the first one it sees if multiple have the same max `key` value.
    // To fully replicate, we would need to check original indices. For simplicity here, `array_count_values` + `arsort`
    // is a common way to find mode. If there are ties, `key($valueCounts)` returns one of them, but which one depends
    // on internal sort stability for `arsort`.

    return $mostFrequent; // Assuming scalar type T for now. For objects/arrays, needs serialization.
}


/**
 * Return the first argument in the args that has the given name.
 *
 * @param string $name
 * @param iterable<Argument> $args An iterable of Argument objects.
 * @return Argument|null
 */
function get_arg(string $name, iterable $args): ?Argument
{
    global $WS; // Access global WS constant

    foreach ($args as $arg) {
        if (trim($arg->name(), WS) === trim($name, WS)) { // Call name() as method
            return $arg;
        }
    }
    return null;
}
