<?php

namespace draw;

use Psr\Log\LoggerInterface;

class SVGDocument
{
    public function __construct(
        private readonly SceneNode $root,
        private readonly ?array $viewBox = null,
        private readonly ?float $width = null,
        private readonly ?float $height = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getRoot(): SceneNode
    {
        return $this->root;
    }

    public function getViewBox(): ?array
    {
        return $this->viewBox;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function getViewBoxTransform(float $targetWidth, float $targetHeight, string $aspectRatio = 'xMidYMid meet'): ?Transform
    {
        if ($this->viewBox === null) {
            return null;
        }

        [$vbX, $vbY, $vbW, $vbH] = $this->viewBox;

        if ($vbW <= 0 || $vbH <= 0) {
            return null;
        }

        $scaleX = $targetWidth / $vbW;
        $scaleY = $targetHeight / $vbH;

        $parts = preg_split('/\s+/', trim($aspectRatio));
        $align = strtolower($parts[0] ?? 'xmidymid');
        $mode = strtolower($parts[1] ?? 'meet');

        if ($align === 'none') {
            $sx = $scaleX;
            $sy = $scaleY;
            $tx = 0.0;
            $ty = 0.0;
        } else {
            if ($mode === 'slice') {
                $sx = $sy = max($scaleX, $scaleY);
            } else {
                $sx = $sy = min($scaleX, $scaleY);
            }

            $tx = match (true) {
                str_contains($align, 'xmin') => 0.0,
                str_contains($align, 'xmax') => $targetWidth - $vbW * $sx,
                default => ($targetWidth - $vbW * $sx) / 2.0,
            };

            $ty = match (true) {
                str_contains($align, 'ymin') => 0.0,
                str_contains($align, 'ymax') => $targetHeight - $vbH * $sy,
                default => ($targetHeight - $vbH * $sy) / 2.0,
            };
        }

        return Transform::translate($tx, $ty)
            ->multiply(Transform::scale($sx, $sy))
            ->multiply(Transform::translate(-$vbX, -$vbY));
    }

    public function render(Canvas $canvas, ?RenderContext $ctx = null): void
    {
        $ctx ??= RenderContext::defaults();

        if ($this->viewBox !== null) {
            $vbt = $this->getViewBoxTransform((float) $canvas->w, (float) $canvas->h);
            $canvas->save();
            if ($vbt !== null) {
                $canvas->concatTransform($vbt);
            }
        }

        $this->root->render($canvas, $ctx);

        if ($this->viewBox !== null) {
            $canvas->restore();
        }
    }
}
