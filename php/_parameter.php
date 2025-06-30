<?php

declare(strict_types=1);

namespace Wtp\Node;

use Wtp\Parser\SubWikiText; // Assuming SubWikiText is in Wtp\Parser
use const Wtp\Parser\WS; // Assuming WS is a constant in Wtp\Parser

/**
 * Class Parameter
 *
 * Represents a MediaWiki parameter (e.g., {{{param_name|default_value}}}).
 */
class Parameter extends SubWikiText
{
    // __slots__ is Python-specific, no direct PHP equivalent for memory optimization.

    /**
     * @return array<int> [start_offset, end_offset] relative to current object's string.
     */
    protected function _content_span(): array
    {
        return [3, -3]; // Assuming this refers to 3 characters from start and 3 from end
    }

    /**
     * Current parameter's name.
     *
     * getter: Return current parameter's name.
     * setter: set a new name for the current parameter.
     *
     * @return string
     */
    public function name(): string
    {
        $shadow = $this->_shadow; // Assuming _shadow is a property from SubWikiText
        $pipe = strpos($shadow, chr(124)); // ASCII 124 is '|'

        if ($pipe === false) {
            // No pipe, name is the whole content between {{{ and }}}.
            // Python's self(3, -3) means slice from index 3 to 3 chars from end.
            return $this->__invoke(3, -3);
        }
        // Name is from index 3 up to the pipe.
        return $this->__invoke(3, $pipe);
    }

    /**
     * @param string $newname The new name for the parameter.
     */
    public function set_name(string $newname): void
    {
        $shadow = $this->_shadow;
        $pipe = strpos($shadow, chr(124));

        if ($pipe === false) {
            // No pipe, replace the entire content between {{{ and }}}.
            $this->offsetSet(3, -3, $newname); // Offset 3, length null (to end-3)
            return;
        }
        // Replace from index 3 up to the pipe.
        $this->offsetSet(3, $pipe - 3, $newname); // Offset 3, length (pipe - 3)
    }

    /**
     * Return `|` if there is a pipe (default value) in the Parameter.
     * Return '' otherwise.
     *
     * @return string
     */
    public function pipe(): string
    {
        return strpos($this->_shadow, chr(124)) !== false ? '|' : '';
    }

    /**
     * The default value of current parameter.
     *
     * getter: Return null if there is no default.
     * setter: Set a new default value.
     * deleter: Delete the default value, including the pipe character.
     *
     * @return string|null
     */
    public function default(): ?string
    {
        $shadow = $this->_shadow;
        $pipe = strpos($shadow, chr(124));

        if ($pipe === false) {
            return null; // No default value
        }
        // Default value is from after the pipe to 3 chars from the end.
        return $this->__invoke($pipe + 1, -3);
    }

    /**
     * @param string $newdefault The new default value string.
     */
    public function set_default(string $newdefault): void
    {
        $shadow = $this->_shadow;
        $pipe = strpos($shadow, chr(124));

        if ($pipe === false) {
            // No pipe, so add pipe and new default before the closing "}}}"
            $this->insert(-3, '|' . $newdefault); // Insert 3 chars from end
            return;
        }
        // Replace from after the pipe to 3 chars from the end.
        $this->offsetSet($pipe + 1, -3 - ($pipe + 1), $newdefault);
    }

    /**
     * Deletes the default value, including the pipe character.
     */
    public function delete_default(): void
    {
        $shadow = $this->_shadow;
        $pipe = strpos($shadow, chr(124));

        if ($pipe === false) {
            return; // No default to delete
        }
        // Delete from the pipe to 3 chars from the end.
        $this->offsetUnset($pipe, -3 - $pipe);
    }

    /**
     * Append a new default parameter in the appropriate place.
     * Add the new default to the inner-most parameter.
     * If the parameter already exists among defaults, don't change anything.
     *
     * @param string $new_default_name
     */
    public function append_default(string $new_default_name): void
    {
        global $WS; // Access global WS constant from Wtp\Parser

        $stripped_default_name = trim($new_default_name, $WS);
        if ($stripped_default_name === trim($this->name(), $WS)) { // Call name as method
            return;
        }

        $dig = true;
        $innermost_param = $this;
        while ($dig) {
            $dig = false;
            $default = $innermost_param->default(); // Call default as method

            // Iterate through parameters (nested {{{...}}} structures)
            foreach ($innermost_param->parameters() as $p) { // Call parameters as method
                if ($p->string === $default) { // Compare whole string content
                    if ($stripped_default_name === trim($p->name(), $WS)) { // Call name as method
                        return; // Default already exists in inner parameter
                    }
                    $innermost_param = $p; // Dig deeper
                    $dig = true;
                    break; // Found the next innermost parameter, break and continue while loop
                }
            }
        }

        $innermost_default = $innermost_param->default(); // Call default as method
        if ($innermost_default === null) {
            // No default in innermost param, so append it just before the closing "}}}"
            $innermost_param->insert(-3, '|{{{' . $new_default_name . '}}}');
        } else {
            // There is an existing default, so wrap it with the new parameter
            $name = $innermost_param->name(); // Call name as method
            $prefix_length = strlen('{{{' . $name . '|');
            $original_content_length = strlen('{{{' . $name . '|' . $innermost_default);

            // Replace the section that was the old default value
            $innermost_param->offsetSet(
                $prefix_length,
                $original_content_length - $prefix_length,
                '{{{' . $new_default_name . '|' . $innermost_default . '}}}'
            );
        }
    }

    /**
     * Returns a list of Parameter objects found within the current Parameter,
     * excluding the current one.
     *
     * @return array<Parameter>
     */
    public function parameters(): array
    {
        // Call the parent's parameters method (from SubWikiText) and return all but the first element
        $allParameters = parent::parameters();
        return array_slice($allParameters, 1);
    }
}
