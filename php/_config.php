<?php

declare(strict_types=1);

namespace Wtp\Parser\_config;

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة collections في Python.
 * المرجع: https://docs.python.org/3/library/collections.html#collections.defaultdict
 *
 * `defaultdict` provides a default value for a key that does not exist.
 * In PHP, you can achieve similar behavior by checking if a key exists before accessing it,
 * or by implementing a custom class that extends `ArrayAccess`.
 * For simple use cases (like `_pattern` where it's `_defaultdict(list)`),
 * initializing an empty array `[]` when a key is not found is sufficient.
 */

/**
 * TODO: هذه الوظيفة بحاجة إلى تحويل يدوي من مكتبة typing في Python.
 * المرجع: https://docs.python.org/3/library/typing.html#typing.Iterable
 *
 * `Iterable` is a type hint. In PHP, `iterable` pseudo-type can be used, or `array`.
 */


/**
 * Create a Trie out of a list of words and return an atomic regex pattern.
 *
 * The corresponding Regex should match much faster than a simple Regex union.
 *
 * @param iterable<string> $strings
 * @return array<string, mixed>
 */
function _plant_trie(iterable $strings): array
{
    // plant the trie
    $trie = [];
    foreach ($strings as $string) {
        $d = &$trie; // Use reference to modify the original trie
        foreach (str_split($string) as $char) {
            if (!isset($d[$char])) {
                $d[$char] = [];
            }
            $d = &$d[$char]; // Move deeper into the trie
        }
        $d[''] = null; // EOS (End Of String marker)
    }
    return $trie;
}


/**
 * Convert a trie to a regex pattern.
 *
 * @param array<string, mixed> $trie
 * @return string
 */
function _pattern(array $trie): string
{
    $optional = false;
    if (array_key_exists('', $trie)) {
        if (count($trie) == 1) {
            return '';
        }
        $optional = true;
        unset($trie['']); // Remove EOS marker for processing
    }

    // Mimic defaultdict(list)
    $subpattern_to_chars = []; // This will map subpatterns to lists of characters

    foreach ($trie as $char => $sub_trie) {
        $subpattern = _pattern($sub_trie); // Recursive call
        if (!isset($subpattern_to_chars[$subpattern])) {
            $subpattern_to_chars[$subpattern] = [];
        }
        $subpattern_to_chars[$subpattern][] = $char;
    }

    $alts = [];
    foreach ($subpattern_to_chars as $subpattern => $chars) {
        if (count($chars) == 1) {
            $alts[] = preg_quote($chars[0], '/') . $subpattern;
        } else {
            rsort($chars); // Sort characters in reverse (Python's behavior for ranges)
            $alts[] = '[' . preg_quote(implode('', $chars), '/') . ']' . $subpattern;
        }
    }

    if (count($alts) == 1) {
        $result = $alts[0];
        if ($optional) {
            // Check if result is a single character
            // Note: `strlen($result)` for a single character like `a` is 1.
            // For `[a-z]`, it's 5. For `\d`, it's 2.
            // This logic needs to consider if it's a simple character class.
            // A more robust check might involve parsing the regex fragment.
            // For now, let's assume if it's a single raw character (no escaping/brackets)
            // or a simple escaped character, its length would be small.
            // The original Python uses `len(result) == 1` which is specific to character length.
            // For PHP, let's simplify to `(?:...)` for any optional single result.
            $result = '(?:' . $result . ')?+';
        }
    } else {
        rsort($alts); // Sort alternatives in reverse
        $result = '(?>' . implode('|', $alts) . ')'; // Atomic group for performance
        if ($optional) {
            $result .= '?+';
        }
    }
    return $result;
}


/**
 * Convert words to a regex pattern that matches any of them.
 *
 * @param iterable<string> $words
 * @return string Regex pattern as a string (PHP does not have `bytes` type directly for regex patterns).
 */
function regex_pattern(iterable $words): string
{
    // The Python code returns `bytes`. In PHP, regex patterns are strings.
    return _pattern(_plant_trie($words));
}


// Contents of the some of the extension tags can be parsed as wikitext.
// For example, templates are valid inside the poem tag:
//    <poem>{{text|Hi!}}</poem>
// But not within math or source or ...
// for more information about the <categorytree> tag see:
// https://www.mediawiki.org/wiki/Extension:CategoryTree#The_.7B.7B.23categorytree.7D.7D_parser_function
const _parsable_tag_extensions = [
    'categorytree',
    'gallery',
    'imagemap',
    'includeonly',
    'indicator',
    'inputbox',
    'noinclude',
    'onlyinclude',
    'poem',
    'ref',
    'references',
    'section',
];

// For a complete list of extension tags on your wiki, see the
// "Parser extension tags" section at the end of [[Special:Version]].
// <templatedata> and <includeonly> were manually added to the following lists.
// A simple trick to find out if a tag should be listed here or not is as
// follows:
// Create the {{text}} template in your wiki (You can copy the source code from
// English Wikipedia). Then save the following in a test page:
// {{text|0<tagname>1}}2</tagname>3}}4
// If the ending braces in the rendered result appear between 3 and 4, then
// `tagname` is not an extension tag (e.g. <small>). Otherwise, i.e. if those
// braces appear between 1 and 2 or completely don't show up, `tagname` is
// a tag extension (e.g.: <pre>).
const _unparsable_tag_extensions = [
    'ce',
    'charinsert',
    'chem',
    'graph',
    'hiero',
    'languages',    // Extension:Translate
    'mapframe',
    'maplink',
    'math',
    'nowiki',
    'pagelist',
    'pagequality',
    'pages',
    'pre',
    'score',
    'source',
    'syntaxhighlight',
    'templatedata',
    'templatestyles',
    'timeline',
];

/**
 * Union of parsable and unparsable tag extensions.
 * @return array<string>
 */
function _tag_extensions(): array
{
    return array_merge(_parsable_tag_extensions, _unparsable_tag_extensions);
}


// Copied from DefaultSettings.php
// https://phabricator.wikimedia.org/source/mediawiki/browse/master/includes/DefaultSettings.php
// See also: https://www.mediawiki.org/wiki/Help:Links#External_links
const _bare_external_link_schemes = [
    'bitcoin:',
    'ftp://',
    'ftps://',
    'geo:',
    'git://',
    'gopher://',
    'http://',
    'https://',
    'irc://',
    'ircs://',
    'magnet:',
    'mailto:',
    'mms://',
    'news:',
    'nntp://',
    'redis://',
    'sftp://',
    'sip:',
    'sips:',
    'sms:',
    'ssh://',
    'svn://',
    'tel:',
    'telnet://',
    'urn:',
    'worldwind://',
    'xmpp:',    // '//'
];

// generated using dev/html_tag_names.py
const _valid_html_tag_names = [
    's',
    'ins',
    'code',
    'b',
    'ol',
    'i',
    'h5',
    'th',
    'dt',
    'td',
    'wbr',
    'div',
    'big',
    'p',
    'small',
    'h4',
    'tt',
    'span',
    'font',
    'ruby',
    'h3',
    'dfn',
    'rb',
    'li',
    'h1',
    'cite',
    'dl',
    'rtc',
    'em',
    'q',
    'h2',
    'samp',
    'strike',
    'time',
    'blockquote',
    'bdi',
    'del',
    'br',
    'rp',
    'hr',
    'abbr',
    'sub',
    'u',
    'kbd',
    'table',
    'rt',
    'dd',
    'var',
    'ul',
    'tr',
    'center',
    'data',
    'strong',
    'mark',
    'h6',
    'bdo',
    'caption',
    'sup',
];

/**
 * Regex pattern for HTML tag names.
 * @return string
 */
function _HTML_TAG_NAME(): string
{
    return regex_pattern(_valid_html_tag_names) . '\\b';
}

const _parser_functions = [
    'ARTICLEPAGENAME',
    'ARTICLEPAGENAMEE',
    'ARTICLESPACE',
    'ARTICLESPACEE',
    'BASEPAGENAME',
    'BASEPAGENAMEE',
    'CASCADINGSOURCES',
    'CONTENTLANG',
    'CONTENTLANGUAGE',
    'CURRENTDAY',
    'CURRENTDAY2',
    'CURRENTDAYNAME',
    'CURRENTDOW',
    'CURRENTHOUR',
    'CURRENTMONTH',
    'CURRENTMONTH1',
    'CURRENTMONTHABBREV',
    'CURRENTMONTHNAME',
    'CURRENTMONTHNAMEGEN',
    'CURRENTTIME',
    'CURRENTTIMESTAMP',
    'CURRENTVERSION',
    'CURRENTWEEK',
    'CURRENTYEAR',
    'DEFAULTCATEGORYSORT',
    'DEFAULTSORT',
    'DEFAULTSORTKEY',
    'DIRECTIONMARK',
    'DIRMARK',
    'DISPLAYTITLE',
    'FULLPAGENAME',
    'FULLPAGENAMEE',
    'LOCALDAY',
    'LOCALDAY2',
    'LOCALDAYNAME',
    'LOCALDOW',
    'LOCALHOUR',
    'LOCALMONTH',
    'LOCALMONTH1',
    'LOCALMONTHABBREV',
    'LOCALMONTHNAME',
    'LOCALMONTHNAMEGEN',
    'LOCALTIME',
    'LOCALTIMESTAMP',
    'LOCALWEEK',
    'LOCALYEAR',
    'NAMESPACE',
    'NAMESPACEE',
    'NAMESPACENUMBER',
    'NUMBERINGROUP',
    'NUMBEROFACTIVEUSERS',
    'NUMBEROFADMINS',
    'NUMBEROFARTICLES',
    'NUMBEROFEDITS',
    'NUMBEROFFILES',
    'NUMBEROFPAGES',
    'NUMBEROFUSERS',
    'NUMBEROFVIEWS',
    'NUMINGROUP',
    'PAGEID',
    'PAGELANGUAGE',
    'PAGENAME',
    'PAGENAMEE',
    'PAGESINCAT',
    'PAGESINCATEGORY',
    'PAGESINNAMESPACE',
    'PAGESINNS',
    'PAGESIZE',
    'PROTECTIONEXPIRY',
    'PROTECTIONLEVEL',
    'REVISIONDAY',
    'REVISIONDAY2',
    'REVISIONID',
    'REVISIONMONTH',
    'REVISIONMONTH1',
    'REVISIONTIMESTAMP',
    'REVISIONUSER',
    'REVISIONYEAR',
    'ROOTPAGENAME',
    'ROOTPAGENAMEE',
    'SCRIPTPATH',
    'SERVER',
    'SERVERNAME',
    'SITENAME',
    'STYLEPATH',
    'SUBJECTPAGENAME',
    'SUBJECTPAGENAMEE',
    'SUBJECTSPACE',
    'SUBJECTSPACEE',
    'SUBPAGENAME',
    'SUBPAGENAMEE',
    'TALKPAGENAME',
    'TALKPAGENAMEE',
    'TALKSPACE',
    'TALKSPACEE',
    'anchorencode',
    'canonicalurl',
    'filepath',
    'formatnum',
    'fullurl',
    'gender',
    'grammar',
    'int',
    'lc',
    'lcfirst',
    'localurl',
    'msg',
    'msgnw',
    'ns',
    'nse',
    'padleft',
    'padright',
    'plural',
    'raw',
    'safesubst',
    'subst',
    'uc',
    'ucfirst',
    'urlencode',
];


// https://github.com/wikimedia/mediawiki/blob/de18cff244e8fab2e1ab2470c3b444e76b305e12/includes/libs/mime/MimeAnalyzer.php#L425
const KNOWN_FILE_EXTENSIONS = [
    'bmp',
    'djvu',
    'gif',
    'iff',
    'jb2',
    'jp2',
    'jpc',
    'jpeg',
    'jpg',
    'jpx',
    'mid',
    'mka',
    'mkv',
    'mp3',
    'oga',
    'ogg',
    'ogv',
    'ogx',
    'opus',
    'pdf',
    'png',
    'psd',
    'spx',
    'stl',
    'svg',
    'swc',
    'swf',
    'tif',
    'tiff',
    'wbmp',
    'webm',
    'webp',
    'wmf',
    'xbm',
    'xcf',
];

/**
 * Returns the list of parsable tag extensions.
 * @return array<string>
 */
function _get_parsable_tag_extensions(): array
{
    return _parsable_tag_extensions;
}

/**
 * Returns the list of unparsable tag extensions.
 * @return array<string>
 */
function _get_unparsable_tag_extensions(): array
{
    return _unparsable_tag_extensions;
}

/**
 * Returns the list of bare external link schemes.
 * @return array<string>
 */
function _get_bare_external_link_schemes(): array
{
    return _bare_external_link_schemes;
}

/**
 * Returns the list of parser functions.
 * @return array<string>
 */
function _get_parser_functions(): array
{
    return _parser_functions;
}

/**
 * Returns the list of known file extensions.
 * @return array<string>
 */
function _get_known_file_extensions(): array
{
    return KNOWN_FILE_EXTENSIONS;
}
