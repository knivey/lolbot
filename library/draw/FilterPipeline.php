<?php

namespace draw;

use Psr\Log\LoggerInterface;

class FilterPipeline
{
    /** @var array<string, Canvas> */
    private array $results = [];
    private Canvas $lastResult;
    private bool $bgWarned = false;

    public function __construct(
        Canvas $sourceGraphic,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->results['SourceGraphic'] = $sourceGraphic;
        $this->lastResult = $sourceGraphic;
    }

    public function getResult(string $name): ?Canvas
    {
        if (isset($this->results[$name])) {
            return $this->results[$name];
        }

        return match ($name) {
            'SourceAlpha' => $this->buildSourceAlpha(),
            'BackgroundImage' => $this->buildEmptyCanvas('BackgroundImage'),
            'BackgroundAlpha' => $this->buildEmptyCanvas('BackgroundAlpha'),
            default => null,
        };
    }

    public function setResult(string $name, Canvas $canvas): void
    {
        $this->results[$name] = $canvas;
        $this->lastResult = $canvas;
    }

    public function getLastResult(): Canvas
    {
        return $this->lastResult;
    }

    private function buildSourceAlpha(): Canvas
    {
        $source = $this->results['SourceGraphic'];
        $alpha = Canvas::createBlank($source->w, $source->h, $source->halfblocks);

        for ($y = 0; $y < $source->h; $y++) {
            for ($x = 0; $x < $source->w; $x++) {
                $sp = $source->data[$y][$x];
                $dp = $alpha->data[$y][$x];
                if ($sp->fg !== null) {
                    $dp->fg = Color::White;
                    $dp->fgAlpha = $sp->fgAlpha;
                }
                if ($sp->bg !== null) {
                    $dp->bg = Color::White;
                    $dp->bgAlpha = $sp->bgAlpha;
                }
            }
        }

        $this->results['SourceAlpha'] = $alpha;
        return $alpha;
    }

    private function buildEmptyCanvas(string $sourceName): Canvas
    {
        if (!$this->bgWarned) {
            $this->logger?->warning("SVG filter source '{$sourceName}' is not supported, using empty canvas");
            $this->bgWarned = true;
        }
        $source = $this->results['SourceGraphic'];
        $empty = Canvas::createBlank($source->w, $source->h, $source->halfblocks);
        return $empty;
    }
}
