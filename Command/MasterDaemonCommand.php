<?php
namespace Beatbox\DaemonBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Beatbox\DaemonBundle\Daemon\MasterDaemon;

class MasterDaemonCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('beatbox:master-daemon')
            ->setDescription('Start the Beatbox master background process')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        print("Starting master daemon.\n");
        $d = new MasterDaemon("master", $this->getApplication()->getKernel()->getContainer());
        $d->run();
    }
}