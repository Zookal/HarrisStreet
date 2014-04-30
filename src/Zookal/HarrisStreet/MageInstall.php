<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Zookal\HarrisStreet\Exceptions;

class MageInstall extends ProjectHandlerAbstract
{
    public static function run(Event $event)
    {
        static::_construct($event);
        static::removeN98MageFile();

        /**
         * check for correct branch name
         */
        if (true === static::$isRelease) {
            static::checkGitBranchValidSemVer();
        }

        static::loadDbConfig();
        static::copyMagentoSource();

        // if a database dump is found, and DB+tables not exists, read it, else install magento
        if (
            false === static::$isRelease &&
            false === static::$dbConfig['dbTablesExists'] &&
            (static::$target['target'] === 'development' || static::$target['target'] === 'staging')
        ) {

            static::dropCreateDatabase();

            $sqlDumpGz = static::getFilePath(array(
                static::getConfigValue('directories/db-dump'),
                static::$target['target'] . '.sql.gz'
            ));
            if (true === file_exists($sqlDumpGz) && filesize($sqlDumpGz) > 4096) {
                $result = static::importMySqlDump($sqlDumpGz);
                static::$io->write('Result of mysql dump import: ' . (empty($result) ? 'Success!' : $result), true);
            } else {
                static::runMagentoInstall();
            }
        }
        static::linkLocalXmlFiles();
        static::backupDataBase();

        static::handleFileSystem();
        static::handlePersistentDirectories();
        static::importCoreConfigData();

        static::removeModules();

        if (false === static::$isRelease) {
            static::updatePhpStorm();
        }

        if (true === static::$isRelease) {
            static::generateRelease();
        }
    }
}
