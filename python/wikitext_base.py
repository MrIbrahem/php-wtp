from __future__ import annotations

from bisect import bisect_left, bisect_right, insort_right

from typing import (
    MutableSequence,
)
from ._spans import (
    TypeToSpans,
    parse_to_spans,
)

from ._wikitext_utils import (
    SPAN_PARSER_TYPES,
    DEAD_SPAN,
)


class WikiTextBase:
    # In subclasses of WikiText _type is used as the key for _type_to_spans
    # Therefore: self._span can be found in self._type_to_spans[self._type].
    # The following class attribute acts as a default value.
    _type = 'WikiTextBase'

    __slots__ = '_type_to_spans', '_lststr', '_span_data'

    def __init__(
        self,
        string: MutableSequence[str] | str,
        _type_to_spans: TypeToSpans | None = None,
    ) -> None:
        """Initialize the object.

        Set the initial values for self._lststr, self._type_to_spans.

        :param string: The string to be parsed or a list containing the string
            of the parent object.
        :param _type_to_spans: If the lststr is already parsed, pass its
            _type_to_spans property as _type_to_spans to avoid parsing it
            again.
        """
        if _type_to_spans is not None:
            self._type_to_spans = _type_to_spans
            self._lststr: MutableSequence[str] = string  # type: ignore
            return
        self._lststr: MutableSequence[str] = [string]  # type: ignore
        byte_array = bytearray(string, 'ascii', 'replace')  # type: ignore
        span = self._span_data = [0, len(string), None, byte_array]
        _type = self._type
        if _type not in SPAN_PARSER_TYPES:
            type_to_spans = self._type_to_spans = parse_to_spans(byte_array)
            type_to_spans[_type] = [span]
        else:
            # In SPAN_PARSER_TYPES, we can't pass the original byte_array to
            # parser to generate the shadow because it will replace the whole
            # string with '_'. Also, we can't just modify it before passing
            # because the generated _type_to_spans will lack self._span.
            # As a workaround we can add the missed span after parsing.
            if type(self) is Parameter:  # Note: This will need to be `type(self) is <subclass>` if Parameter doesn't inherit from WikiTextBase, but it should.
                head = byte_array[:2]
                tail = byte_array[-2:]
                byte_array[:2] = b'__'
                byte_array[-2:] = b'__'

                type_to_spans = parse_to_spans(byte_array)
                type_to_spans[_type].insert(0, span)
                self._type_to_spans = type_to_spans

                byte_array[:2] = head
                byte_array[-2:] = tail
            else:
                head = byte_array[0]
                tail = byte_array[-1]
                byte_array[0] = 3
                byte_array[-1] = 32

                type_to_spans = parse_to_spans(byte_array)
                type_to_spans[_type].insert(0, span)
                self._type_to_spans: TypeToSpans = type_to_spans

                byte_array[0] = head
                byte_array[-1] = tail

    def __str__(self) -> str:
        return self.string

    def __repr__(self) -> str:
        return f'{type(self).__name__}({repr(self.string)})'

    def __contains__(self, value: str | 'WikiTextBase') -> bool:
        """Return True if parsed_wikitext is inside self. False otherwise.

        Also self and parsed_wikitext should belong to the same parsed
        wikitext object for this function to return True.
        """
        # Is it useful (and a good practice) to also accepts str inputs
        # and check if self.string contains it?
        if isinstance(value, str):
            return value in self.string
        # isinstance(value, WikiTextBase)
        if self._lststr is not value._lststr:
            return False
        ps, pe, _, _ = value._span_data
        ss, se, _, _ = self._span_data
        if ss <= ps and se >= pe:
            return True
        return False

    def __len__(self):
        s, e, _, _ = self._span_data
        return e - s

    def __call__(
        self,
        start: int,
        stop: int | None = False,
        step: int | None = None,
    ) -> str:
        """Return `self.string[start]` or `self.string[start:stop]`.

        Return self.string[start] if stop is False.
        Otherwise return self.string[start:stop:step].
        """
        if stop is False:
            if start >= 0:
                return self._lststr[0][self._span_data[0] + start]
            return self._lststr[0][self._span_data[1] + start]
        s, e, _, _ = self._span_data
        return self._lststr[0][
            s
            if start is None
            else (s + start if start >= 0 else e + start) : e
            if stop is None
            else (s + stop if stop >= 0 else e + stop) : step
        ]

    def _check_index(self, key: slice | int) -> tuple[int, int]:
        """Return adjusted start and stop index as tuple.

        Used in  __setitem__ and __delitem__.
        """
        ss, se, _, _ = self._span_data
        if isinstance(key, int):
            if key < 0:
                key += se - ss
                if key < 0:  # type: ignore
                    raise IndexError('index out of range')
            elif key >= se - ss:
                raise IndexError('index out of range')
            start = ss + key
            return start, start + 1
        # isinstance(key, slice)
        if key.step is not None:
            raise NotImplementedError(
                'step is not implemented for string setter.'
            )
        start = key.start or 0
        stop = key.stop
        if start < 0:
            start += se - ss
            if start < 0:
                raise IndexError('start index out of range')
        if stop is None:
            stop = se - ss
        elif stop < 0:
            stop += se - ss
        if start > stop:
            raise IndexError(
                'stop index out of range or start is after the stop'
            )
        return start + ss, stop + ss

    def __setitem__(self, key: slice | int, value: str) -> None:
        """Set a new string for the given slice or character index.

        Use this method instead of calling `insert` and `del` consecutively.
        By doing so only one of the `_insert_update` and
        `_shrink_update` functions will be called and the performance
        will improve.
        """
        abs_start, abs_stop = self._check_index(key)
        # Update lststr
        lststr = self._lststr
        lststr0 = lststr[0]
        lststr[0] = lststr0[:abs_start] + value + lststr0[abs_stop:]
        # Set the length of all subspans to zero because
        # they are all being replaced.
        self._close_subspans(abs_start, abs_stop)
        # Update the other spans according to the new length.
        val_ba = bytearray(value, 'ascii', 'replace')
        len_change = len(value) + abs_start - abs_stop
        if len_change > 0:
            self._insert_update(abs_start, len_change)
        elif len_change < 0:
            self._del_update(
                rmstart=abs_stop + len_change,
                rmstop=abs_stop,  # new stop
            )  # old stop
        # Add the newly added spans contained in the value.
        type_to_spans = self._type_to_spans
        for type_, value_spans in parse_to_spans(val_ba).items():
            tts = type_to_spans[type_]
            for s, e, m, ba in value_spans:
                try:
                    insort_right(tts, [abs_start + s, abs_start + e, m, ba])
                except TypeError:
                    # already exists which has lead to comparing Matches
                    continue

    def __delitem__(self, key: slice | int) -> None:
        """Remove the specified range or character from self.string.

        Note: If an operation involves both insertion and deletion, it'll be
        safer to use the `insert` function first. Otherwise there is a
        possibility of insertion into the wrong spans.
        """
        start, stop = self._check_index(key)
        lststr = self._lststr
        lststr0 = lststr[0]
        lststr[0] = lststr0[:start] + lststr0[stop:]
        # Update spans
        self._del_update(start, stop)

    # Todo: def __add__(self, other) and __radd__(self, other)

    def insert(self, index: int, string: str) -> None:
        """Insert the given string before the specified index.

        This method has the same effect as ``self[index:index] = string``;
        it only avoids some condition checks as it rules out the possibility
        of the key being an slice, or the need to shrink any of the sub-spans.
        """
        ss, se, _, _ = self._span_data
        lststr = self._lststr
        lststr0 = lststr[0]
        if index < 0:
            index += se - ss
            if index < 0:
                index = 0
        elif index > se - ss:  # Note that it is not >=. Index can be new.
            index = se - ss
        index += ss
        # Update lststr
        lststr[0] = lststr0[:index] + string + lststr0[index:]
        string_len = len(string)
        # Update spans
        self._insert_update(index=index, length=string_len)
        # Remember newly added spans by the string.
        type_to_spans = self._type_to_spans
        byte_array = bytearray(string, 'ascii', 'replace')
        for type_, spans in parse_to_spans(byte_array).items():
            for s, e, _, _ in spans:
                insort_right(
                    type_to_spans[type_],
                    [index + s, index + e, None, byte_array],
                )

    @property
    def span(self) -> tuple:
        """Return the span of self relative to the start of the root node."""
        return (*self._span_data[:2],)

    @property
    def string(self) -> str:
        """Return str(self). Support get, set, and delete operations.

        getter and deleter: Note that this will overwrite the current string,
            emptying any object that points to the old string.
        """
        start, end, _, _ = self._span_data
        return self._lststr[0][start:end]

    @string.setter
    def string(self, newstring: str) -> None:
        self[:] = newstring

    @string.deleter
    def string(self) -> None:
        del self[:]

    def _subspans(self, type_: str) -> list[list[int]]:
        """Return all the sub-span including self._span."""
        return self._type_to_spans[type_]

    def _close_subspans(self, start: int, stop: int) -> None:
        """Close all sub-spans of (start, stop)."""
        ss, se, _, _ = self._span_data
        for spans in self._type_to_spans.values():
            b = bisect_left(spans, [start])
            for i, (s, e, _, _) in enumerate(
                spans[b : bisect_right(spans, [stop], b)]
            ):
                if e <= stop:
                    if ss != s or se != e:
                        spans.pop(i + b)[:] = DEAD_SPAN
                        b -= 1

    def _del_update(self, rmstart: int, rmstop: int) -> None:
        """Update self._type_to_spans according to the removed span."""
        # Note: The following algorithm won't work correctly if spans
        # are not sorted.
        # Note: No span should be removed from _type_to_spans.
        rmlength = rmstop - rmstart
        for spans in self._type_to_spans.values():
            i = len(spans) - 1
            while i >= 0:
                # todo update byte_array
                s, e, _, b = span = spans[i]
                if rmstop <= s:
                    # rmstart <= rmstop <= s <= e
                    # todo
                    span[:] = s - rmlength, e - rmlength, None, None
                    i -= 1
                    continue
                break  # pragma: no cover
            else:
                continue  # pragma: no cover
            while True:
                if rmstart <= s:
                    if rmstop < e:
                        # rmstart < s <= rmstop < e
                        # todo: update byte_array instead
                        span[:] = rmstart, e - rmlength, None, None
                        i -= 1
                        if i < 0:
                            break
                        s, e, _, _ = span = spans[i]
                        continue
                    # rmstart <= s <= e < rmstop
                    spans.pop(i)[:] = DEAD_SPAN
                    i -= 1
                    if i < 0:
                        break
                    s, e, _, _ = span = spans[i]
                    continue
                break  # pragma: no cover
            while i >= 0:
                if e <= rmstart:
                    # s <= e <= rmstart <= rmstop
                    i -= 1
                    if i < 0:
                        break
                    s, e, _, _ = span = spans[i]
                    continue
                # s <= rmstart <= rmstop <= e
                span[1] -= rmlength
                span[2] = None
                # todo: update bytearray instead
                span[3] = None
                i -= 1
                if i < 0:
                    break
                s, e, _, _ = span = spans[i]
                continue

    def _insert_update(self, index: int, length: int) -> None:
        """Update self._type_to_spans according to the added length.

        Warning: If an operation involves both _shrink_update and
        _insert_update, you might wanna consider doing the
        _insert_update before the _shrink_update as this function
        can cause data loss in self._type_to_spans.
        """
        self_span = ss, se, _, _ = self._span_data
        for span_type, spans in self._type_to_spans.items():
            for span in spans:
                s0, s1, _, _ = span
                if index < s1 or s1 == index == se:
                    span[1] += length
                    span[3] = None  # todo: update instead
                    # index is before s0, or at s0 but span is not a parent
                    if index < s0 or (
                        s0 == index
                        and self_span is not span
                        and span_type != 'WikiText'  # This needs to be 'WikiTextBase' now or the actual subclass name
                    ):
                        span[0] += length

    def _nesting_level(self, parent_types) -> int:
        ss, se, _, _ = self._span_data
        level = 0
        type_to_spans = self._type_to_spans
        for type_ in parent_types:
            spans = type_to_spans[type_]
            for s, e, _, _ in spans[: bisect_right(spans, [ss + 1])]:
                if se <= e:
                    level += 1
        return level

    @property
    def _content_span(self) -> tuple[int, int]:
        # return content_start, self_len, self_end
        return 0, len(self)

    @property
    def _shadow(self) -> bytearray:
        """Return a copy of self.string with specific sub-spans replaced.

        Comments blocks are replaced by spaces. Other sub-spans are replaced
        by underscores.

        The replaced sub-spans are: (
            'Template', 'WikiLink', 'ParserFunction', 'ExtensionTag',
            'Comment',
        )

        This function is called upon extracting tables or extracting the data
        inside them.
        """
        ss, se, m, cached_shadow = span_data = self._span_data
        if cached_shadow is not None:
            return cached_shadow
        shadow = span_data[3] = bytearray(
            self._lststr[0][ss:se], 'ascii', 'replace'
        )
        if self._type in SPAN_PARSER_TYPES:
            cs, ce = self._content_span
            head = shadow[:cs]
            tail = shadow[ce:]
            shadow[:cs] = b'_' * cs
            shadow[ce:] = b'_' * len(tail)
            parse_to_spans(shadow)
            shadow[:cs] = head
            shadow[ce:] = tail
        else:
            parse_to_spans(shadow)
        return shadow

    def _inner_type_to_spans_copy(self) -> TypeToSpans:
        """Create the arguments for the parse function used in pformat method.

        Only return sub-spans and change them to fit the new scope, i.e self.string.
        """
        ss, se, _, _ = self._span_data
        return {
            type_: [
                [s - ss, e - ss, m, ba[:] if ba is not None else None]
                for s, e, m, ba in spans[
                    bisect_right(spans, [ss]) : bisect_right(spans, [se])
                ]
            ]
            for type_, spans in self._type_to_spans.items()
        }
