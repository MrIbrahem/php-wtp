

"""
from ._table_utils import CAPTION_MATCH, T, FIND_ROWS, HEAD_DIGITS, FIRST_NON_CAPTION_LINE
"""

from __future__ import annotations

from typing import TypeVar

from regex import DOTALL, VERBOSE

from ._wikitext import rc

CAPTION_MATCH = rc(
    rb"""
    # Everything until the caption line
    (?P<preattrs>
        # Start of table
        {\|
        (?:
            (?:
                (?!\n\s*+\|)
                [\s\S]
            )*?
        )
        # Start of caption line
        \n\s*+\|\+
    )
    # Optional caption attrs
    (?:
        (?P<attrs>[^\n|]*+)
        \|(?!\|)
    )?
    (?P<caption>.*?)
    (?:\n[\|\!]|\|\|)
    """,
    DOTALL | VERBOSE,
).match
T = TypeVar('T')
FIND_ROWS = rc(rb'\|-(.*)').finditer


HEAD_DIGITS = rc(rb'\s*+\d+').match

# Captions are optional and only one should be placed between table-start
# and the first row. Others captions are not part of the table and will
# be ignored.
FIRST_NON_CAPTION_LINE = rc(rb'\n[\t \0]*+(\|(?!\+)|!)|\Z').search
