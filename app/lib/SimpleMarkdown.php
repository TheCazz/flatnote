<?php

/**
 * Minimal Markdown renderer for the app's intended feature set.
 * Supports: headings, paragraphs, bold, italic, strikethrough,
 * inline code, fenced code, unordered lists, task lists and autolinks.
 */
class SimpleMarkdown
{
    public static function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);
        $html = [];
        $inList = false;
        $inCode = false;
        $codeBuffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if ($inCode) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                    $codeBuffer = [];
                    $inCode = false;
                } else {
                    if ($inList) {
                        $html[] = '</ul>';
                        $inList = false;
                    }
                    $inCode = true;
                }
                continue;
            }

            if ($inCode) {
                $codeBuffer[] = $line;
                continue;
            }

            if (trim($line) === '') {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $level = strlen($m[1]);
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^- \[( |x|X)\]\s+(.*)$/', $line, $m)) {
                if (!$inList) {
                    $html[] = '<ul class="task-list">';
                    $inList = true;
                }
                $checked = strtolower($m[1]) === 'x' ? ' checked' : '';
                $html[] = '<li class="task-item"><label><input type="checkbox" disabled' . $checked . '> <span>' . self::inline($m[2]) . '</span></label></li>';
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $m)) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            $html[] = '<p>' . self::inline($line) . '</p>';
        }

        if ($inCode) {
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        }
        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Inline code first, then simple emphasis rules.
        $text = preg_replace_callback('/`([^`]+)`/', fn($m) => '<code>' . $m[1] . '</code>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);

        // Make plain HTTP(S) URLs clickable.
        $text = preg_replace_callback(
            '~(?<!["\'>])(https?://[^\s<]+)~i',
            function ($m) {
                $url = rtrim($m[1], '.,;:!?)]}');
                $tail = substr($m[1], strlen($url));
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $tail;
            },
            $text
        );

        return $text;
    }
}
