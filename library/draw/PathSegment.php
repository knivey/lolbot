<?php
namespace draw;

interface PathSegment
{
    /**
     * Flatten this segment into polygon vertices.
     *
     * @param float $startX Current point X when this segment begins.
     * @param float $startY Current point Y when this segment begins.
     * @param float $tolerance Maximum deviation from true curve, in canvas units.
     * @return array<int, array{float, float}> Vertices produced by this segment
     *         (excluding the start point, which the caller already has).
     */
    public function flatten(float $startX, float $startY, float $tolerance): array;

    /**
     * Returns the endpoint of this segment (where the cursor lands).
     *
     * @return array{float, float}
     */
    public function endPoint(): array;
}
