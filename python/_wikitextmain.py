from __future__ import annotations

from bisect import insort_right
from html import unescape
from itertools import islice
from operator import attrgetter
from typing import (
    Callable,
    Iterable,
    overload,
)
from warnings import warn

from regex import (
    MULTILINE,
    Match,
    finditer,
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
    parse_to_spans,
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
    _table_to_text
)


from .wikitext_base import WikiTextBase  # Assuming wikitext_base.py is in the same package/directory.


class WikiText(WikiTextBase):
    # In subclasses of WikiText _type is used as the key for _type_to_spans
    # Therefore: self._span can be found in self._type_to_spans[self._type].
    # The following class attribute acts as a default value.
    _type = 'WikiText'

    # __slots__ are inherited implicitly, no need to redefine unless adding new ones.

    def plain_text(
        self,
        *,
        replace_templates: bool | Callable[[Template], str | None] = True,
        replace_parser_functions: bool
        | Callable[[ParserFunction], str | None] = True,
        replace_parameters=True,
        replace_tags=True,
        replace_external_links=True,
        replace_wikilinks=True,
        unescape_html_entities=True,
        replace_bolds_and_italics=True,
        replace_tables: Callable[[Table], str | None] | bool = _table_to_text,
        _is_root_node=False,
    ) -> str:
        # plain_text_doc will be added to __doc__
        """Return a plain text string representation of self."""
        if _is_root_node is False:
            s, e, m, b = self._span_data
            tts = self._inner_type_to_spans_copy()
            # Note: Here WikiText is initialized, ensuring it refers to the same underlying _lststr and _type_to_spans
            # and that it's a "WikiText" instance for its methods.
            parsed = WikiText([self._lststr[0][s:e]], tts)
            new_end = e - s
            for span_data in tts[self._type]:  # Self._type will be 'WikiText'
                if span_data[1] == new_end:
                    parsed._span_data = span_data
                    break
            else:  # self is a dead span
                parsed._span_data = [0, 0, None, bytearray()]
        else:
            tts = self._type_to_spans
            parsed = self
        lst: list[str | None] = list(parsed.string)

        def remove(b: int, e: int):
            lst[b:e] = [None] * (e - b)

        for b, e, _, _ in tts['Comment']:
            remove(b, e)

        if callable(replace_templates):
            for template in parsed.templates:
                b, e = template._span_data[:2]  # noqa
                if lst[b] is None:  # overwritten
                    continue
                lst[b] = replace_templates(template)
                remove(b + 1, e)
        elif replace_templates:
            for b, e, _, _ in tts['Template']:
                remove(b, e)

        if callable(replace_parser_functions):
            for pf in parsed.parser_functions:
                b, e = pf._span_data[:2]
                if lst[b] is None:  # already overwritten
                    continue
                lst[b] = replace_parser_functions(pf)
                remove(b + 1, e)
        elif replace_parser_functions:
            for b, e, _, _ in tts['ParserFunction']:
                remove(b, e)

        if replace_external_links:
            for el in parsed.external_links:
                if el.in_brackets:
                    b, e = el.span
                    text = el.text
                    if text is None:
                        remove(b, e)
                    else:
                        remove(b, e - 1 - len(text))
                        remove(e - 1, e)
        # replacing bold and italics should be done before wikilinks and tags
        # because removing tags and wikilinks creates invalid spans, and
        # get_bolds() will try to look into wikilinks for bold parts.
        if replace_bolds_and_italics:
            for i in parsed.get_bolds_and_italics():
                b, e = i.span
                ib, ie = i._match.span(1)  # noqa, text span
                remove(b, b + ib)
                remove(b + ie, e)
        if replace_parameters:
            for p in parsed.parameters:
                b, e = p.span
                default_start = p._shadow.find(124)
                if default_start != -1:
                    remove(b, b + default_start + 1)
                    remove(e - 3, e)
                else:
                    remove(b, e)
        if replace_tags:
            for t in parsed.get_tags():
                b, e = t.span
                cb, ce = t._match.span('contents')  # noqa
                if cb != -1:  # not a self-closing tag
                    remove(b, b + cb)
                    remove(b + ce, e)
                else:  # remove the whole self-closing tag
                    remove(b, e)
        if replace_wikilinks:
            for w in parsed.wikilinks:
                b, e = w.span
                title = w.title
                if title[:1] != ':' and (
                    title.partition(':')[2].rpartition('.')[2]
                    in KNOWN_FILE_EXTENSIONS
                ):
                    remove(b, e)  # image
                else:
                    tb, te = w._match.span(4)  # noqa, text span
                    if tb != -1:
                        remove(b, b + tb)
                        remove(b + te, e)
                    else:
                        tb, te = w._match.span(1)  # noqa, target span
                        remove(b, b + tb)
                        remove(b + te, e)

        if callable(replace_tables):
            for table in parsed.get_tables():
                b, e = table._span_data[:2]  # noqa
                if lst[b] is None:  # overwritten
                    continue
                lst[b] = replace_tables(
                    Table(''.join([c for c in lst[b:e] if c is not None]))
                )
                remove(b + 1, e)

        string = ''.join([c for c in lst if c is not None])
        if unescape_html_entities:
            string = unescape(string)
        return string

    def pformat(self, indent: str = '    ', remove_comments=False) -> str:
        """Return a pretty-print formatted version of `self.string`.

        Try to organize templates and parser functions by indenting, aligning
        at the equal signs, and adding space where appropriate.

        Note that this function will not mutate self.
        """
        ws = WS
        # Do not try to do inplace pformat. It will overwrite on some spans.
        lststr0 = self._lststr[0]
        s, e, m, b = self._span_data
        # Note: Here WikiText is initialized, ensuring it refers to the same underlying _lststr and _type_to_spans
        parsed = WikiText([lststr0[s:e]], self._inner_type_to_spans_copy())
        # Since _type_to_spans arg of WikiText has been used, parsed._span
        # is not set yet.
        span = [0, e - s, m, b[:] if b is not None else None]
        parsed._span_data = span
        parsed._type_to_spans['WikiText'] = [span]
        if remove_comments:
            for c in parsed.comments:
                del c[:]
        else:
            # Only remove comments that contain whitespace.
            for c in parsed.comments:
                if not c.contents.strip(ws):
                    del c[:]
        # First remove all current spacings.
        for template in reversed(parsed.templates):
            stripped_tl_name = template.name.strip(ws)
            template.name = (
                ' ' + stripped_tl_name + ' '
                if stripped_tl_name[0] == '{'
                else stripped_tl_name
            )
            args = template.arguments
            if not args:
                continue
            if ':' in stripped_tl_name:
                # Don't use False because we don't know for sure.
                not_a_parser_function = None
            else:
                not_a_parser_function = True
            # Required for alignment
            arg_stripped_names = [a.name.strip(ws) for a in args]
            arg_positionalities = [a.positional for a in args]
            arg_name_lengths = [
                wcswidth(n.replace('لا', '?')) if not p else 0
                for n, p in zip(arg_stripped_names, arg_positionalities)
            ]
            max_name_len = max(arg_name_lengths)
            # Format template.name.
            level = template.nesting_level
            newline_indent = '\n' + indent * level
            template.name += newline_indent
            if level == 1:
                last_comment_indent = '<!--\n-->'
            else:
                last_comment_indent = '<!--\n' + indent * (level - 2) + ' -->'
            # Special formatting for the last argument.
            last_arg = args.pop()
            last_is_positional = arg_positionalities.pop()
            last_value = last_arg.value
            last_stripped_value = last_value.strip(ws)
            if last_is_positional and last_value != last_stripped_value:
                stop_conversion = True
                if not last_value.endswith('\n' + indent * (level - 1)):
                    last_arg.value = last_value + last_comment_indent
            elif not_a_parser_function:
                stop_conversion = False
                last_arg.name = (
                    ' '
                    + arg_stripped_names.pop()
                    + ' '
                    + ' ' * (max_name_len - arg_name_lengths.pop())
                )
                last_arg.value = (
                    ' ' + last_stripped_value + '\n' + indent * (level - 1)
                )
            elif last_is_positional:
                # (last_value == last_stripped_value
                # and not_a_parser_function is not True)
                stop_conversion = True
                # Can't strip or adjust the position of the value
                # because this could be a positional argument in a template.
                last_arg.value = last_value + last_comment_indent
            else:
                stop_conversion = True
                # This is either a parser function or a keyword
                # argument in a template. In both cases the name
                # can be lstripped and the value can be rstripped.
                last_arg.name = ' ' + last_arg.name.lstrip(ws)
                if not last_value.endswith('\n' + indent * (level - 1)):
                    last_arg.value = (
                        last_value.rstrip(ws) + ' ' + last_comment_indent
                    )
            if not args:
                continue
            comment_indent = '<!--\n' + indent * (level - 1) + ' -->'
            for arg, stripped_name, positional, arg_name_len in zip(
                reversed(args),
                reversed(arg_stripped_names),
                reversed(arg_positionalities),
                reversed(arg_name_lengths),
            ):
                value = arg.value
                stripped_value = value.strip(ws)
                # Positional arguments of templates are sensitive to
                # whitespace. See:
                # https://meta.wikimedia.org/wiki/Help:Newlines_and_spaces
                if stop_conversion:
                    if not value.endswith(newline_indent):
                        arg.value += comment_indent
                elif positional and value != stripped_value:
                    stop_conversion = True
                    if not value.endswith(newline_indent):
                        arg.value += comment_indent
                elif not_a_parser_function:
                    arg.name = (
                        ' '
                        + stripped_name
                        + ' '
                        + ' ' * (max_name_len - arg_name_len)
                    )
                    arg.value = ' ' + stripped_value + newline_indent

        for func in reversed(parsed.parser_functions):
            name = func.name
            ls_name = name.lstrip(ws)
            lws = len(name) - len(ls_name)
            if lws:
                del func[2 : lws + 2]
            if ls_name.lower() in ('#tag', '#invoke', ''):
                # The 2nd argument of `tag` parser function is an exception
                # and cannot be stripped.
                # So in `{{#tag:tagname|arg1|...}}`, no whitespace should be
                # added/removed to/from arg1.
                # See: [[mw:Help:Extension:ParserFunctions#Miscellaneous]]
                # All args of #invoke are also whitespace-sensitive.
                continue
            args = func.arguments
            if not args:
                continue
            # Whitespace, including newlines, tabs, and spaces is stripped
            # from the beginning and end of all the parameters of
            # parser functions. See:
            # www.mediawiki.org/wiki/Help:Extension:ParserFunctions#
            #    Stripping_whitespace
            level = func.nesting_level
            short_indent = '\n' + indent * (level - 1)
            newline_indent = short_indent + indent
            if len(args) == 1:
                arg = args[0]
                # the first arg is both the first and last argument
                if arg.positional:
                    arg.value = (
                        newline_indent + arg.value.strip(ws) + short_indent
                    )
                else:
                    # Note that we don't add spaces before and after the
                    # '=' in parser functions because it could be part of
                    # an ordinary string.
                    arg.name = newline_indent + arg.name.lstrip(ws)
                    arg.value = arg.value.rstrip(ws) + short_indent
                continue
            # Special formatting for the first argument
            arg = args[0]
            if arg.positional:
                arg.value = (
                    newline_indent + arg.value.strip(ws) + newline_indent
                )
            else:
                arg.name = newline_indent + arg.name.lstrip(ws)
                arg.value = arg.value.rstrip(ws) + newline_indent
            # Formatting the middle arguments
            for arg in args[1:-1]:
                if arg.positional:
                    arg.value = ' ' + arg.value.strip(ws) + newline_indent
                else:
                    arg.name = ' ' + arg.name.lstrip(ws)
                    arg.value = arg.value.rstrip(ws) + newline_indent
            # Special formatting for the last argument
            arg = args[-1]
            if arg.positional:
                arg.value = ' ' + arg.value.strip(ws) + short_indent
            else:
                arg.name = ' ' + arg.name.lstrip(ws)
                arg.value = arg.value.rstrip(ws) + short_indent

        return parsed.string

    @property
    def parameters(self) -> list[Parameter]:
        """Return a list of parameter objects."""
        _lststr = self._lststr
        _type_to_spans = self._type_to_spans
        return [
            Parameter(_lststr, _type_to_spans, span, 'Parameter')
            for span in self._subspans('Parameter')
        ]

    @property
    def parser_functions(self) -> list[ParserFunction]:
        """Return a list of parser function objects."""
        _lststr = self._lststr
        _type_to_spans = self._type_to_spans
        return [
            ParserFunction(_lststr, _type_to_spans, span, 'ParserFunction')
            for span in self._subspans('ParserFunction')
        ]

    @property
    def templates(self) -> list[Template]:
        """Return a list of templates as template objects."""
        _lststr = self._lststr
        _type_to_spans = self._type_to_spans
        return [
            Template(_lststr, _type_to_spans, span, 'Template')
            for span in self._subspans('Template')
        ]

    @property
    def wikilinks(self) -> list[WikiLink]:
        """Return a list of wikilink objects."""
        _lststr = self._lststr
        _type_to_spans = self._type_to_spans
        return [
            WikiLink(_lststr, _type_to_spans, span, 'WikiLink')
            for span in self._subspans('WikiLink')
        ]

    @property
    def comments(self) -> list[Comment]:
        """Return a list of comment objects."""
        _lststr = self._lststr
        _type_to_spans = self._type_to_spans
        return [
            Comment(_lststr, _type_to_spans, span, 'Comment')
            for span in self._subspans('Comment')
        ]

    @property
    def _balanced_quotes_shadow(self) -> bytearray:
        """Return a byte array with non-markup-apostrophes removed.

        The comments at /includes/parser/Parser.php:doQuotes are helpful:
        https://github.com/wikimedia/mediawiki/blob/master/includes/parser/Parser.php
        https://phabricator.wikimedia.org/T15227#178834
        """
        bold_starts: list[int] = []
        odd_italics = False
        odd_bold_italics = False
        append_bold_start = bold_starts.append

        def process_line(line: bytes) -> bytes:
            nonlocal odd_italics, odd_bold_italics
            if odd_italics and (len(bold_starts) + odd_bold_italics) % 2:
                # one of the bold marks needs to be interpreted as italic
                first_multi_letter_word = first_space = None
                for s in bold_starts:
                    if line[s - 1] == 32:  # space
                        if first_space is None:
                            first_space = s
                        continue
                    if line[s - 2] == 32:  # space
                        line = line[:s] + b' ' + line[s + 1 :]
                        break  # first_single_letter_word
                    if first_multi_letter_word is None:
                        first_multi_letter_word = s
                        continue
                else:  # there was no first_single_letter_word
                    if first_multi_letter_word is not None:
                        line = (
                            line[:first_multi_letter_word]
                            + b'_'
                            + line[first_multi_letter_word + 1 :]
                        )
                    elif first_space is not None:
                        line = (
                            line[:first_space] + b'_' + line[first_space + 1 :]
                        )
            # reset state for the next line
            bold_starts.clear()
            odd_italics = False
            odd_bold_italics = False
            return line

        def process_apostrophes(m) -> bytes:
            nonlocal odd_italics, odd_bold_italics
            starts = m.starts(1)
            n = len(starts)
            if n == 2:  # italic
                odd_italics ^= True
                return m[0]
            if n == 3:  # bold
                append_bold_start(starts[0])
                return m[0]
            if n == 5:
                odd_bold_italics ^= True
                odd_italics ^= True
                return m[0]
            if n == 4:  # four apostrophes -> hide the first one
                s = starts[1]
                append_bold_start(s)
                return b'_' * (s - starts[0]) + m.string[s : m.end()]
            # more than 5 apostrophes -> hide the prior ones
            odd_bold_italics ^= True
            odd_italics ^= True
            s = starts[-5]
            return b'_' * (s - starts[0]) + m.string[s : m.end()]

        return bytearray(b'\n').join(
            [
                process_line(substitute_apostrophes(process_apostrophes, line))
                for line in self._shadow.splitlines()
            ]
        )

    def _bolds_italics_recurse(self, result: list, filter_cls: type | None):
        for prop in (
            'templates',
            'parser_functions',
            'parameters',
            'wikilinks',
        ):
            for e in getattr(self, prop):
                result += e.get_bolds_and_italics(
                    filter_cls=filter_cls, recursive=False
                )
        extension_tags = self._extension_tags
        if not extension_tags:
            return result
        # noinspection PyProtectedMember
        result_spans = {(*i._span_data[:2],) for i in result}
        for e in extension_tags:
            for i in e.get_bolds_and_italics(
                filter_cls=filter_cls, recursive=False
            ):
                # noinspection PyProtectedMember
                if (*i._span_data[:2],) not in result_spans:
                    result.append(i)

    @overload
    def get_bolds_and_italics(
        self, *, recursive=True, filter_cls: type[Bold]
    ) -> list[Bold]: ...

    @overload
    def get_bolds_and_italics(
        self, *, recursive=True, filter_cls: type[Italic]
    ) -> list[Italic]: ...

    @overload
    def get_bolds_and_italics(
        self, *, recursive=True, filter_cls: None = None
    ) -> list[Bold | Italic]: ...

    def get_bolds_and_italics(
        self,
        *,
        recursive=True,
        filter_cls: type[Bold] | type[Italic] | None = None,
    ) -> list[Bold | Italic] | list[Italic] | list[Bold]:
        """Return a list of bold and italic objects in self.

        This is faster than calling ``get_bolds`` and ``get_italics``
        individually.
        :keyword recursive: if True also look inside templates, parser
            functions, extension tags, etc.
        :keyword filter_cls: only return this type. Should be
            `wikitextparser.Bold` or `wikitextparser.Italic`.
            The default is None and means both bolds and italics.
        """
        result = []
        append = result.append
        _lststr = self._lststr
        s = self._span_data[0]
        type_to_spans = self._type_to_spans
        tts_setdefault = type_to_spans.setdefault
        balanced_shadow = self._balanced_quotes_shadow
        rs, re = self._content_span

        if filter_cls is None or filter_cls is Bold:
            bold_spans = tts_setdefault('Bold', [])
            get_old_bold_span = {(s[0], s[1]): s for s in bold_spans}.get
            bold_matches = list(BOLD_FINDITER(balanced_shadow, rs, re))
            for m in bold_matches:
                ms, me = m.span()
                b, e = s + ms, s + me
                old_span = get_old_bold_span((b, e))
                if old_span is None:
                    span = [b, e, None, balanced_shadow[ms:me]]
                    insort_right(bold_spans, span)
                else:
                    span = old_span
                append(Bold(_lststr, type_to_spans, span, 'Bold'))
            if recursive:
                self._bolds_italics_recurse(result, filter_cls)
                if filter_cls is Bold:
                    result.sort(key=attrgetter('_span_data'))
                    return result
            elif filter_cls is Bold:
                return result
        else:  # filter_cls is Italic
            bold_matches = BOLD_FINDITER(balanced_shadow, rs, re)

        # filter_cls is None or filter_cls is Italic

        # remove bold tokens before searching for italics
        for m in bold_matches:
            ms, me = m.span()
            cs, ce = m.span(1)  # content
            balanced_shadow[ms:cs] = b'_' * (cs - ms)
            balanced_shadow[ce:me] = b'_' * (me - ce)

        italic_spans = tts_setdefault('Italic', [])
        get_old_italic_span = {(s[0], s[1]): s for s in italic_spans}.get
        for m in ITALIC_FINDITER(balanced_shadow, rs, re):
            ms, me = m.span()
            b, e = span = s + ms, s + me
            old_span = get_old_italic_span(span)
            if old_span is None:
                span = [b, e, None, balanced_shadow[ms:me]]
                insort_right(italic_spans, span)
            else:
                span = old_span
            append(
                Italic(_lststr, type_to_spans, span, 'Bold', me != m.end(1))
            )
        if recursive and filter_cls is Italic:
            self._bolds_italics_recurse(result, filter_cls)
            result.sort(key=attrgetter('_span_data'))
            return result
        if filter_cls is None:  # all Italics are appended after Bolds
            result.sort(key=attrgetter('_span_data'))
        return result

    def get_bolds(self, recursive=True) -> list[Bold]:
        """Return bold parts of self.

        :param recursive: if True also look inside templates, parser functions,
            extension tags, etc.
        """
        return self.get_bolds_and_italics(filter_cls=Bold, recursive=recursive)

    def get_italics(self, recursive=True) -> list[Italic]:
        """Return italic parts of self.

        :param recursive: if True also look inside templates, parser functions,
            extension tags, etc.
        """
        return self.get_bolds_and_italics(
            filter_cls=Italic, recursive=recursive
        )

    @property
    def _ext_link_shadow(self) -> bytearray:
        """Replace the invalid chars of SPAN_PARSER_TYPES with b'_'.

        For comments, all characters are replaced, but for ('Template',
        'ParserFunction', 'Parameter') only invalid characters are replaced.
        """
        ss, se, _, _ = self._span_data
        byte_array = bytearray(self._lststr[0][ss:se], 'ascii', 'replace')
        subspans = self._subspans
        for s, e, _, _ in subspans('Comment'):
            byte_array[s - ss : e - ss] = (e - s) * b'_'
        for s, e, _, _ in subspans('WikiLink'):
            byte_array[s - ss : e - ss] = (e - s) * b' '
        for type_ in 'Template', 'ParserFunction', 'Parameter':
            for s, e, _, _ in subspans(type_):
                byte_array[s - ss : e - ss] = INVALID_EL_TPP_CHRS_SUB(
                    b' ', byte_array[s:e]
                )
        return byte_array

    @property
    def external_links(self) -> list[ExternalLink]:
        """Return a list of found external link objects.

        Note:
            Templates adjacent to external links are considered part of the
            link. In reality, this depends on the contents of the template:

            >>> WikiText(
            ...    'http://example.com{{dead link}}'
            ...).external_links[0].url
            'http://example.com{{dead link}}'

            >>> WikiText(
            ...    '[http://example.com{{space template}} text]'
            ...).external_links[0].url
            'http://example.com{{space template}}'
        """
        external_links: list[ExternalLink] = []
        external_links_append = external_links.append
        type_to_spans = self._type_to_spans
        lststr = self._lststr
        ss, se, _, _ = self._span_data
        spans = type_to_spans.setdefault('ExternalLink', [])
        span_tuple_to_span_get = {(s[0], s[1]): s for s in spans}.get
        el_shadow = self._ext_link_shadow

        def _extract(start, end):
            for m in EXTERNAL_LINK_FINDITER(el_shadow, start, end):
                ms, me = m.span()
                span = s, e, _, _ = [ss + ms, ss + me, None, el_shadow[ms:me]]
                old_span = span_tuple_to_span_get((s, e))
                if old_span is None:
                    insort_right(spans, span)
                else:
                    span = old_span
                external_links_append(
                    ExternalLink(lststr, type_to_spans, span, 'ExternalLink')
                )

        for s, e, _, _ in self._subspans('ExtensionTag'):
            _extract(s, e)
            el_shadow[s:e] = (e - s) * b' '
        _extract(None, None)
        return external_links

    def _section_spans_to_sections(
        self, section_spans: list[tuple[int, int]], shadow: bytearray
    ) -> list[Section]:
        type_to_spans = self._type_to_spans
        sections: list[Section] = []
        sections_append = sections.append
        ss, se, _, ba = self._span_data
        type_spans = type_to_spans.setdefault('Section', [])
        span_tuple_to_span = {(s[0], s[1]): s for s in type_spans}.get
        lststr = self._lststr
        for ms, me in section_spans:
            s, e = ss + ms, ss + me
            old_span = span_tuple_to_span((s, e))
            if old_span is None:
                span = [s, e, None, shadow[ms:me]]
                insort_right(type_spans, span)
            else:
                span = old_span
            sections_append(Section(lststr, type_to_spans, span, 'Section'))
        return sections

    @property
    def sections(self) -> list[Section]:
        """Return self.get_sections(include_subsections=True)."""
        return self.get_sections()

    def get_sections(
        self,
        *args,
        include_subsections=True,
        level=None,
        top_levels_only=False,
    ) -> list[Section]:
        """Return a list of sections in current wikitext.

        The first section will always be the lead section, even if it is an
        empty string.

        :param include_subsections: If true, include the text of subsections
            in each Section object.
        :param level: Only return sections where section.level == level.
            Return all levels if None (default).
        :param top_levels_only: Only return sections that are not subsections
            of other sections. In this mode, level cannot be specified and
            `include_subsections` must be True.
        """
        if args:
            warn(
                'calling get_sections with positional arguments is deprecated',
                DeprecationWarning,
                2,
            )
            if len(args) == 1:
                include_subsections = args[0]
            else:
                include_subsections, level = args

        shadow = self._shadow
        if top_levels_only:
            assert level is None
            assert include_subsections
            full_match: Match[bytes] = SECTIONS_TOP_LEVELS_ONLY(shadow)  # type: ignore
            return self._section_spans_to_sections(
                full_match.spans('section'), shadow
            )

        full_match = SECTIONS_FULLMATCH(shadow)  # type: ignore
        section_spans = full_match.spans('section')
        levels = [len(eq) for eq in full_match.captures('equals')]

        if include_subsections:
            z = [*zip(section_spans, levels)]
            for pi, ((ps, pe), pl) in enumerate(islice(z, 1, None), 1):
                for (ss, se), sl in islice(z, pi + 1, None):
                    if sl > pl:
                        section_spans[pi] = (ps, se)
                    else:
                        break

        if level is not None:
            section_spans = [
                ss for (ss, lvl) in zip(section_spans, levels) if lvl == level
            ]

        return self._section_spans_to_sections(section_spans, shadow)

    @property
    def tables(self) -> list[Table]:
        """Return a list of all tables."""
        return self.get_tables(True)

    def get_tables(self, recursive=False) -> list[Table]:
        """Return tables. Include nested tables if `recursive` is `True`."""
        type_to_spans = self._type_to_spans
        lststr = self._lststr
        shadow_copy = self._shadow[:]
        ss, se, _, _ = self._span_data
        spans = type_to_spans.setdefault('Table', [])
        spans_append = spans.append
        skip_self_span = self._type == 'Table'
        span_tuple_to_span_get = {(s[0], s[1]): s for s in spans}.get
        return_spans = []
        return_spans_append = return_spans.append
        shadow_copy_copy = shadow_copy[:]

        def extract_tables_from_shadow():
            m = True
            while m:
                m = False
                for m in TABLE_FINDITER(shadow_copy, skip_self_span):
                    ms, me = m.span()
                    # Ignore leading whitespace using len(m[1]).
                    s, e = ss + ms, ss + me
                    old_span = span_tuple_to_span_get((s, e))
                    if old_span is None:
                        span = [s, e, None, shadow_copy_copy[ms:me]]
                        spans_append(span)
                        return_spans_append(span)
                    else:
                        return_spans_append(old_span)
                    shadow_copy[ms:me] = b'_' * (me - ms)

        extract_tables_from_shadow()

        for tag in self._extension_tags:
            if tag.name in _parsable_tag_extensions:
                shadow_copy = tag._shadow[:]
                shadow_copy_copy = shadow_copy[:]
                # noinspection PyProtectedMember
                ss = tag._span_data[0]
                extract_tables_from_shadow()

        return_spans.sort()
        spans.sort()
        if not recursive:
            return_spans = _outer_spans(return_spans)
        return [
            Table(lststr, type_to_spans, sp, 'Table') for sp in return_spans
        ]

    @property
    def _lists_shadow_ss(self) -> tuple[bytearray, int]:
        """Return appropriate shadow and its offset to be used by `lists`."""
        return self._shadow, self._span_data[0]

    def get_lists(
        self, pattern: str | Iterable[str] = (r'\#', r'\*', '[:;]')
    ) -> list[WikiList]:
        r"""Return a list of WikiList objects.

        :param pattern: The starting pattern for list items.
            If pattern is not None, it will be passed to the regex engine,
            so remember to escape the `*` character. Examples:

                - `'\#'` means top-level ordered lists
                - `'\#\*'` means unordred lists inside an ordered one
                - Currently definition lists are not well supported, but you
                    can use `'[:;]'` as their pattern.

            Tips and tricks:

                Be careful when using the following patterns as they will
                probably cause malfunction in the `sublists` method of the
                resultant List. (However don't worry about them if you are
                not going to use the `sublists` or `List.get_lists` method.)

                - Use `'\*+'` as a pattern and nested unordered lists will be
                    treated as flat.
                - Use `'\*\s*'` as pattern to rtstrip `items` of the list.
        """
        if isinstance(pattern, str):
            patterns = (pattern,)
        else:
            patterns = pattern
        lists = []
        lists_append = lists.append
        lststr = self._lststr
        type_to_spans = self._type_to_spans
        spans = type_to_spans.setdefault('WikiList', [])
        span_tuple_to_span_get = {(s[0], s[1]): s for s in spans}.get
        shadow, ss = self._lists_shadow_ss
        if any(':' in pattern for pattern in patterns):
            for m in EXTERNAL_LINK_FINDITER(shadow):
                s, e = m.span()
                shadow[s:e] = b'_' * (e - s)
        for pattern in patterns:
            for m in finditer(
                LIST_PATTERN_FORMAT.replace(b'{pattern}', pattern.encode(), 1),
                shadow,
                MULTILINE,
            ):
                ms, me = m.span()
                s, e = ss + ms, ss + me
                old_span = span_tuple_to_span_get((s, e))
                if old_span is None:
                    span = [s, e, None, shadow[ms:me]]
                    insort_right(spans, span)
                else:
                    span = old_span
                lists_append(
                    WikiList(
                        lststr, pattern, m, type_to_spans, span, 'WikiList'
                    )
                )
        lists.sort(key=attrgetter('_span_data'))
        return lists

    @property
    def _extension_tags(self):
        lststr = self._lststr
        type_to_spans = self._type_to_spans
        return [
            Tag(lststr, type_to_spans, span, 'ExtensionTag')
            for span in self._subspans('ExtensionTag')
        ]

    def get_tags(self, name=None) -> list[Tag]:
        """Return all tags with the given name."""
        lststr = self._lststr
        type_to_spans = self._type_to_spans
        if name:
            if name in _tag_extensions:
                string = lststr[0]
                return [
                    Tag(lststr, type_to_spans, span, 'ExtensionTag')
                    for span in type_to_spans['ExtensionTag']
                    if match(r'<' + name + r'\b', string, pos=span[0])
                    is not None
                ]
            tags: list[Tag] = []
        else:
            # There is no name, add all extension tags. Before using shadow.
            tags = self._extension_tags
        tags_append = tags.append
        # Get the left-most start tag, match it to right-most end tag
        # and so on.
        ss = self._span_data[0]
        byte_array = bytearray(self.string, 'ascii', 'replace')
        if name:
            # There is a name but it is not in TAG_EXTENSIONS.
            reversed_start_matches = reversed(
                [
                    m
                    for m in rc(
                        START_TAG_PATTERN.replace(
                            rb'{name}', rb'(?P<name>' + name.encode() + rb')'
                        )
                    ).finditer(byte_array)
                ]
            )
            end_search = rc(
                END_TAG_PATTERN.replace(b'{name}', name.encode())
            ).search
        else:
            reversed_start_matches = reversed(
                [m for m in NAME_CAPTURING_HTML_START_TAG_FINDITER(byte_array)]
            )
        ba_copy = byte_array[:]
        spans = type_to_spans.setdefault('Tag', [])
        span_tuple_to_span_get = {(s[0], s[1]): s for s in spans}.get
        spans_append = spans.append
        for start_match in reversed_start_matches:
            if start_match[0].rstrip(b' \t\n>')[-1] == 47:  # ord('/') == 47
                # Self-closing tag. Don't look for the end tag.
                # todo: some self-closing tags actually should be treated
                # as start tag in HTML5, see:
                # https://stackoverflow.com/questions/3558119/
                ms, me = start_match.span()
                span = [ss + ms, ss + me, None, ba_copy[ms:me]]
            else:
                # look for the end-tag
                sms, sme = start_match.span()
                if name:
                    # the end_search is already available
                    end_match = end_search(ba_copy, sme)  # type: ignore
                else:
                    # build end_search according to start tag name
                    end_match = search(
                        END_TAG_PATTERN.replace(
                            b'{name}', start_match['name']
                        ),
                        ba_copy,
                        pos=sme,
                    )
                if end_match:
                    ems, eme = end_match.span()
                    ba_copy[ems:eme] = b'_' * (eme - ems)
                    span = [ss + sms, ss + eme, None, byte_array[sms:eme]]
                else:
                    # Assume start-only tag.
                    span = [ss + sms, ss + sme, None, ba_copy[sms:sme]]
            old_span = span_tuple_to_span_get((span[0], span[1]))
            if old_span is None:
                spans_append(span)
            else:
                span = old_span
            tags_append(Tag(lststr, type_to_spans, span, 'Tag'))
        spans.sort()
        tags.sort(key=attrgetter('_span_data'))
        return tags

    def parent(self, type_: str | None = None) -> WikiText | None:
        """Return None (The parent of the root node is None)."""
        return None

    def ancestors(self, type_: str | None = None) -> list[WikiText]:
        """Return [] (the root node has no ancestors)."""
        return []
