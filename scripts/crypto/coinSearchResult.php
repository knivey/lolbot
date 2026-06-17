<?php

namespace scripts\crypto;

use JsonMapper\Middleware\Attributes\MapFrom;

class coinSearchResult
{
    #[MapFrom("id")]
    public string $id;

    #[MapFrom("name")]
    public string $name;

    #[MapFrom("api_symbol")]
    public string $api_symbol;

    #[MapFrom("symbol")]
    public string $symbol;

    #[MapFrom("market_cap_rank")]
    public ?int $market_cap_rank = null;
}
