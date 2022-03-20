<?php
namespace lolbot\entities;

use knivey\tools;
use Doctrine\ORM\Mapping as ORM;

class Network
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column(length: 512)]
    protected string $hostmask;

    #[ORM\Column]
    protected \DateTime $created;

}
