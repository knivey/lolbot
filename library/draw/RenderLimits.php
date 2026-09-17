<?php

namespace draw;

/**
 * Central caps for rendering untrusted SVG/image input.
 * Guards against memory bombs (allocation) and CPU bombs (rasterizer loops).
 * See docs/superpowers/specs/2026-09-17-svg-hardening-design.md
 */
final class RenderLimits
{
    //total canvas pixels (Pixel objects cost ~150 bytes each; 1M ≈ 150-200MB worst case)
    public const maxCanvasPixels = 1000000;

    //per-dimension sanity bound
    public const maxCanvasSide = 100000;

    //XML nesting depth / element count caps for SVGParser
    public const maxParseDepth = 200;
    public const maxElements = 100000;

    //path `d` attribute segment cap
    public const maxPathSegments = 50000;

    //total vertices a single path may emit while flattening (curve-subdivision bomb bound)
    public const maxPathVertices = 500000;

    //attribute clamps (silent, degrade gracefully)
    public const maxStrokeWidth = 500;
    public const maxBlurStdDev = 100;
    public const maxFontSize = 1000;
    public const maxDashPatternEntries = 16;
    public const maxDashCount = 10000;
    public const maxArcSteps = 5000;

    //coordinate magnitude clamp before rasterization
    public const maxCoordMagnitude = 10000000;
}
