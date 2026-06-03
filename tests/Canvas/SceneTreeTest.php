<?php

namespace Tests\Canvas;

use draw\Color;
use draw\FillRule;
use draw\RenderContext;
use draw\Transform;
use PHPUnit\Framework\TestCase;

class SceneTreeTest extends TestCase
{
    public function test_render_context_defaults(): void
    {
        $ctx = RenderContext::defaults();
        $this->assertInstanceOf(Color::class, $ctx->fill);
        $this->assertNull($ctx->stroke);
        $this->assertTrue(Transform::identity()->equals($ctx->transform));
        $this->assertSame(1.0, $ctx->opacity);
        $this->assertSame(1.0, $ctx->fillOpacity);
        $this->assertSame(FillRule::NonZero, $ctx->fillRule);
    }

    public function test_render_context_merge_no_overrides(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fill: null, stroke: null, transform: null, opacity: null, fillOpacity: null, fillRule: null);
        $this->assertEquals($ctx->fill, $merged->fill);
        $this->assertNull($merged->stroke);
        $this->assertSame($ctx->opacity, $merged->opacity);
    }

    public function test_render_context_merge_overrides_fill(): void
    {
        $ctx = RenderContext::defaults();
        $newFill = new Color(4, null);
        $merged = $ctx->merge(fill: $newFill);
        $this->assertSame($newFill, $merged->fill);
    }

    public function test_render_context_merge_overrides_stroke(): void
    {
        $ctx = RenderContext::defaults();
        $stroke = new \draw\StrokeStyle(new Color(9, null));
        $merged = $ctx->merge(stroke: $stroke);
        $this->assertSame($stroke, $merged->stroke);
    }

    public function test_render_context_merge_composes_transform(): void
    {
        $ctx = RenderContext::defaults();
        $t = Transform::translate(10.0, 20.0);
        $merged = $ctx->merge(transform: $t);
        $this->assertTrue($t->equals($merged->transform));
    }

    public function test_render_context_merge_multiplies_opacity(): void
    {
        $ctx = RenderContext::defaults()->merge(opacity: 0.5);
        $this->assertSame(0.5, $ctx->opacity);
        $merged = $ctx->merge(opacity: 0.8);
        $this->assertSame(0.4, $merged->opacity);
    }

    public function test_render_context_merge_multiplies_fill_opacity(): void
    {
        $ctx = RenderContext::defaults()->merge(fillOpacity: 0.5);
        $this->assertSame(0.5, $ctx->fillOpacity);
        $merged = $ctx->merge(fillOpacity: 0.6);
        $this->assertSame(0.3, $merged->fillOpacity);
    }

    public function test_render_context_merge_overrides_fill_rule(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fillRule: FillRule::EvenOdd);
        $this->assertSame(FillRule::EvenOdd, $merged->fillRule);
    }

    public function test_render_context_is_immutable(): void
    {
        $ctx = RenderContext::defaults();
        $merged = $ctx->merge(fill: new Color(4, null));
        $this->assertNotSame($ctx, $merged);
        $this->assertNotSame($ctx->fill, $merged->fill);
    }
}
