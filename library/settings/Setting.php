<?php

namespace library\settings;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
class Setting
{
    /**
     * @param array<int, string> $enum_of allowed values when $type is 'enum'
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public mixed $default = null,
        public string $scope = 'channel',
        public string $flag = 'admin',
        public string $description = '',
        public bool $irc = true,
        public array $enum_of = [],
    ) {
    }

    public function __toString(): string
    {
        return "{$this->name} ({$this->type}, {$this->scope})";
    }
}
