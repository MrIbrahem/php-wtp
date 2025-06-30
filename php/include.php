<?php
foreach (glob(__DIR__ . '/*.php') as $file) {
    if ($file === __FILE__) {
        continue;
    }
    require_once $file;
}

use Wtp\Parser\_wikitextmain\WikiText;
use Wtp\Node\Argument;
use Wtp\Node\Bold;
use Wtp\Node\Comment;
use Wtp\Node\ExternalLink;
use Wtp\Node\Parameter;
use Wtp\Node\ParserFunction;
use Wtp\Node\Section;
use Wtp\Node\Table;
use Wtp\Node\Tag;
use Wtp\Node\Template;
use Wtp\Node\WikiLink;
use Wtp\Node\WikiList;

// تعريف alias للفئات الشائعة لتسهيل الاستخدام
class_alias(WikiText::class, 'WikitextParser');
class_alias(WikiText::class, 'Parse'); // بما أن `parse` كانت alias لـ `WikiText` في Python

// إذا كنت تريد جعل remove_markup دالة عامة، يمكنك فعل ذلك
// عبر استدعائها من ملفها الأصلي.
if (!function_exists('remove_markup')) {
    function remove_markup(string $s, ...$kwargs): string
    {
        $wikiText = new WikiText($s);
        // Python's **kwargs means all remaining keyword arguments.
        // In PHP, this is handled by `...$kwargs`.
        // The `_is_root_node=True` might be a flag for internal logic within `plain_text`.
        return $wikiText->plain_text(array_merge($kwargs, ['_is_root_node' => true]));
    }
}
