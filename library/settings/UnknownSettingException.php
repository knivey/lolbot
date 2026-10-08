<?php

namespace library\settings;

/**
 * Thrown when a setting name is not present in the SettingsRegistry.
 * Every SettingsStore method resolves the definition FIRST, before any
 * storage routing or entity work, and throws this for unknown names.
 */
class UnknownSettingException extends \InvalidArgumentException {}
