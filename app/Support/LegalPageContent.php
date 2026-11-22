<?php

namespace App\Support;

/**
 * Turns the admin-written page text into the sections the website renders.
 */
final class LegalPageContent
{
    /**
     * "## Heading" starts a section, "- item" lines become bullets and any other text is a
     * paragraph (a blank line ends it). Text before the first heading is ignored.
     *
     * @return array<int, array{heading: string, paragraphs: array<int, string>, bullets?: array<int, string>}>
     */
    public static function parse(string $content): array
    {
        $sections = [];
        $current = null;
        $paragraph = [];

        $closeParagraph = function () use (&$current, &$paragraph): void {
            if ($current !== null && $paragraph !== []) {
                $current['paragraphs'][] = implode(' ', $paragraph);
            }

            $paragraph = [];
        };

        $closeSection = function () use (&$sections, &$current, $closeParagraph): void {
            $closeParagraph();

            if ($current !== null) {
                if ($current['bullets'] === []) {
                    unset($current['bullets']);
                }

                $sections[] = $current;
            }

            $current = null;
        };

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '## ')) {
                $closeSection();
                $current = ['heading' => trim(substr($line, 3)), 'paragraphs' => [], 'bullets' => []];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($line === '') {
                $closeParagraph();
            } elseif (str_starts_with($line, '- ')) {
                $closeParagraph();
                $current['bullets'][] = trim(substr($line, 2));
            } else {
                $paragraph[] = $line;
            }
        }

        $closeSection();

        return $sections;
    }
}
