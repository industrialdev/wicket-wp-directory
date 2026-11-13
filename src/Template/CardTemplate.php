<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

/**
 * A `[col]` / `{{field}}` card template: parsed once, rendered per card.
 *
 * Syntax, one card row per line:
 *
 * ```
 * {{image}}
 * {{eyebrow}}
 * # {{name}}, {{post_nominal}}
 * ID: {{member_id}} | {{tiers}}
 * [col]
 * ## Contact
 * Email: {{email}}
 * Phone: {{phone}}
 * [/col]
 * {{website_button}}
 * ```
 *
 * - `{{field}}` is a merge tag (MergeTags resolves it). Everything else is
 *   literal text, escaped on output; HTML in a template shows as text.
 * - `[col]` … `[/col]` is a column. Consecutive columns form one row (the
 *   Standard card's Details / Address / Contact row). A `[col]` inside an
 *   open column starts the next one; an unclosed column ends with the
 *   template; a stray `[/col]` is ignored.
 * - `# ` starts the card title (h3); `## ` a heading (h4, a column title).
 * - Blank lines are ignored.
 * - A line with tags is left out when all its tags are empty. Within a line,
 *   `, ` and ` | ` split segments: a segment whose tags are all empty is left
 *   out with its separator, so `{{city}}, {{state_name}}` never ends in a
 *   comma.
 * - A line holding a list field (emails, phones, addresses, websites,
 *   employers…) repeats once per item of the first one; other list fields on
 *   that line show all their items, comma-joined.
 * - A tag alone on its line prints its block form when it has one: website
 *   buttons in an actions row, tag chips, the description with Show more,
 *   addresses, employers, the Organization Contact list.
 * - A column is left out when it has tags and every line with a tag is.
 *
 * Card layout: lines outside columns form groups. The group with the title
 * is the card header (`wicket-directory-card__header`), others are
 * `wicket-directory-card__rows`. The image, description, website buttons and
 * tag chips stand on their own between them. An `{{image}}` that comes
 * before anything else sits beside the content, as on the Standard card.
 *
 * @phpstan-type Part array{text?: string, tag?: string}
 * @phpstan-type Segment array{separator: string, parts: list<Part>}
 * @phpstan-type Line array{kind: string, segments: list<Segment>, tags: list<string>}
 * @phpstan-type Node array{column: bool, lines: list<Line>}
 */
final class CardTemplate
{
    public const LINE_TEXT = 'text';
    public const LINE_TITLE = 'title';
    public const LINE_HEADING = 'heading';

    /**
     * Parsed templates, by hash, for the request.
     *
     * @var array<string, self>
     */
    private static array $parsed = [];

    /**
     * @param list<Node> $nodes Lines outside columns and columns, in order.
     */
    private function __construct(public readonly array $nodes) {}

    /**
     * Parse a template; the same text is parsed once per request.
     *
     * @param string $template Template text.
     *
     * @return self
     */
    public static function parse(string $template): self
    {
        $key = md5($template);

        return self::$parsed[$key] ??= new self(self::nodes($template));
    }

