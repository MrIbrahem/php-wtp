from __future__ import annotations

from bisect import bisect_left, bisect_right
from itertools import islice
from typing import (
    Iterable,
    MutableSequence,
)
from ._spans import (
    TypeToSpans,
)

from ._wikitext_utils import (
    SPAN_PARSER_TYPES,
)

from ._wikitextmain import WikiText


class SubWikiText(WikiText):
    """Define a class to be inherited by some subclasses of WikiText.

    Allow focusing on a particular part of WikiText.
    """

    __slots__ = '_type'

    def __init__(
        self,
        string: str | MutableSequence[str],
        _type_to_spans: TypeToSpans | None = None,
        _span: list | None = None,
        _type: str | int | None = None,
    ) -> None:
        """Initialize the object."""
        if _type is None:
            # assert _span is None
            # assert _type_to_spans is None
            # https://youtrack.jetbrains.com/issue/PY-29770
            # noinspection PyDunderSlots,PyUnresolvedReferences
            self._type = _type = type(self).__name__
            super().__init__(string)
        else:
            # assert _span is not None
            # assert _type_to_spans is not None
            # https://youtrack.jetbrains.com/issue/PY-29770
            # noinspection PyDunderSlots,PyUnresolvedReferences
            self._type = _type
            super().__init__(string, _type_to_spans)
            self._span_data: list = _span  # type: ignore

    def _subspans(self, type_: str) -> list[list[int]]:
        """Yield all the sub-span indices excluding self._span."""
        ss, se, _, _ = self._span_data
        spans = self._type_to_spans[type_]
        # Do not yield self._span by bisecting for s < ss.
        # The second bisect is an optimization and should be on [se + 1],
        # but empty spans are not desired thus [se] is used.
        b = bisect_left(spans, [ss])
        return [
            span
            for span in spans[b : bisect_right(spans, [se], b)]
            if span[1] <= se
        ]

    def ancestors(self, type_: str | None = None) -> list[WikiText]:
        """Return the ancestors of the current node.

        :param type_: the type of the desired ancestors as a string.
            Currently the following types are supported: {Template,
            ParserFunction, WikiLink, Comment, Parameter, ExtensionTag}.
            The default is None and means all the ancestors of any type above.
        """
        if type_ is None:
            types = SPAN_PARSER_TYPES
        else:
            types = (type_,)
        lststr = self._lststr
        type_to_spans = self._type_to_spans
        ss, se, _, _ = self._span_data
        ancestors = []
        ancestors_append = ancestors.append
        for type_ in types:
            cls = globals()[type_]
            spans = type_to_spans[type_]
            for span in spans[: bisect_right(spans, [ss])]:
                if se < span[1]:
                    ancestors_append(cls(lststr, type_to_spans, span, type_))
        return sorted(ancestors, key=lambda i: ss - i._span_data[0])

    def parent(self, type_: str | None = None) -> WikiText | None:
        """Return the parent node of the current object.

        :param type_: the type of the desired parent object.
            Currently the following types are supported: {Template,
            ParserFunction, WikiLink, Comment, Parameter, ExtensionTag}.
            The default is None and means the first parent, of any type above.
        :return: parent WikiText object or None if no parent with the desired
            `type_` is found.
        """
        ancestors = self.ancestors(type_)
        if ancestors:
            return ancestors[0]
        return None


def _outer_spans(sorted_spans: list[list[int]]) -> Iterable[list[int]]:
    """Yield the outermost intervals."""
    for i, span in enumerate(sorted_spans):
        se = span[1]
        for ps, pe, _, _ in islice(sorted_spans, None, i):
            if se < pe:
                break
        else:  # none of the previous spans included span
            yield span


def remove_markup(s: str, **kwargs) -> str:
    # plain_text_doc will be added to __doc__
    """Return a string with wiki markup removed/replaced."""
    return WikiText(s).plain_text(**kwargs, _is_root_node=True)
