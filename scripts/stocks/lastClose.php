<?php
namespace scripts\stocks;

use JsonMapper\Middleware\Attributes\MapFrom;

class lastClose {
    #[MapFrom("01. symbol")]
    public string $symbol;

    #[MapFrom("02. open")]
    public string $open;

    #[MapFrom("03. high")]
    public string $high;

    #[MapFrom("04. low")]
    public string $low;

    #[MapFrom("05. price")]
    public string $price;

    #[MapFrom("06. volume")]
    public string $volume;

    #[MapFrom("07. latest trading day")]
    public string $date;

    #[MapFrom("08. previous close")]
    public string $prevCl;

    #[MapFrom("09. change")]
    public string $change;

    #[MapFrom("10. change percent")]
    public string $changeP;
}