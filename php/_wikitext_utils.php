<?php

declare(strict_types=1);

namespace Wtp\Parser;

use Wtp\Compat\wcwidth; // Assuming wcwidth will be in a Compat namespace
use Wtp\Node\Table; // Assuming Table class will be in a Node namespace

// noinspection PyProtectedMember
use function Wtp\Parser\_config\_HTML_TAG_NAME;
use function Wtp\Parser\_config\_bare_external_link_schemes;
use function Wtp\Parser\_config\regex_pattern;
use function Wtp\Parser\_spans\BARE_EXTERNAL_LINK;
use function Wtp\Parser\_spans\EXTERNAL_LINK_URL_TAIL;
use function Wtp\Parser\_spans\INVALID_URL_CHARS;
use function Wtp\Parser\_spans\START_TAG_PATTERN;
use function Wtp\Parser\_spans\rc;

const DOTALL = 's';
const IGNORECASE = 'i';
const MULTILINE = 'm';
const VERBOSE = 'x';

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة wcwidth في Python.
 * المرجع: https://github.com/jquast/wcwidth
 */
function wcswidth(string $str): int
{
    return \Wtp\Compat\wcswidth($str);
}

/**
 * TODO: هذه الوظائف بحاجة إلى تحويل يدوي من ملف _config.py في Python.
 * المرجع: (لا يوجد توثيق عام لهذا الملف، لذا قد تحتاج إلى البحث في الكود الأصلي)
 */
function _HTML_TAG_NAME(): string
{
    return 'TODO_IMPLEMENT_HTML_TAG_NAME';
}

function _bare_external_link_schemes(): array
{
    return ['TODO_IMPLEMENT_BARE_EXTERNAL_LINK_SCHEMES'];
}

function regex_pattern(array $patterns): string
{
    return 'TODO_IMPLEMENT_REGEX_PATTERN';
}

/**
 * TODO: هذه الوظائف بحاجة إلى تحويل يدوي من ملف _spans.py في Python.
 * المرجع: (لا يوجد توثيق عام لهذا الملف، لذا قد تحتاج إلى البحث في الكود الأصلي)
 */
function BARE_EXTERNAL_LINK(): string
{
    return 'TODO_IMPLEMENT_BARE_EXTERNAL_LINK';
}

function EXTERNAL_LINK_URL_TAIL(): string
{
    return 'TODO_IMPLEMENT_EXTERNAL_LINK_URL_TAIL';
}

function INVALID_URL_CHARS(): string
{
    return 'TODO_IMPLEMENT_INVALID_URL_CHARS';
}

function START_TAG_PATTERN(): string
{
    return 'TODO_IMPLEMENT_START_TAG_PATTERN';
}

function rc(string $pattern, string $flags = ''): string
{
    return '/' . str_replace('/', '\/', $pattern) . '/' . $flags; // Basic regex compilation
}


$NAME_CAPTURING_HTML_START_TAG_FINDITER = rc(
    str_replace(
        '{name}',
        '(?<name>' . _HTML_TAG_NAME() . ')',
        START_TAG_PATTERN()
    )
);

// External links
$BRACKET_EXTERNAL_LINK_SCHEMES = regex_pattern(
    array_merge(_bare_external_link_schemes(), ['//'])
);
$BRACKET_EXTERNAL_LINK_URL = (
    $BRACKET_EXTERNAL_LINK_SCHEMES . EXTERNAL_LINK_URL_TAIL()
);
$BRACKET_EXTERNAL_LINK = '\[' . $BRACKET_EXTERNAL_LINK_URL . '[^\]\n]*+\]';
$EXTERNAL_LINK = (
    '(?>' . BARE_EXTERNAL_LINK() . '|' . $BRACKET_EXTERNAL_LINK . ')'
);
$EXTERNAL_LINK_FINDITER = rc($EXTERNAL_LINK, IGNORECASE);
$INVALID_EL_TPP_CHRS_SUB = rc(    // the [:-4] slice allows \[ and \]
    '[' . substr(INVALID_URL_CHARS(), 0, -4) . '{}\|]'
);

// Sections
$SECTION_HEADING = '^(?<equals>={1,6})[^\n]+?(?P=equals)[ \t]*+$';
$SUB_SECTION = '(?:^(?P=equals)=[^\n]+?(?P=equals)=[ \t]*+$.*?)*';
$LEAD_SECTION = '(?<section>(?<equals>).*?)';
$SECTIONS_FULLMATCH = rc(
    $LEAD_SECTION
        . '(?<section>'
        . $SECTION_HEADING
        . '.*?'    // heading  # section content
        . ')*',
    DOTALL . MULTILINE . VERBOSE
);
$SECTIONS_TOP_LEVELS_ONLY = rc(
    $LEAD_SECTION
        . '(?<section>'
        . $SECTION_HEADING
        . '.*?'
        . $SUB_SECTION
        . ')*',
    DOTALL . MULTILINE . VERBOSE
);

