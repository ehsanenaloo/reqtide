<?php

declare(strict_types=1);

/**
 * Lexical PHP highlighter shared by the documentation tools. It wraps tokens in the classes docs/styles.css
 * styles (tk-c comment, tk-k keyword, tk-s string, tk-v variable, tk-n number) and escapes everything else.
 * It uses token_get_all() only: it never parses or runs the code, and excerpts without an opening tag are fine.
 */

/** @return string HTML for the inside of a <code> element; plain text survives unchanged when tags are stripped */
function docsHighlightPhp(string $code): string
{
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $inject = !str_starts_with($code, '<?php');
    $tokens = token_get_all($inject ? "<?php\n" . $code : $code);
    $keywords = [
        T_FUNCTION, T_RETURN, T_STATIC, T_NEW, T_USE, T_NAMESPACE, T_CLASS, T_FINAL, T_EXTENDS, T_IMPLEMENTS, T_TRY, T_CATCH, T_THROW,
        T_IF, T_ELSE, T_ELSEIF, T_FOR, T_FOREACH, T_AS, T_ECHO, T_MATCH, T_FN, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_ABSTRACT,
        T_INTERFACE, T_ENUM, T_CASE, T_CONST, T_DECLARE, T_REQUIRE, T_REQUIRE_ONCE, T_INSTANCEOF, T_DEFAULT, T_WHILE, T_BREAK, T_CONTINUE,
        T_EXIT, T_ARRAY, T_CALLABLE, T_FINALLY,
    ];
    $typeWords = ['null', 'true', 'false', 'self', 'int', 'string', 'bool', 'float', 'mixed', 'void', 'never', 'iterable', 'object'];

    $out = '';
    $inString = false;
    foreach ($tokens as $index => $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            if ($inject && $index === 0) {
                continue; // the injected open tag, which includes its newline
            }
            $class = null;
            if ($inString) {
                $class = 's';
            } elseif ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $class = 'c';
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $class = 's';
            } elseif ($id === T_VARIABLE) {
                $class = 'v';
            } elseif ($id === T_LNUMBER || $id === T_DNUMBER) {
                $class = 'n';
            } elseif (in_array($id, $keywords, true) || ($id === T_STRING && in_array(strtolower($text), $typeWords, true))) {
                $class = 'k';
            }
            $out .= $class === null ? $escape($text) : '<span class="tk-' . $class . '">' . $escape($text) . '</span>';
        } elseif ($token === '"') {
            $out .= '<span class="tk-s">"</span>';
            $inString = !$inString;
        } else {
            $out .= $escape($token);
        }
    }

    return $out;
}
