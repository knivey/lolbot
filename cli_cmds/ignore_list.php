<?php
namespace lolbot\cli_cmds;
/**
 * @psalm-suppress InvalidGlobal
 */
global $entityManager;

use GetOpt\Command;
use GetOpt\GetOpt;
use GetOpt\Operand;
use GetOpt\Option;
use lolbot\entities\Ignore;

class ignore_list extends Command
{
    public function __construct()
    {
        parent::__construct('ignore:list', $this->handle(...));
        $this->addOption(Option::create('b', 'bot', GetOpt::MULTIPLE_ARGUMENT));
        $this->addOption(Option::create('n', 'network', GetOpt::MULTIPLE_ARGUMENT));
    }

    public function handle(GetOpt $getOpt) {
        global $entityManager;
        $repo = $entityManager->getRepository(Ignore::class);
        $ignores = $repo->findAll();
        foreach ($ignores as $ignore) {
            echo $ignore . "\n";
        }
    }
}