// Tables
$TABLE_FINDITER = rc(
    <<<'REGEX'
    # Table-start
    # Always starts on a new line with optional leading spaces or indentation.
    (?<=^[ :\\0]*+)
    {\\| # Table contents
    (?:
        # Any character, as long as it is not indicating another table-start
        (?!^\\ *+{\\|).
    )*?
    # Table-end
    \\n\\s*+
    (?> \\|} | \\Z )
    REGEX,
    DOTALL . MULTILINE . VERBOSE
);

$substitute_apostrophes = rc("('\\0*+){2,}+(?=[^']|$)", MULTILINE);

$BOLD_FINDITER = rc(
    <<<'REGEX'
    # start token
    '\\0*+'\\0*+'
    # content
    (\\0*+[^'\\n]++.*?)
    # end token
    (?:'\\0*+'\\0*+'|$)
REGEX,
    MULTILINE . VERBOSE
);

$ITALIC_FINDITER = rc(
    <<<'REGEX'
    # start token
    '\\0*+'
    # content
    (\\0*+[^'\\n]++.*?)
    # end token
    (?:'\\0*+'|$)
REGEX,
    MULTILINE . VERBOSE
);

// Types which are detected by parse_to_spans
const SPAN_PARSER_TYPES = [
    'Template',
    'ParserFunction',
    'WikiLink',
    'Comment',
    'Parameter',
    'ExtensionTag',
];

const WS = "\r\n\t ";

class DeadIndexError extends \TypeError {}

class DeadIndex extends \_int
{
    // PHP does not have direct equivalent of __slots__ from Python for performance
    // and preventing arbitrary attributes. No need to implement __slots__ explicitly.

    public function __construct(int $value = 0)
    {
        parent::__construct($value); // Call parent constructor if _int needs initialization
    }

    public function __add__(mixed $o): mixed
    {
        throw new DeadIndexError(
            'this usually means that the object has died '
                . '(overwritten or deleted) and cannot be mutated'
        );
    }

    public function __toString(): string
    {
        return 'DeadIndex()';
    }
}

// Emulating Python's int for DeadIndex to inherit from.
// In PHP, classes can extend built-in types like int directly.
// This is a placeholder for a custom int-like class if needed for specific behaviors.
// For direct inheritance from int, this would require PHP 8.1+ Enums or a custom class that behaves like int.
// For now, this is a simple wrapper to illustrate the concept.
class _int
{
    private int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function __add__(mixed $o): mixed
    {
        if (is_int($o)) {
            return $this->value + $o;
        }
        throw new \InvalidArgumentException('Can only add int to _int');
    }

    public function __toString(): string
    {
        return (string)$this->value;
    }
}


const DEAD_INDEX = new DeadIndex(); // == int() == 0
const DEAD_SPAN = [DEAD_INDEX, DEAD_INDEX, null, null];


function _table_to_text(Table $t): string
{
    $data = [];
    foreach ($t->data() as $row) {
        $rowData = [];
        foreach ($row as $cell) {
            $rowData[] = ($cell !== null) ? $cell : '';
        }
        $data[] = $rowData;
    }

    if (empty($data)) {
        return '';
    }

    $widths = array_fill(0, count($data[0]), 0);
    foreach ($data as $row) {
        foreach ($row as $ri => $d) {
            if ($ri < count($row) - 1) { // Exclude the last element as in Python's slice [:-1]
                $widths[$ri] = max($widths[$ri], wcswidth($d));
            }
        }
    }

    $caption = $t->caption;
    $output = '';

    if ($caption !== null) {
        $output .= "\n{$caption}\n";
    }

    $output .= "\n";
    $rowsOutput = [];
    foreach ($data as $r) {
        $cellsOutput = [];
        foreach ($r as $i => $d) {
            // Check if the index exists in $widths
            $width = $widths[$i] ?? 0;
            $cellsOutput[] = str_pad($d, $width, ' ', STR_PAD_RIGHT);
        }
        $rowsOutput[] = implode("\t", $cellsOutput);
    }
    $output .= implode("\n", $rowsOutput);
    $output .= "\n";

    return $output;
}
