from __future__ import annotations

from bisect import bisect_left, bisect_right, insort_right
from html import unescape
from itertools import islice
from operator import attrgetter
from typing import (
    Callable,
    Iterable,
    MutableSequence,
    overload,
)
from warnings import warn

from regex import (
    MULTILINE,
    Match,
    finditer,
    match,
    search,
)
from wcwidth import wcswidth

# noinspection PyProtectedMember
from ._config import (
    KNOWN_FILE_EXTENSIONS,
    _parsable_tag_extensions,
    _tag_extensions,
)
from ._spans import (

    END_TAG_PATTERN,
    START_TAG_PATTERN,
    TypeToSpans,
    parse_to_spans,
    rc,
)

from ._wikitext_utils import (
    NAME_CAPTURING_HTML_START_TAG_FINDITER,
    EXTERNAL_LINK_FINDITER,
    INVALID_EL_TPP_CHRS_SUB,
    SECTIONS_FULLMATCH,
    SECTIONS_TOP_LEVELS_ONLY,
    TABLE_FINDITER,
    substitute_apostrophes,
    BOLD_FINDITER,
    ITALIC_FINDITER,
    SPAN_PARSER_TYPES,
    WS,
    DEAD_SPAN,
    _table_to_text
)
