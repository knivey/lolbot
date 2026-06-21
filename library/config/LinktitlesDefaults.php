<?php
namespace lolbot\config;

/** Code-level defaults for linktitles vision knobs (the bottom of the resolution cascade). */
final class LinktitlesDefaults
{
    public const MODEL = 'gpt-4o';
    public const PROMPT = 'very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!';
    public const ENABLED = false;
    public const AI_VISION_DISABLED = false;
}
