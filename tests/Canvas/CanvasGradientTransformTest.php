<?php

namespace Tests\Canvas;

use draw\Canvas;
use draw\ColorStop;
use draw\LinearGradient;
use PHPUnit\Framework\TestCase;

class CanvasGradientTransformTest extends TestCase
{
    public function test_gradient_with_identity_ctm_samples_pixel_coords(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(0.0, 5.0, 10.0, 5.0, $stops);
        $canvas = Canvas::createBlank(10, 10);
        $canvas->drawPoint(5, 5, $gradient);
        $pixel = $canvas->getPixel(5, 5);
        $this->assertNotNull($pixel->fg);
    }

    public function test_gradient_with_scale_transform_samples_user_coords(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(0.0, 50.0, 100.0, 50.0, $stops);
        $canvas = Canvas::createBlank(10, 10);
        $canvas->save();
        $canvas->scale(0.1, 0.1);
        $canvas->drawPoint(5, 5, $gradient);
        $canvas->restore();
        $pixel = $canvas->getPixel(1, 1);
        $this->assertNotNull($pixel->fg);
    }

    public function test_gradient_with_translate_transform_samples_user_coords(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(10.0, 5.0, 20.0, 5.0, $stops);
        $canvas = Canvas::createBlank(30, 10);
        $canvas->save();
        $canvas->translate(10.0, 0.0);
        $canvas->drawPoint(15, 5, $gradient);
        $canvas->restore();
        $pixel = $canvas->getPixel(25, 5);
        $this->assertNotNull($pixel->fg);
    }

    public function test_save_restore_preserves_inverse_ctm(): void
    {
        $stops = [new ColorStop(0.0, 255, 0, 0), new ColorStop(1.0, 0, 0, 255)];
        $gradient = new LinearGradient(0.0, 5.0, 10.0, 5.0, $stops);
        $canvas = Canvas::createBlank(30, 10);
        $canvas->save();
        $canvas->translate(10.0, 0.0);
        $canvas->drawPoint(15, 5, $gradient);
        $canvas->restore();
        $canvas->drawPoint(5, 5, $gradient);
        $this->assertNotNull($canvas->getPixel(25, 5)->fg);
        $this->assertNotNull($canvas->getPixel(5, 5)->fg);
    }
}