    /**
     * Every tag a template uses, distinct, in order.
     *
     * @param string $template Template text.
     *
     * @return list<string>
     */
    public static function tags(string $template): array
    {
        $tags = [];

        foreach (self::parse($template)->nodes as $node) {
            foreach ($node['lines'] as $line) {
                foreach ($line['tags'] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        return array_map('strval', array_keys($tags));
    }

    /**
     * Render the template for one card.
     *
     * @param MergeTags   $tags    Tag resolver.
     * @param CardContext $context The card.
     *
     * @return array{media: string, body: string} The image beside the content (or ''), and the content.
     */
    public function render(MergeTags $tags, CardContext $context): array
    {
        $values = [];
        $resolve = static function (string $tag) use ($tags, $context, &$values): TagValue {
            return $values[$tag] ??= $tags->resolve($tag, $context);
        };

        $media = '';
        $body = '';
        $group = '';
        $group_has_title = false;
        $row = '';

        $flush_group = static function () use (&$body, &$group, &$group_has_title): void {
            if ($group !== '') {
                $body .= $group_has_title
                    ? '<header class="wicket-directory-card__header">' . $group . '</header>'
                    : '<div class="wicket-directory-card__rows">' . $group . '</div>';
            }

            $group = '';
            $group_has_title = false;
        };

        $flush_row = static function () use (&$body, &$row): void {
            if ($row !== '') {
                $body .= '<div class="wicket-directory-card__columns">' . $row . '</div>';
            }

            $row = '';
        };

        foreach ($this->nodes as $node) {
            if ($node['column']) {
                $flush_group();
                $row .= self::column($node['lines'], $resolve);
                continue;
            }

            $flush_row();

            foreach ($node['lines'] as $line) {
                $block = self::block($line, $resolve);

                if ($block !== null && $block[1]) {
                    if ($block[0] === '') {
                        continue;
                    }

                    // An image before anything else sits beside the content.
                    if ($line['tags'] === ['image'] && $media === '' && $body === '' && $group === '') {
                        $media = $block[0];
                        continue;
                    }

                    $flush_group();
                    $body .= $block[0];
                    continue;
                }

                $html = $block !== null ? $block[0] : self::line($line, $resolve);

                if ($html !== '') {
                    $group .= $html;
                    $group_has_title = $group_has_title || $line['kind'] === self::LINE_TITLE;
                }
            }
        }

        $flush_group();
        $flush_row();

        return ['media' => $media, 'body' => $body];
    }

    /**
     * Render a column; '' when it has tags and none of its tag lines shows.
     *
     * @param list<Line>                       $lines   The column's lines.
     * @param callable(string): TagValue       $resolve Tag resolver.
     *
     * @return string
     */
    private static function column(array $lines, callable $resolve): string
    {
        $html = '';
        $has_tags = false;
        $shown = false;

        foreach ($lines as $line) {
            $block = self::block($line, $resolve);
            $line_html = $block !== null ? $block[0] : self::line($line, $resolve);

            if ($line['tags'] !== []) {
                $has_tags = true;
                $shown = $shown || $line_html !== '';
            }

            $html .= $line_html;
        }

        return $html === '' || ($has_tags && !$shown) ? '' : '<div class="wicket-directory-card__column">' . $html . '</div>';
    }

    /**
     * The block form of a line that is one tag alone, when the tag has one.
     *
     * @param Line                       $line    The line.
     * @param callable(string): TagValue $resolve Tag resolver.
     *
     * @return array{0: string, 1: bool}|null The block's HTML and whether it stands alone; null to
     *                                        render the line as text.
     */
    private static function block(array $line, callable $resolve): ?array
    {
        if ($line['kind'] !== self::LINE_TEXT || count($line['segments']) !== 1 || count($line['segments'][0]['parts']) !== 1) {
            return null;
        }

        $tag = $line['segments'][0]['parts'][0]['tag'] ?? null;

        if ($tag === null) {
            return null;
        }

        $value = $resolve($tag);

        if ($value->block === null) {
            return null;
        }

        return [$value->is_empty() ? '' : $value->block, $value->standalone];
    }

    /**
     * Render a line; '' when it has tags and all are empty.
     *
     * @param Line                       $line    The line.
     * @param callable(string): TagValue $resolve Tag resolver.
     *
     * @return string One element per repetition.
     */
    private static function line(array $line, callable $resolve): string
    {
        $values = [];
        $driver = null;

        foreach ($line['tags'] as $tag) {
            $value = $resolve($tag);
            $values[$tag] = array_values(array_filter($value->items, static fn (string $item): bool => $item !== ''));

            if ($driver === null && $value->is_list && $values[$tag] !== []) {
                $driver = $tag;
            }
        }

        $count = $driver !== null ? count($values[$driver]) : 1;
        $html = '';

        for ($i = 0; $i < $count; $i++) {
            $html .= self::repetition($line, $values, $driver, $i);
        }

        return $html;
    }

    /**
     * One repetition of a line.
     *
     * @param Line                        $line   The line.
     * @param array<string, list<string>> $values Each tag's items.
     * @param string|null                 $driver The list tag the line repeats over.
     * @param int                         $index  The driver's item.
     *
     * @return string '' when the line has tags and all are empty.
     */
    private static function repetition(array $line, array $values, ?string $driver, int $index): string
    {
        $value = static fn (string $tag): string => $tag === $driver
            ? $values[$tag][$index]
            : implode(', ', $values[$tag] ?? []);

        $kept = [];
        $any = false;

        foreach ($line['segments'] as $segment) {
            $text = '';
            $has_tags = false;
            $filled = false;

            foreach ($segment['parts'] as $part) {
                if (isset($part['tag'])) {
                    $has_tags = true;
                    $html = $value($part['tag']);
                    $filled = $filled || $html !== '';
                    $text .= $html;
                } else {
                    $text .= esc_html($part['text'] ?? '');
                }
            }

            if ($has_tags && !$filled) {
                continue;
            }

            $any = $any || $filled;
            $kept[] = ($kept === [] ? '' : esc_html($segment['separator'])) . $text;
        }

        if ($line['tags'] !== [] && !$any) {
            return '';
        }

        $html = trim(implode('', $kept));

        if ($html === '') {
            return '';
        }

        return match ($line['kind']) {
            self::LINE_TITLE   => '<h3 class="component-card-listing__title wicket-directory-card__title">' . $html . '</h3>',
            self::LINE_HEADING => '<h4 class="wicket-directory-card__column-title">' . $html . '</h4>',
            default            => '<p class="wicket-directory-card__line">' . $html . '</p>',
        };
    }

    /**
     * Split a template into nodes.
     *
     * @param string $template Template text.
     *
     * @return list<Node>
     */
    private static function nodes(string $template): array
    {
        $template = str_replace(["\r\n", "\r"], "\n", $template);
        $tokens = preg_split('/(\[\/?col\b[^\]\n]*\])/i', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$template];
        $nodes = [];
        $in_column = false;

        foreach ($tokens as $token) {
            if (preg_match('/^\[(\/?)col\b/i', $token, $match) === 1) {
                $in_column = $match[1] === '';
                // Each [col] starts a new column, even inside an open one.
                if ($in_column) {
                    $nodes[] = ['column' => true, 'lines' => []];
                }

                continue;
            }

            $lines = array_values(array_filter(array_map(self::parse_line(...), explode("\n", $token))));

            if ($lines === []) {
                continue;
            }

            $last = array_key_last($nodes);

            if ($in_column && $last !== null && $nodes[$last]['column']) {
                array_push($nodes[$last]['lines'], ...$lines);
            } elseif (!$in_column && $last !== null && !$nodes[$last]['column']) {
                array_push($nodes[$last]['lines'], ...$lines);
            } else {
                $nodes[] = ['column' => $in_column, 'lines' => $lines];
            }
        }

        // Columns that got no lines are dropped.
        return array_values(array_filter($nodes, static fn (array $node): bool => $node['lines'] !== []));
    }

    /**
     * Parse one line.
     *
     * @param string $text Line text.
     *
     * @return Line|null Null for a blank line.
     */
    private static function parse_line(string $text): ?array
    {
        $text = trim($text);
        $kind = self::LINE_TEXT;

        if (preg_match('/^(#{1,2})\s+(.*)$/', $text, $match) === 1) {
            $kind = $match[1] === '#' ? self::LINE_TITLE : self::LINE_HEADING;
            $text = trim($match[2]);
        }

        if ($text === '') {
            return null;
        }

        $pieces = preg_split('/\{\{\s*([^{}]*?)\s*\}\}/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $segments = [['separator' => '', 'parts' => []]];
        $tags = [];

        foreach ($pieces as $i => $piece) {
            $current = array_key_last($segments);

            if ($i % 2 === 1) {
                $segments[$current]['parts'][] = ['tag' => $piece];
                $tags[$piece] = true;
                continue;
            }

            // Literal text: split it at the segment separators.
            $chunks = preg_split('/(,\s+|\s+\|\s+)/', $piece, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$piece];

            foreach ($chunks as $j => $chunk) {
                if ($j % 2 === 1) {
                    $segments[] = ['separator' => $chunk, 'parts' => []];
                    $current = array_key_last($segments);
                } elseif ($chunk !== '') {
                    $segments[$current]['parts'][] = ['text' => $chunk];
                }
            }
        }

        return [
            'kind'     => $kind,
            'segments' => $segments,
            'tags'     => array_map('strval', array_keys($tags)),
        ];
    }
}
