<?php

/**
 * Minimal Markdown renderer for the app's intended feature set.
 * Supports: headings, paragraphs, bold, italic, strikethrough,
 * inline code, fenced code, unordered lists, task lists, tables and autolinks.
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
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

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

            // GFM-style table: header row followed by a delimiter row.
            if ($i + 1 < $count && self::looksLikeTableRow($line) && self::isTableDelimiter($lines[$i + 1])) {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }

                $headers = self::splitTableRow($line);
                $alignments = self::tableAlignments($lines[$i + 1]);

                if (count($headers) === count($alignments)) {
                    $html[] = '<div class="markdown-table-wrap"><table class="markdown-table"><thead><tr>';
                    foreach ($headers as $column => $cell) {
                        $align = $alignments[$column];
                        $attr = $align !== '' ? ' style="text-align:' . $align . '"' : '';
                        $html[] = '<th' . $attr . '>' . self::inline(trim($cell)) . '</th>';
                    }
                    $html[] = '</tr></thead><tbody>';
                    $i += 2;

                    while ($i < $count && trim($lines[$i]) !== '' && self::looksLikeTableRow($lines[$i])) {
                        $cells = self::splitTableRow($lines[$i]);
                        $html[] = '<tr>';
                        foreach ($headers as $column => $_) {
                            $cell = $cells[$column] ?? '';
                            $align = $alignments[$column] ?? '';
                            $attr = $align !== '' ? ' style="text-align:' . $align . '"' : '';
                            $html[] = '<td' . $attr . '>' . self::inline(trim($cell)) . '</td>';
                        }
                        $html[] = '</tr>';
                        $i++;
                    }

                    $html[] = '</tbody></table></div>';
                    $i--;
                    continue;
                }
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

    private static function looksLikeTableRow(string $line): bool
    {
        return strpos($line, '|') !== false;
    }

    private static function splitTableRow(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) $line = substr($line, 1);
        if (str_ends_with($line, '|')) $line = substr($line, 0, -1);
        return preg_split('/(?<!\\\\)\|/', $line) ?: [];
    }

    private static function isTableDelimiter(string $line): bool
    {
        $cells = self::splitTableRow($line);
        if (!$cells) return false;

        foreach ($cells as $cell) {
            if (!preg_match('/^\s*:?-{3,}:?\s*$/', $cell)) return false;
        }
        return true;
    }

    private static function tableAlignments(string $line): array
    {
        $out = [];
        foreach (self::splitTableRow($line) as $cell) {
            $cell = trim($cell);
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            $out[] = $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }
        return $out;
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
