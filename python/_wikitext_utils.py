from __future__ import annotations


from regex import (
    DOTALL,
    IGNORECASE,
    MULTILINE,
    VERBOSE,
)
from wcwidth import wcswidth

# noinspection PyProtectedMember
from ._config import (
    _HTML_TAG_NAME,
    _bare_external_link_schemes,
    regex_pattern,
)
from ._spans import (
    BARE_EXTERNAL_LINK,
    EXTERNAL_LINK_URL_TAIL,
    INVALID_URL_CHARS,
    START_TAG_PATTERN,
    rc,
)

NAME_CAPTURING_HTML_START_TAG_FINDITER = rc(
    START_TAG_PATTERN.replace(
        b'{name}', rb'(?<name>' + _HTML_TAG_NAME + rb')', 1
    )
).finditer

# External links
BRACKET_EXTERNAL_LINK_SCHEMES = regex_pattern(
    _bare_external_link_schemes | {'//'}
)
BRACKET_EXTERNAL_LINK_URL = (
    BRACKET_EXTERNAL_LINK_SCHEMES + EXTERNAL_LINK_URL_TAIL
)
BRACKET_EXTERNAL_LINK = rb'\[' + BRACKET_EXTERNAL_LINK_URL + rb'[^\]\n]*+\]'
EXTERNAL_LINK = (
    rb'(?>' + BARE_EXTERNAL_LINK + rb'|' + BRACKET_EXTERNAL_LINK + rb')'
)
EXTERNAL_LINK_FINDITER = rc(EXTERNAL_LINK, IGNORECASE).finditer
INVALID_EL_TPP_CHRS_SUB = rc(  # the [:-4] slice allows \[ and \]
    rb'[' + INVALID_URL_CHARS[:-4] + rb'{}|]'
).sub

# Sections
SECTION_HEADING = rb'^(?<equals>={1,6})[^\n]+?(?P=equals)[ \t]*+$'
SUB_SECTION = rb'(?:^(?P=equals)=[^\n]+?(?P=equals)=[ \t]*+$.*?)*'
LEAD_SECTION = rb'(?<section>(?<equals>).*?)'
SECTIONS_FULLMATCH = rc(
    LEAD_SECTION
    + rb'(?<section>'
    + SECTION_HEADING
    + rb'.*?'  # heading  # section content
    rb')*',
    DOTALL | MULTILINE | VERBOSE,
).fullmatch
SECTIONS_TOP_LEVELS_ONLY = rc(
    LEAD_SECTION
    + rb'(?<section>'
    + SECTION_HEADING
    + rb'.*?'
    + SUB_SECTION
    + rb')*',
    DOTALL | MULTILINE | VERBOSE,
).fullmatch

# Tables
TABLE_FINDITER = rc(
    rb"""
    # Table-start
    # Always starts on a new line with optional leading spaces or indentation.
    (?<=^[ :\0]*+)
    {\| # Table contents
    (?:
        # Any character, as long as it is not indicating another table-start
        (?!^\ *+\{\|).
    )*?
    # Table-end
    \n\s*+
    (?> \|} | \Z )
    """,
    DOTALL | MULTILINE | VERBOSE,
).finditer

substitute_apostrophes = rc(rb"('\0*+){2,}+(?=[^']|$)", MULTILINE).sub

BOLD_FINDITER = rc(
    rb"""
    # start token
    '\0*+'\0*+'
    # content
    (\0*+[^'\n]++.*?)
    # end token
    (?:'\0*+'\0*+'|$)
""",
    MULTILINE | VERBOSE,
).finditer

ITALIC_FINDITER = rc(
    rb"""
    # start token
    '\0*+'
    # content
    (\0*+[^'\n]++.*?)
    # end token
    (?:'\0*+'|$)
""",
    MULTILINE | VERBOSE,
).finditer

# Types which are detected by parse_to_spans
SPAN_PARSER_TYPES = {
    'Template',
    'ParserFunction',
    'WikiLink',
    'Comment',
    'Parameter',
    'ExtensionTag',
}

WS = '\r\n\t '


class DeadIndexError(TypeError):
    pass


class DeadIndex(int):
    """Do not allow adding to another integer but allow usage in a slice.

    Addition of indices is the main operation during mutation of WikiText
    objects.
    """

    __slots__ = ()

    def __add__(self, o):
        raise DeadIndexError(
            'this usually means that the object has died '
            '(overwritten or deleted) and cannot be mutated'
        )

    __radd__ = __add__

    def __repr__(self):
        return 'DeadIndex()'


DEAD_INDEX = DeadIndex()  # == int() == 0
DEAD_SPAN = DEAD_INDEX, DEAD_INDEX, None, None


def _table_to_text(t: Table) -> str:
    data = [
        [(cell if cell is not None else '') for cell in row]
        for row in t.data()
    ]
    if not data:
        return ''
    widths = [0] * len(data[0])
    for row in data:
        for ri, d in enumerate(row[:-1]):
            widths[ri] = max(widths[ri], wcswidth(d))
    caption = t.caption
    return (
        (f'\n{caption}\n' if caption is not None else '')
        + '\n'
        + '\n'.join(
            '\t'.join(f'{d:<{w}}' for (w, d) in zip(widths, r)) for r in data
        )
        + '\n'
    )
