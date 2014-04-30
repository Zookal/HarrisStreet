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

class MageUpdate extends ProjectHandlerAbstract
{
    public static function run(Event $event)
    {
        static::_construct($event);
        static::loadDbConfig();
        static::copyMagentoSource();
        static::linkLocalXmlFiles();
        static::handleFileSystem();
        static::handlePersistentDirectories();
        static::backupDataBase();
        static::importCoreConfigData();
        static::removeModules();
        if (false === static::$isRelease) {
            static::updatePhpStorm();
        }
    }
}
