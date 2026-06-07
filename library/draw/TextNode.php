<?php

namespace draw;

class TextNode implements SceneNode
{
    public string $text = '';
    public float $x = 0;
    public float $y = 0;
    public ?string $fontFamily = null;
    public float $fontSize = 16;
    public ?string $fontWeight = null;
    public ?string $fontStyle = null;
    public string $textAnchor = 'start';
    public string $dominantBaseline = 'auto';
    public ?Paint $fill = null;
    public ?StrokeStyle $stroke = null;
    public ?Transform $transform = null;
    public float $opacity = 1.0;
    public float $fillOpacity = 1.0;
    public ?FillRule $fillRule = null;
    public ?Dithering $dithering = null;

    /** @var TspanNode[] */
    public array $tspans = [];

    public function getChildren(): array
    {
        return [];
    }

    public function render(Canvas $canvas, RenderContext $ctx): void
    {
        $effective = $ctx->merge(
            fill: $this->fill,
            stroke: $this->stroke,
            opacity: $this->opacity,
            fillOpacity: $this->fillOpacity,
            fillRule: $this->fillRule,
            dithering: $this->dithering,
        );

        $effectiveFontSize = max(1.0, $this->fontSize);

        $resolvedFontFamily = $this->fontFamily;
        $resolvedWeight = $this->fontWeight;
        $resolvedStyle = $this->fontStyle;

        try {
            $font = FontManager::resolve(
                $resolvedFontFamily ?? 'sans-serif',
                $resolvedWeight,
                $resolvedStyle,
            );
        } catch (\Throwable) {
            $font = null;
        }

        $scale = $font !== null ? $effectiveFontSize / $font->unitsPerEm : 1.0;
        $baselineY = $this->computeBaselineY($font, $scale);

        $runs = $this->buildRuns();

        $totalWidth = 0.0;
        foreach ($runs as $run) {
            $totalWidth += $this->measureRunWidth($run, $font, $scale, $effectiveFontSize);
        }

        $penX = $this->x;
        if ($this->textAnchor === 'middle') {
            $penX -= $totalWidth / 2;
        } elseif ($this->textAnchor === 'end') {
            $penX -= $totalWidth;
        }

        $penY = $this->y + $baselineY;

        $canvas->save();

        if ($this->transform !== null) {
            $canvas->concatTransform($this->transform);
        }

        $prevDithering = $canvas->getDithering();
        $resolvedDithering = $effective->dithering ?? $prevDithering;
        $canvas->setDithering($resolvedDithering);

        foreach ($runs as $run) {
            $penX = $this->renderRun($run, $canvas, $font, $scale, $effectiveFontSize, $penX, $penY, $effective);
        }

        $canvas->setDithering($prevDithering);
        $canvas->restore();
    }

