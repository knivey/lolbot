<?php
namespace scripts\stocks;

use DateTime;
use JsonMapper\Middleware\Attributes\MapFrom;

class quote {
    #[MapFrom("c")]
    public float $price;

    #[MapFrom("d")]
    public float $change;

    #[MapFrom("dp")]
    public float $changePercent;

    #[MapFrom("h")]
    public float $high;

    #[MapFrom("l")]
    public float $low;

    #[MapFrom("o")]
    public float $open;

    #[MapFrom("pc")]
    public float $prevClose;

    #[MapFrom("t")]
    public int $time;

    //TODO ditch jsonmapper for something better or make my own
    public function verify(): bool {
        // response from a unknown symbol is
        // {"c":0,"d":null,"dp":null,"h":0,"l":0,"o":0,"pc":0,"t":0}
        return isset($this->changePercent) && isset($this->change) && isset($this->price);
    }
}