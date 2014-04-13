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

class MageCheck extends ProjectHandlerAbstract
{
    /**
     * @param Event $event
     *
     * @throws Exceptions\DirectoryNotFound
     * @throws Exceptions\FileNotFound
     */
    public static function run(Event $event)
    {
        static::_construct($event);
        static::preRunCheck();
        static::loadDbConfig();

        if (!file_exists(static::$magentoRootDir) || !is_dir(static::$magentoRootDir)) {
            throw new Exceptions\DirectoryNotFound(static::$magentoRootDir);
        }
        $magentoConfigDir = static::getConfigValue('directories/config-mage-xml') . DIRECTORY_SEPARATOR . static::$environment['target'];
        if (!is_dir($magentoConfigDir)) {
            throw new Exceptions\DirectoryNotFound($magentoConfigDir);
        }

        $checkValues = array(
            'n98-script/file'         => static::getConfigValue('n98-script/file'),
            'n98-script/success-flag' => static::getConfigValue('n98-script/success-flag'),
            'archive/script-name'     => static::getConfigValue('archive/script-name'),
            'target-file'             => static::getConfigValue('target-file'),
            'readme'                  => static::getConfigValue('readme'),
        );
        foreach ($checkValues as $cfg => $file) {
            if (empty($file)) {
                throw new Exceptions\FileNotFound($cfg . ' => ' . $file); // only checks if config value is present, no need for the real file
            }
        }

        static::checkDatabase();
        static::persistUserData();
        static::$io->write('<info>Finished Checking!</info>', true);
    }

    /**
     * @return bool
     */
    protected static function checkDatabase()
    {
        // for building a release no DB checking
        if (true === static::$isRelease) {
            return true;
        }

        $installUser = false;
        $askRootData = false;
        try {
            self::getDbConnection();
        } catch (\PDOException $e) {
            $askRootData = true;
        }

        if ($askRootData === true) {
            $installUser = true;
            static::$io->write('<comment>Access denied to database or user not existent. Need root access data to create the user.</comment>', true);
            static::$dbConfig['db_host'] = static::$io->ask('Please enter database host [localhost]:', 'localhost');
            static::$dbConfig['db_user'] = static::$io->ask('Please enter database username [root]:', 'root');
            static::$dbConfig['db_pass'] = static::$io->askAndHideAnswer('Please enter database password [none]:');
            static::$dbConfig['db_name'] = null;
            self::$mysqlPdoWrapper       = null;
        }

        $db        = static::$localXml->global->resources->default_setup->connection->dbname;
        $query     = sprintf("SHOW DATABASES LIKE '%s';", $db);
        $pdoDbStmt = self::getDbConnection()->query($query); // this may fail if root data is invalid

        /** database exists */
        if ($pdoDbStmt->rowCount() > 0) {
            self::getDbConnection()->query('USE `' . $db . '`'); // this may fail if root data is invalid
            $pdoTblStmt             = self::getDbConnection()->query('SHOW TABLES;'); // this may fail if root data is invalid
            static::$dbTablesExists = $pdoTblStmt->rowCount() > 200; // amount of default Magento tables, over 300?!
            return true;
        }

        if ($installUser === true) {
            static::createMysqlDbAndUser();
        }
        return true;
    }

    /**
     * @throws Exceptions\BranchNotFound
     * @throws Exceptions\FileNotFound
     */
    protected static function preRunCheck()
    {
        $checkForBinaries = array('gunzip', 'mysql', 'mysqldump', 'git', 'tar', 'gzip', 'chmod', 'rm', 'openssl');

        foreach ($checkForBinaries as $bin) {
            static::$io->write('<info>Checking for ' . $bin . ' ... </info>', false);
            $result = trim(shell_exec('which ' . $bin));
            if (empty($result)) {
                throw new Exceptions\FileNotFound($bin);
            }
            static::$io->write('<comment>Ok</comment>', true);
        }
    }
}