    private function renderRun(
        TextRun $run,
        Canvas $canvas,
        ?FontFile $font,
        float $scale,
        float $effectiveFontSize,
        float $penX,
        float $penY,
        RenderContext $effective,
    ): float {
        if ($run->dx !== null) {
            $penX += $run->dx;
        }
        if ($run->dy !== null) {
            $penY += $run->dy;
        }

        $runFont = $font;
        $runScale = $scale;
        if ($run->fontFamily !== null || $run->fontSize !== null || $run->fontWeight !== null || $run->fontStyle !== null) {
            $runFontFamily = $run->fontFamily ?? $this->fontFamily ?? 'sans-serif';
            $runWeight = $run->fontWeight ?? $this->fontWeight;
            $runStyle = $run->fontStyle ?? $this->fontStyle;
            try {
                $runFont = FontManager::resolve($runFontFamily, $runWeight, $runStyle);
                $runScale = ($run->fontSize ?? $effectiveFontSize) / $runFont->unitsPerEm;
            } catch (\Throwable) {
                $runFont = $font;
                $runScale = $scale;
            }
        }

        $runFill = $run->fill ?? $effective->fill;
        $runStroke = $run->stroke ?? $effective->stroke;

        if ($runFont !== null) {
            $runScale = ($run->fontSize ?? $effectiveFontSize) / $runFont->unitsPerEm;
        }

        $chars = mb_str_split($run->text);
        $prevChar = null;
        foreach ($chars as $char) {
            $kerning = 0.0;
            if ($prevChar !== null && $runFont !== null) {
                $kerning = $runFont->getKerning($prevChar, $char) * $runScale;
            }

            $glyphX = $penX + $kerning;

            $glyphPath = $runFont?->getGlyphPath($char);

            if ($glyphPath !== null) {
                $glyphTransform = Transform::translate($glyphX, $penY)
                    ->multiply(Transform::scale($runScale, -$runScale));
                $glyphPath->setTransform($glyphTransform);

                $effectiveFill = ($runFill instanceof NoPaint) ? null : $runFill;
                $effectiveStroke = $runStroke;
                if ($effectiveStroke !== null && $effectiveStroke->paint instanceof NoPaint) {
                    $effectiveStroke = null;
                }

                if ($effectiveFill !== null || $effectiveStroke !== null) {
                    $canvas->drawPath(
                        $glyphPath,
                        $effectiveFill,
                        $effectiveStroke,
                        '',
                        $effective->fillRule,
                        $effective->fillOpacity,
                        $effective->opacity,
                    );
                }
            } else {
                $effectiveFill = ($runFill instanceof NoPaint) ? null : $runFill;
                if ($effectiveFill !== null) {
                    $canvas->drawPoint($glyphX, $penY, $effectiveFill, $char);
                }
            }

            if ($runFont !== null) {
                $penX = $glyphX + $runFont->getAdvanceWidth($char) * $runScale;
            } else {
                $penX = $glyphX + $effectiveFontSize * 0.6;
            }

            $prevChar = $char;
        }

        return $penX;
    }

    private function computeBaselineY(?FontFile $font, float $scale): float
    {
        if ($font === null) {
            return 0.0;
        }

        return match ($this->dominantBaseline) {
            'auto', 'alphabetic' => 0.0,
            'middle', 'central' => ($font->ascent + $font->descent) / 2 * $scale,
            'hanging' => $font->ascent * $scale,
            'text-top' => $font->ascent * $scale,
            'text-bottom' => $font->descent * $scale,
            default => 0.0,
        };
    }

    private function measureRunWidth(TextRun $run, ?FontFile $font, float $scale, float $effectiveFontSize): float
    {
        $runFont = $font;
        $runScale = $scale;

        if ($run->fontFamily !== null || $run->fontSize !== null || $run->fontWeight !== null || $run->fontStyle !== null) {
            $runFontFamily = $run->fontFamily ?? $this->fontFamily ?? 'sans-serif';
            $runWeight = $run->fontWeight ?? $this->fontWeight;
            $runStyle = $run->fontStyle ?? $this->fontStyle;
            try {
                $runFont = FontManager::resolve($runFontFamily, $runWeight, $runStyle);
                $runScale = ($run->fontSize ?? $effectiveFontSize) / $runFont->unitsPerEm;
            } catch (\Throwable) {
                $runFont = $font;
                $runScale = $scale;
            }
        }

        if ($runFont === null) {
            return mb_strlen($run->text) * $effectiveFontSize * 0.6;
        }

        $width = 0.0;
        $chars = mb_str_split($run->text);
        $prevChar = null;
        foreach ($chars as $char) {
            if ($prevChar !== null) {
                $width += $runFont->getKerning($prevChar, $char) * $runScale;
            }
            $width += $runFont->getAdvanceWidth($char) * $runScale;
            $prevChar = $char;
        }
        return $width;
    }

    /** @return TextRun[] */
    private function buildRuns(): array
    {
        $runs = [];
        if ($this->text !== '') {
            $runs[] = new TextRun(
                text: $this->text,
            );
        }

        foreach ($this->tspans as $tspan) {
            $runs[] = new TextRun(
                text: $tspan->text,
                fontFamily: $tspan->fontFamily,
                fontSize: $tspan->fontSize,
                fontWeight: $tspan->fontWeight,
                fontStyle: $tspan->fontStyle,
                fill: $tspan->fill,
                stroke: $tspan->stroke,
                dx: $tspan->dx,
                dy: $tspan->dy,
            );
        }

        return $runs;
    }
}
