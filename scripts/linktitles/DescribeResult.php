<?php

namespace scripts\linktitles;

/**
 * Outcome of an ImageDescriber run: either a raw (untruncated) description
 * or a typed failure, plus whatever profile/timing fragments were gathered
 * before the failure so callers can keep their existing profile strings.
 */
final class DescribeResult
{
    public const TOO_LARGE = 'too_large';
    public const UNDECODABLE = 'undecodable';
    public const EMPTY = 'empty';
    public const TIMEOUT = 'timeout';
    public const UPSTREAM = 'upstream_error';

    public function __construct(
        public readonly ?string $description,
        public readonly ?string $error,
        public readonly string $errorDetail = '',
        public readonly string $profile = '',
        public readonly float $workMs = 0.0,
    ) {}

    public static function success(string $description, string $profile, float $workMs): self
    {
        return new self($description, null, '', $profile, $workMs);
    }
}
