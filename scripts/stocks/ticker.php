<?php
namespace scripts\stocks;

use JsonMapper\Middleware\Attributes\MapFrom;

class ticker {
    #[MapFrom("1. symbol")]
    public string $symbol;

    #[MapFrom("2. name")]
    public string $name;

    #[MapFrom("3. type")]
    public string $type;

    #[MapFrom("4. region")]
    public string $region;

    #[MapFrom("5. marketOpen")]
    public string $marketOpen;

    #[MapFrom("6. marketClose")]
    public string $marketClose;

    #[MapFrom("7. timezone")]
    public string $timezone;

    #[MapFrom("8. currency")]
    public string $currency;


}