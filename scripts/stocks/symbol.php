<?php
namespace scripts\stocks;

use JsonMapper\Middleware\Attributes\MapFrom;

class symbol {
    #[MapFrom("description")]
    public string $description;

    #[MapFrom("symbol")]
    public string $symbol;

    #[MapFrom("type")]
    public string $type;

    public function verify(): bool {
        return isset($this->symbol) &&
            isset($this->description) &&
            isset($this->type);
    }
}