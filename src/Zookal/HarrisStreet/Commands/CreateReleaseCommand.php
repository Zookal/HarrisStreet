<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Downloader\ChangeReportInterface;
use Composer\IO\IOInterface;
use Composer\Command\Command;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Script\ScriptEvents;

class CreateReleaseCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('HarrisStreet:Release')
            ->setAliases(array('hs:r'))
            ->setDescription('Creates a release')
            ->setDefinition(array(
                new InputOption('env', null, InputOption::VALUE_REQUIRED, 'Name of the environment'),
                new InputOption('semver', null, InputOption::VALUE_REQUIRED, 'Version, valid semver.org'),
            ))
            ->setHelp(<<<EOT
The hs:r command creates a release according to your environment.
You must also specify the version.

EOT
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $returnCode = $this->release(
            $this->getIO(),
            $input->getOption('env'),
            $input->getOption('semver')
        );
        return $returnCode;
    }

    /**
     * @param IOInterface $io
     * @param             $environment
     * @param             $semver
     *
     * @return int
     */
    protected function release(IOInterface $io, $environment, $semver)
    {
        $io->write('<info>Env: ' . $environment . ', Version ' . $semver . '. Nothing done!</info>');
        return 0;
    }
}
