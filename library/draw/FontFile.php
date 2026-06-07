<?php

namespace draw;

use FontLib\Font;
use FontLib\Glyph\OutlineComposite;
use FontLib\Glyph\OutlineSimple;

class FontFile
{
    public readonly float $unitsPerEm;
    public readonly float $ascent;
    public readonly float $descent;
    public readonly float $lineGap;

    private \FontLib\TrueType\File $font;
    private array $charMap;
    private array $hmtx;
    private array $kernTree;
    private array $glyphPathCache = [];

    private function __construct(\FontLib\TrueType\File $font)
    {
        $this->font = $font;

        $head = $font->getData('head');
        $hhea = $font->getData('hhea');

        $this->unitsPerEm = (float) $head['unitsPerEm'];
        $this->ascent = (float) $hhea['ascent'];
        $this->descent = (float) $hhea['descent'];
        $this->lineGap = (float) ($hhea['lineGap'] ?? 0);

        $this->charMap = $font->getUnicodeCharMap() ?? [];
        $this->hmtx = $font->getData('hmtx');

        $kernData = $font->getData('kern');
        $this->kernTree = $kernData['subtable']['tree'] ?? [];
    }

    public static function load(string $path): self
    {
        $font = Font::load($path);
        if (!$font instanceof \FontLib\TrueType\File) {
            throw new \RuntimeException("Unsupported font format: {$path}");
        }
        $font->parse();
        return new self($font);
    }

    public function getGlyphPath(string $char): ?Path
    {
        if (isset($this->glyphPathCache[$char])) {
            return $this->glyphPathCache[$char];
        }

        $codepoint = mb_ord($char, 'UTF-8');
        if ($codepoint === false) {
            return null;
        }

        $glyphId = $this->charMap[$codepoint] ?? null;
        if ($glyphId === null) {
            return null;
        }

        $path = $this->extractGlyphPath($glyphId);
        $this->glyphPathCache[$char] = $path;
        return $path;
    }

    private function extractGlyphPath(int $glyphId): ?Path
    {
        $glyfTable = $this->font->getTableObject('glyf');
        if ($glyfTable === null) {
            return null;
        }

        $glyph = $glyfTable->data[$glyphId] ?? null;
        if ($glyph === null) {
            return null;
        }

        $glyph->parseData();

        if ($glyph instanceof OutlineSimple) {
            $svgPath = $glyph->getSVGContours();
            if ($svgPath === '' || $svgPath === null) {
                return null;
            }
            return SVGParser::parseDString($svgPath);
        }

        if ($glyph instanceof OutlineComposite) {
            return $this->extractCompositePath($glyph);
        }

        return null;
    }

    private function extractCompositePath(OutlineComposite $glyph): ?Path
    {
        $components = $glyph->getSVGContours();
        if (!is_array($components) || empty($components)) {
            return null;
        }

        return $this->resolveCompositeComponents($components);
    }

    private function resolveCompositeComponents(array $components): ?Path
    {
        $mergedPath = null;
        foreach ($components as $component) {
            $contours = $component['contours'];
            $transformArr = $component['transform'];

            if (is_string($contours) && $contours !== '') {
                $subPath = SVGParser::parseDString($contours);
            } elseif (is_array($contours)) {
                $subPath = $this->resolveCompositeComponents($contours);
                if ($subPath === null) {
                    continue;
                }
            } else {
                continue;
            }

            if (count($transformArr) === 6) {
                $t = Transform::matrix(
                    (float) $transformArr[0],
                    (float) $transformArr[1],
                    (float) $transformArr[2],
                    (float) $transformArr[3],
                    (float) $transformArr[4],
                    (float) $transformArr[5],
                );
                $subPath->setTransform($t);
            }

            if ($mergedPath === null) {
                $mergedPath = $subPath;
            } else {
                $mergedPath = $mergedPath->merge($subPath);
            }
        }

        return $mergedPath;
    }

    public function getAdvanceWidth(string $char): float
    {
        $codepoint = mb_ord($char, 'UTF-8');
        if ($codepoint === false) {
            return 0.0;
        }

        $glyphId = $this->charMap[$codepoint] ?? 0;
        return (float) ($this->hmtx[$glyphId][0] ?? 0);
    }

    public function getKerning(string $left, string $right): float
    {
        $leftCP = mb_ord($left, 'UTF-8');
        $rightCP = mb_ord($right, 'UTF-8');
        if ($leftCP === false || $rightCP === false) {
            return 0.0;
        }

        $leftId = $this->charMap[$leftCP] ?? null;
        $rightId = $this->charMap[$rightCP] ?? null;
        if ($leftId === null || $rightId === null) {
            return 0.0;
        }

        return (float) ($this->kernTree[$leftId][$rightId] ?? 0.0);
    }
}
