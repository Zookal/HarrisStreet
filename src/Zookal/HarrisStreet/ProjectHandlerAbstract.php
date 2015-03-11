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

abstract class ProjectHandlerAbstract
{
    const N98_MAGRRUN_CMD = 'php n98-magerun.phar '; // @todo move into composer.json
    const COMPOSER_JSON = 'composer.json';
    const LOCAL_XML = 'local.xml';
    protected static $mysqlPdoWrapper = null;
    protected static $workDir = null;
    protected static $magentoInstallerConfig = [];
    protected static $magentoRootDir = null;
    protected static $vendorDir = null;
    protected static $target = null;
    protected static $dbConfig = [];
    protected static $localXml = null;
    protected static $composerBinDir = null;
    protected static $currentGitBranchName = null;
    protected static $releaseGitBranchName = null;
    protected static $releaseVersion = null;
    protected static $isRoot = false;
    protected static $isRelease = false;
    protected static $dbTablesExists = false;

    /**
     * @var IOInterface
     */
    protected static $io = null;

    protected static $eventName = null;

    /**
     * @param Event $event
     */
    protected static function _construct(Event $event)
    {

        static::$io = $event->getIO();
        $options    = $event->getComposer()->getPackage()->getExtra();

        static::$workDir                = rtrim(isset($_SERVER['PWD']) ? $_SERVER['PWD'] : trim(shell_exec('pwd')), '/');
        static::$magentoRootDir         = rtrim(isset($options['magento-root-dir']) ? $options['magento-root-dir'] : '', '/');
        static::$magentoInstallerConfig = $options['magento-installer-config'];
        static::$composerBinDir         = $event->getComposer()->getConfig()->get('bin-dir');
        static::$vendorDir              = rtrim($event->getComposer()->getConfig()->get('vendor-dir'), '/');
        static::$eventName              = $event->getName();
        static::$isRoot                 = trim(shell_exec('whoami')) === 'root';
        static::detectEnvironment();
    }

    /**
     * @param string|array $path
     * @param array        $recCfg
     *
     * @return null
     */
    protected static function getConfigValue($path, array $recCfg = null)
    {
        if (empty($path)) {
            return null;
        }

        if ($recCfg === null) {
            $recCfg = static::$magentoInstallerConfig;
        }

        $segments = is_array($path) ? $path : explode('/', $path);

        foreach ($segments as $segment) {
            if (isset($recCfg[$segment])) {
                unset($segments[0]);
                if (count($segments) > 0) {
                    return static::getConfigValue(implode('/', $segments), $recCfg[$segment]);
                } else {
                    return $recCfg[$segment];
                }
            }
        }
        return null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    protected static function detectEnvironment()
    {
        static::checkGitBranchValidSemVer(false);

        $targetJsonFile = static::getFilePath([static::$workDir, static::getConfigValue('target-file')]);
        static::fileExists($targetJsonFile);

        static::$target = json_decode(file_get_contents($targetJsonFile), true);

        if (!isset(static::$target['target']) || empty(static::$target['target'])) {
            throw new \InvalidArgumentException('Invalid Target in ' . static::getConfigValue('target-file'));
        }
        if (true === static::$isRelease) {
            $branchPath                   = 'targets/' . static::$target['target'] . '/branch';
            static::$releaseGitBranchName = static::getConfigValue($branchPath);
            if (true === empty(static::$releaseGitBranchName)) {
                throw new \Exception('Missing entry "branch" in config path: ' . $branchPath);
            }
            if (false === static::isLocalGitBranchAvailable(static::$releaseGitBranchName)) {
                throw new \Exception('The local branch ' . static::$releaseGitBranchName . ' does not exists! Please create it!');
            }
        }
    }

    /**
     * load a developers custom local.xml file if he/she specifies it in the target.json file
     * but only if we are not building a release
     * @return string
     */
    protected static function getLocalXmlFilePath()
    {
        if (false === static::$isRelease && isset(static::$target[static::LOCAL_XML]) && true === is_file(static::$target[static::LOCAL_XML])) {
            $localXml = static::$target[static::LOCAL_XML];
            static::$io->write('<comment>Loading custom ' . static::LOCAL_XML . ': ' . $localXml . '</comment>', true);
        } else {
            $localXml = static::getFilePath([self::getConfigValue('directories/config-mage-xml'),
                static::$target['target'], static::LOCAL_XML
            ]);
        }
        $localXml = realpath($localXml);
        static::fileExists($localXml);
        return $localXml;
    }

    /**
     * @return bool
     */
    protected static function loadDbConfig()
    {
        $localXml = static::getLocalXmlFilePath();

        /**
         * stop loading when we are building a release
         */
        if (true === static::$isRelease) {
            return true;
        }

        static::$localXml = simplexml_load_file($localXml);

        $persistedData = static::getPersistedUserData(); // loading mysql root access data
        if (false !== $persistedData) {
            static::$dbConfig = $persistedData;

            if (!isset(static::$dbConfig['dbTablesExists'])) {
                static::$dbConfig['dbTablesExists'] = false;
            }

            return true;
        }

        $connection       = static::$localXml->global->resources->default_setup->connection;
        static::$dbConfig = [
            'db_host' => (string)$connection->host,
            'db_user' => (string)$connection->username,
            'db_pass' => (string)$connection->password,
            'db_name' => (string)$connection->dbname,
        ];
        return true;
    }

    /**
     * @param $file
     *
     * @return bool
     * @throws Exceptions\FileNotFound
     */
    protected static function fileExists($file)
    {
        if (false === file_exists($file)) {
            throw new Exceptions\FileNotFound($file);
        }
        return true;
    }

    /**
     * @param bool $useDbName
     *
     * @return null|PdoWrapper
     */
    protected static function getDbConnection($useDbName = false)
    {
        if (null !== self::$mysqlPdoWrapper) {
            return self::$mysqlPdoWrapper;
        }

        self::$mysqlPdoWrapper = new PdoWrapper();

        self::$mysqlPdoWrapper->init(
            'mysql:host=' . static::$dbConfig['db_host'] . ($useDbName === true ? ';dbname=' . static::$dbConfig['db_name'] : ''),
            static::$dbConfig['db_user'],
            static::$dbConfig['db_pass']
        );
        return self::$mysqlPdoWrapper;
    }

    /**
     * @param      $command
     * @param bool $outputCommand
     *
     * @throws \RuntimeException
     */
    protected static function executeCommand($command, $outputCommand = false)
    {
        if (true === $outputCommand) {
            static::$io->write('<comment>Executing: ' . $command . '</comment>', true);
        }
        $process = new Process(null);
        $process->setCommandLine($command);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('An error occurred while executing \'%s\'.', $command));
        }
    }

    /**
     * CREATE USER `zookal`@`localhost` IDENTIFIED BY 'ZGmuPfuMl2tL';
     * GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, LOCK TABLES,
     * CREATE VIEW, SHOW VIEW ON `zookal-dev`.* TO `zookal`@`localhost`;
     *
     * @todo extend this method for dev/vg usage
     *
     * @return bool
     */
    protected static function createMysqlDbAndUser()
    {
        $connection = static::$localXml->global->resources->default_setup->connection;

        $queries   = [];
        $queries[] = sprintf('GRANT USAGE ON *.* TO `%s`@`%s`;', $connection->username, $connection->fromHost);
        $queries[] = sprintf('DROP USER `%s`@`%s`;', $connection->username, $connection->host);
        $queries[] = sprintf('CREATE USER `%s`@`%s` IDENTIFIED BY %s;',
            $connection->username,
            $connection->fromHost,
            self::getDbConnection()->quote($connection->password));
        // CREATE TEMPORARY TABLES,EXECUTE,CREATE ROUTINE, ALTER ROUTINE, TRIGGER
        $queries[] = sprintf('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, LOCK TABLES,
        CREATE VIEW, SHOW VIEW ON `%s`.* TO `%s`@`%s`;', $connection->dbname, $connection->username, $connection->fromHost);

        foreach ($queries as $qry) {
            self::getDbConnection()->query($qry);
        }
        return true;
    }

    /**
     *
     */
    protected static function dropCreateDatabase()
    {
        $connection = static::$localXml->global->resources->default_setup->connection;
        $queries    = [];
        $queries[]  = sprintf('DROP DATABASE IF EXISTS `%s`;', $connection->dbname);
        $queries[]  = sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET %s COLLATE %s;',
            $connection->dbname,
            static::getConfigValue('db/character-set'),
            static::getConfigValue('db/collate')
        );
        foreach ($queries as $qry) {
            self::getDbConnection()->query($qry);
        }
    }

    /**
     * @param $pathToGzFile
     *
     * @return string
     */
    protected static function importMySqlDump($pathToGzFile)
    {
        static::$io->write('<comment>Importing MySQl Dump ...</comment>', true);
        $cmd = sprintf('gunzip --stdout %s | mysql --host=%s --user=%s --password=%s %s',
            escapeshellarg($pathToGzFile),
            escapeshellarg(static::$dbConfig['db_host']),
            escapeshellarg(static::$dbConfig['db_user']),
            escapeshellarg(static::$dbConfig['db_pass']),
            escapeshellarg(static::$localXml->global->resources->default_setup->connection->dbname)
        );

        // @todo use composer process to avoid passing the password via CLI
        return shell_exec($cmd);
    }

    /**
     * @param array $pathToFile
     *
     * @return string
     */
    protected static function getFilePath(array $pathToFile)
    {
        return implode(DIRECTORY_SEPARATOR, $pathToFile);
    }

    /**
     * @todo
     * refactor so it is more configurable to e.g. add local.xml.phpunit and other stuff
     * maybe even only via json config
     */
    protected static function linkLocalXmlFiles()
    {
        $localXml = static::getLocalXmlFilePath();
        $files    = [
            [
                'from' => [$localXml],
                'to'   => [static::$magentoRootDir, 'app', 'etc', static::LOCAL_XML],
            ],
            [
                'from' => ['..', '..', '..', static::getConfigValue('directories/config-mage-xml'),
                    static::$target['target'],
                    static::LOCAL_XML . '.phpunit'
                ],
                'to'   => [static::$magentoRootDir, 'app', 'etc', static::LOCAL_XML . '.phpunit'],
            ],
            [
                'from' => ['..', '..', static::getConfigValue('directories/config-mage-xml'), static::$target['target'],
                    'errors', static::LOCAL_XML
                ],
                'to'   => [static::$magentoRootDir, 'errors', static::LOCAL_XML],
            ],
        ];

        foreach ($files as $file) {
            $from = static::getFilePath($file['from']);
            $to   = static::getFilePath($file['to']);

            if (true === file_exists($to)) {
                unlink($to);
            }
            static::executeCommand('ln -s ' . $from . ' ' . $to);
        }
    }

    /**
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected static function handleFileSystem()
    {
        $parameters = static::getConfigValue('targets/' . static::$target['target'] . '/file-system');

        if (isset(static::$target['file-system']) && is_array(static::$target['file-system'])) {
            $parameters = $parameters + static::$target['file-system'];
        }

        if (false === is_array($parameters) || count($parameters) === 0) {
            throw new \InvalidArgumentException('Config path ' . 'targets/' . static::$target['target'] . '/file-system' . ' cannot be empty!');
        }

        if ('copy' === $parameters['magento-deploystrategy']) {
            static::$io->write('<info>Replacing symlinks with file/directory ...</info>', true);
            $cmd = 'find ' . static::$magentoRootDir . ' -type l -exec ' . static::$composerBinDir . '/ReplaceSymLink.sh {} \;';
            static::executeCommand($cmd, true);
        }

        $chmodF = isset($parameters['chmod-file']) ? (int)$parameters['chmod-file'] : 644;
        $chmodD = isset($parameters['chmod-dir']) ? (int)$parameters['chmod-dir'] : 755;
        static::executeCommand('find ' . static::$magentoRootDir . ' -type d -exec chmod ' . $chmodD . ' {} \;', true);
        static::executeCommand('find ' . static::$magentoRootDir . ' -type f -exec chmod ' . $chmodF . ' {} \;', true);

        /**
         * if we are building a release we do not need to change the file system owner/group/permissions
         */
        if (true === static::$isRelease) {
            return true;
        }

        if (false === static::$isRoot) {
            static::$io->write('<warning>Script is not running as sudo / root. Cannot change ownership of files!</warning>', true);
            return false;
        }

        if (trim($parameters['user']) === 'ask') {
            $parameters['user'] = static::$io->ask('File system username (not the apache/nginx user):', '');
        }

        if (trim($parameters['group']) === 'ask') {
            $parameters['group'] = static::$io->ask('File system groupname (apache/nginx is a member):', '');
        }

        if (!empty($parameters['user']) && !empty($parameters['group'])) {
            $cmd = sprintf('chown -R %s:%s %s',
                $parameters['user'],
                $parameters['group'],
                '*' // static::$magentoRootDir
            );
            static::executeCommand($cmd, true);
        }

        return true;
    }

    /**
     * @todo MAYBE use n98magerun
     *       download magento form some sources
     */
    protected static function runMagentoInstall()
    {
        static::$io->write('<comment>Running Magento install: php -f install.php</comment>', true);

        // move local.xml file
        $oldLocalXml = static::getFilePath([static::$magentoRootDir, 'app', 'etc', static::LOCAL_XML]);
        if (file_exists($oldLocalXml)) {
            @rename($oldLocalXml, $oldLocalXml . '.' . date('Y-m-d-H-i-s'));
            static::$io->write('<comment>Moved old ' . static::LOCAL_XML . ' to a new file name.</comment>', true);
        }

        $parametersFile = static::getFilePath([
            static::getConfigValue('directories/config-mage-xml'),
            static::$target['target'],
            'install.yml'
        ]);
        static::fileExists($parametersFile);

        $newPassword = md5(mt_rand() . uniqid() . time());

        $parameters = Yaml::parse($parametersFile);
        $connection = static::$localXml->global->resources->default_setup->connection;

        $installParams = [
            'license_agreement_accepted' => 'yes',
            'encryption_key'             => (string)static::$localXml->global->crypt->key,
            'db_host'                    => (string)$connection->host,
            'db_name'                    => (string)$connection->dbname,
            'db_user'                    => (string)$connection->username,
            'db_pass'                    => (string)$connection->password,
            'admin_password'             => $newPassword,
            'skip_url_validation'        => 'yes',
        ];

        $installParams = array_merge($parameters, $installParams);

        $installParamsString = [];
        foreach ($installParams as $key => $value) {
            $installParamsString[] = '--' . $key . ' ' . escapeshellarg($value);
        }

        $exec = sprintf('php -f %s/install.php -- %s',
            static::$magentoRootDir,
            implode(' ', $installParamsString)
        );

        static::executeCommand($exec);
        static::$io->write('<comment>Your backend admin access user/pass is: ' . $installParams['admin_username'] . ' / ' . $installParams['admin_password']
            . '</comment>', true);
    }

    /**
     * @return array
     */
    private static function getCryptConfig()
    {
        return [
            'key'  => ((string)static::$localXml->global->crypt->key) . date('YmdH'),
            'file' => '/tmp/mageComposerInstaller.ser',
        ];
    }

    /**
     * @return bool|int
     */
    protected static function persistUserData()
    {
        if (true === static::$isRelease) {
            return true;
        }

        $cfg                                = static::getCryptConfig();
        static::$dbConfig['dbTablesExists'] = static::$dbTablesExists;
        $data                               = serialize(static::$dbConfig);
        return file_put_contents($cfg['file'],
            base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($cfg['key']), $data, MCRYPT_MODE_CBC, md5(md5($cfg['key']))))
        );
    }

    /**
     * @return array|bool
     * @throws \Exception
     */
    protected static function getPersistedUserData()
    {
        if (empty(static::$localXml) || !(static::$localXml->gobal instanceof \SimpleXMLElement)) {
            throw new \Exception(static::LOCAL_XML . ' not loaded!');
        }
        $cfg = static::getCryptConfig();

        if (!file_exists($cfg['file'])) {
            return false;
        }

        $encrypted = file_get_contents($cfg['file']);
        @unlink($cfg['file']);
        $return = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($cfg['key']), base64_decode($encrypted), MCRYPT_MODE_CBC, md5(md5($cfg['key']))), "\0");
        if (empty($return)) {
            return false;
        }
        $return = @unserialize($return);

        return is_array($return) ? $return : false;
    }

    /**
     * @param $command
     */
    protected static function runN98Mage($command)
    {
        static::executeCommand(self::N98_MAGRRUN_CMD . $command, true);
    }

    /**
     * @todo refactor all of that and put it into a class
     */
    const DEFAULT_SCOPE = 'default';
    const WEBSITES_SCOPE = 'websites';
    const STORES_SCOPE = 'stores';

    /**
     * loads the yaml config values into the database OR if we're building a release writes them into a n98 script file
     */
    protected static function importCoreConfigData()
    {

        $n98ScriptFile = static::$workDir . '/' . static::getConfigValue('n98-script/file');
        $baseFolder    = static::getConfigValue('directories/config-mage-core');
        $envPath       = static::getConfigValue('targets/' . static::$target['target'] . '/config-path');;

        if (empty($envPath)) {
            throw new \InvalidArgumentException('Cannot find config path to to environment: ' . static::$target['target']);
        }

        $info = false === static::$isRelease
            ? '<info>Setting Magento config values ...</info>'
            : '<info>Writing Magento config values to file: ' . $n98ScriptFile . '</info>';

        static::$io->write($info, true);

        if (true === static::$isRelease) {
            static::runN98Mage('hs:ccd:convert ' . $baseFolder . ' ' . $envPath . ' --export-file=' . $n98ScriptFile);
            $path  = static::getConfigValue('mage-config-path-version');
            $value = static::$releaseVersion;
            static::writeN98Mage('config:set "' . $path . '" "' . $value . '"');
        } else {
            static::runN98Mage('hs:ccd:import --quiet ' . $baseFolder . ' ' . $envPath);
        }
    }

    /**
     * can be disabled when setting key database-backup to false in target.json
     *
     * @return bool
     */
    protected static function backupDataBase()
    {
        if (isset(static::$target['database-backup']) && false === static::$target['database-backup']) {
            static::$io->write('<info>DB Backup disabled!</info>', true);
            return false;
        }

        $dbBackUpDir = static::getConfigValue('directories/db-backup');
        if (false === static::$isRelease && (empty($dbBackUpDir) || !is_dir($dbBackUpDir))) {
            static::$io->write('<warning>DB Backup failed! Directory not found.</warning>', true);
            return false;
        }

        $fn  = date('Y-m-d_His') . '@' . (isset(static::$dbConfig['db_name']) ? static::$dbConfig['db_name'] : 'pre-' . static::$releaseVersion) . '.sql.gz';
        $cmd = 'db:dump --force --compression=gzip --add-time --strip="@stripped" ' . escapeshellarg($dbBackUpDir . '/' . $fn);

        if (true === static::$isRelease) {
            // check if backup dir exists, if not create it
            static::writeN98Mage('! ([[ ! -d ' . $dbBackUpDir . ' ]] && mkdir -p ' . $dbBackUpDir . ')');
            static::writeN98Mage($cmd);
        } else {
            static::runN98Mage($cmd);
        }

        return true;
    }

    /**
     *
     * @return bool
     */
    protected static function copyMagentoSource()
    {
        $mageSource = rtrim(static::getConfigValue('magento-copy-src'), '/');
        if (empty($mageSource) || !is_dir($mageSource)) {
            static::$io->write('<comment>Cannot copy Magento source files. Config empty.</comment>', true);
            return false;
        }

        try {
            static::executeCommand('cp -a ' . $mageSource . '/.ht* ' . static::$magentoRootDir . '/', true); // also copy dotfiles
            static::executeCommand('cp -a ' . $mageSource . '/* ' . static::$magentoRootDir . '/', true);
        } catch (\RuntimeException $e) {
            static::$io->write('<warning>Copying of some Magento source files failed due to permissions ...</warning>', true);
        }

        /* now deploying all magento modules */
        static::executeCommand(static::$composerBinDir . '/composerCommandIntegrator.php magento-module-deploy', true);

        /* so run the after copy/deploy command for some final hacks */
        $magentoAfterCopyCmd = static::getConfigValue('magento-after-copy-cmd');
        if (!empty($magentoAfterCopyCmd) && is_array($magentoAfterCopyCmd) && count($magentoAfterCopyCmd) > 0) {
            foreach ($magentoAfterCopyCmd as $cmd) {
                static::executeCommand($cmd, true);
            }
        }

        return true;
    }

    /**
     * adds excluding folders to phpstorm
     *
     * @return bool
     */
    protected static function updatePhpStorm()
    {
        $configurator = new PhpStormConfigurator();
        $configurator->setRootDir(static::$magentoRootDir);
        $configurator->setVendorDir(static::$vendorDir);
        $config = isset(static::$magentoInstallerConfig['phpstorm']) ? static::$magentoInstallerConfig['phpstorm'] : [];

        if (isset(static::$target['phpstorm']) && is_array(static::$target['phpstorm']) && count(static::$target['phpstorm']) > 0) {
            $config = array_merge_recursive($config, static::$target['phpstorm']);
        }
        $configurator->setConfig($config);

        $result  = [];
        $result1 = $configurator->addGitRoots();
        if ($result1 !== false) {
            $result = array_merge($result, $result1); // @todo fix that, what?
        }
        $result2 = $configurator->addExcludedFolders();
        if ($result2 !== false) {
            $result = array_merge($result, $result2); // @todo fix that, what?
        }

        if (false !== $result) {
            foreach ($result as $write) {
                static::$io->write('<' . $write['type'] . '>' . $write['msg'] . '</' . $write['type'] . '>', true);
            }
        }
        return true;
    }

    /**
     * @param $command
     *
     * @return int
     */
    protected static function writeN98Mage($command)
    {
        return file_put_contents(static::getN98MageScriptFile(), $command . "\n", FILE_APPEND);
    }

    /**
     * @return string
     */
    protected static function getN98MageScriptFile()
    {
        return static::$workDir . '/' . static::getConfigValue('n98-script/file');
    }

    /**
     * Replaces all occurrences of __VERSION__ with the release version in the n98 script file.
     */
    protected static function setVersionN98Mage()
    {
        if (true === static::$isRelease) {
            $written = file_put_contents(
                static::getN98MageScriptFile(),
                str_replace(
                    file_get_contents(static::getN98MageScriptFile()),
                    '__VERSION__',
                    static::$releaseVersion
                )
            );
            if (false === $written) {
                throw new \Exception('Cannot write to file: ' . static::getN98MageScriptFile());
            }
        }
    }

    /**
     * @return bool
     */
    protected static function removeN98MageFile()
    {
        @unlink(static::$workDir . '/' . static::getConfigValue('n98-script/file'));
        return true;
    }

    /**
     * generates final steps for a release.
     * release can only be created via composer install and on a branch which starts with: release-
     */
    protected static function generateRelease()
    {
        static::updateGitIgnore();

        static::writeN98Mage('cache:clean');
        static::writeN98Mage('cache:flush');
        static::writeN98Mage('! touch ' . static::getConfigValue('n98-script/success-flag'));
        static::setVersionN98Mage();

        static::releaseCompactComposerJson();
        static::releaseUpdateReadme();
        static::createArchiverScript();

        /**
         * this trick could be awesome ... because having trouble of merging the created release branch into a staging/master
         * branch. because both branches contains updates a merge often ends in a conflict even when using "-X theirs" and also
         * removed files in the release branch will not be removed in the staging/master branch.
         *
         * the trick is here: create everything in the release branch and then zip it. (not possible with git stash)
         * after checking out the staging/master branch we set the .git directory to unreadable by changing the chmod.
         * That means git cannot track anymore any changes.
         * after removing the magento-root-folder and unzipping the archive we change .git folder to be readable again.
         * git can now track any changes and we see via git status all the modifications.
         * so we add them all and commit
         *
         * would be nice to get all the commit messages of the vendor folder. the difference of the sha1 between composer.lock before and after
         */
        $gitLog         = static::getConfigValue('directories/data') . DIRECTORY_SEPARATOR . 'git.' . static::$releaseVersion . '.log';
        $tmpArchiveName = 'new-release.tar';
        $commandChain   = [
            'git add --all .',
            'git commit -a -m \'Creating Release ' . static::$releaseVersion . '\'',
            'git archive --format=tar -o ' . $tmpArchiveName . ' HEAD',
            'git checkout ' . static::$releaseGitBranchName,
            'chmod 000 .git',
            'rm -Rf ' . static::$magentoRootDir,
            'tar xf ' . $tmpArchiveName,
            'openssl dgst -sha256 ' . $tmpArchiveName . ' > ' . $tmpArchiveName . '.sha256',
            'rm -f ' . $tmpArchiveName,
            'chmod 700 .git',
            'echo "Current GIT HEAD: " `git rev-parse HEAD` >> ' . $tmpArchiveName . '.sha256',
            'git add --all .',
            'git commit -a -m \'Creating Release ' . static::$releaseVersion . '\'',
            'git branch -D ' . static::getCurrentGitBranch(),
        ];

        foreach ($commandChain as $command) {
            file_put_contents($gitLog, $command . "\n", FILE_APPEND);
            $logging = ' >> ' . $gitLog . ' 2>&1';
            if (strstr($command, '>') !== false) {
                $logging = '';
            }
            static::executeCommand($command . $logging, true);
        }
        static::$io->write('<info>Wrote git output to file: ' . $gitLog . '</info>');

        $hints = [

            /* STAGING */
            'staging'    => '# Please run the following commands:

git push origin staging

# If you use git push deployment, run:
# git push staging-server staging
',
            /* PRODUCTION */
            'production' => '# Please run the following commands:

# Tag the release
git tag -a -m \'Tagging Release ' . static::$releaseVersion . '\' v' . static::$releaseVersion . '

# Push tags to remote server
git push && git push --tags

# Create tarball for deployment on production server.
./' . static::getConfigValue('archive/script-name') . '

# Upload the zip file to the production server and run the release script.
',
        ];

        static::$io->write('<info>' . (isset($hints[static::$target['target']])
                ? $hints[static::$target['target']]
                : 'Wow no hint found!')
            . '</info>', true);
    }

    /**
     * writes the version number and release date into the README.md file
     * you can use the two variables {{version}} and {{date}} in the README.md file
     */
    protected static function releaseUpdateReadme()
    {
        if (true === file_exists(static::getConfigValue('readme'))) {
            $readme = file_get_contents(static::getConfigValue('readme'));
            $readme = str_replace([
                '{{version}}',
                '{{date}}'
            ], [
                static::$releaseVersion,
                date('Y-m-d H:i:s')
            ], $readme);
            file_put_contents(static::getConfigValue('readme'), $readme);
        }
    }

    /**
     * removes all entries from the json file and keeps only the extra key
     * reason: we have the lock file for fixing versions and do not want that anybody can
     * download composer.phar and run something evil.
     */
    protected static function releaseCompactComposerJson()
    {
        $json = json_decode(file_get_contents(static::COMPOSER_JSON), true);;
        $removeKeys = ['require', 'require-dev', 'scripts', 'repositories', 'config'];
        foreach ($removeKeys as $key) {
            if (isset($json[$key])) {
                unset($json[$key]);
            }
        }
        file_put_contents(static::COMPOSER_JSON, json_encode($json, JSON_PRETTY_PRINT));
        static::$io->write('<info>Removed these keys (' . implode(', ', $removeKeys) . ') from ' . static::COMPOSER_JSON . ' for security reasons.</info>', true);
    }

    /**
     * creates the archiver script mainly for creating a production tarball
     */
    protected static function createArchiverScript()
    {

        $includeFolders    = static::getConfigValue('archive/include');
        $tarIncludeFolders = !empty($includeFolders) && is_array($includeFolders)
            ? $includeFolders
            : '.';

        $targetTarBall = static::getConfigValue('directories/data') . DIRECTORY_SEPARATOR . static::$target['target'] . '-' . static::$releaseVersion . '.tgz';

        $content = "#!/bin/bash\n" . 'tar -zcf ' . $targetTarBall . ' ' . implode(' ', $tarIncludeFolders) . "\n";

        file_put_contents(static::getConfigValue('archive/script-name'), $content);
        chmod(static::getConfigValue('archive/script-name'), 0700);
    }

    /**
     * returns the current branch we are on
     * git branch --no-color <== alternative solution
     * @return string
     */
    protected static function getCurrentGitBranch()
    {
        if (null !== static::$currentGitBranchName) {
            return static::$currentGitBranchName;
        }
        $gitHead = static::$workDir . '/.git/HEAD';
        static::fileExists($gitHead);
        static::$currentGitBranchName = str_replace('ref: refs/heads/', '', trim(file_get_contents($gitHead)));
        return static::$currentGitBranchName;
    }

    /**
     * removes the config folder and adds that one plus some others to the gitignore file
     */
    protected static function updateGitIgnore()
    {
        /**
         * configuration folder is not needed in any release version
         */
        static::executeCommand('git rm --cached -r ' . static::getConfigValue('directories/config') . ' > /dev/null  2>&1', true);

        $rmMageRoot    = static::$magentoRootDir . '/';
        $gitIgnoreName = static::$workDir . '/.gitignore';
        static::fileExists($gitIgnoreName);
        $gitIgnore = file($gitIgnoreName);
        $found     = false;
        foreach ($gitIgnore as $index => &$gitLine) { // remove line breaks in the next line
            $gitLine = trim($gitLine);
            if (stristr($gitLine, static::$magentoRootDir) !== false) {
                $found = true;
                unset($gitIgnore[$index]); // can occur multiple times
            }
        }

        if (false === $found) {
            static::$io->write('<warning>Cannot find folder ' . $rmMageRoot . ' in .gitignore file! Please remove by yourself.</warning>');
        } else {
            static::$io->write('<info>Removed folder ' . $rmMageRoot . ' from .gitignore file.</info>');
        }

        $gitIgnore[] = '/' . static::getConfigValue('directories/config');
        $gitIgnore[] = '/composer.phar';
        $gitIgnore[] = static::getConfigValue('target-file');
        $written     = (int)file_put_contents($gitIgnoreName, implode("\n", $gitIgnore));

        if ($written === 0) {
            static::$io->write('<error>Cannot write to .gitignore file!</error>');
        }
    }

    /**
     * @param bool $throwException
     *
     * @throws Exceptions\BranchNotFound
     * @throws \Exception
     */
    protected static function checkGitBranchValidSemVer($throwException = true)
    {
        $branchPrefix = static::getConfigValue('release-prefix-branch-name');

        if (empty($branchPrefix)) {
            throw new \Exception('Release Prefix branch name cannot be empty!');
        }

        $matches = [];
        $branch  = static::getCurrentGitBranch();
        $re      = '/^' . preg_quote($branchPrefix) .
            '(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)(?:-(?P<pres>[\da-z\-]+(?:\.[\da-z\-]+)*))?(?:\+(?P<posts>[\da-z\-]+(?:\.[\da-z\-]+)*))?$/i';
        if (preg_match($re, $branch, $matches) !== 1 && true === $throwException) {
            throw new Exceptions\BranchNotFound($branch, $branchPrefix);
        }
        if (!isset($matches[0])) {
            $matches = [$branch];
        }

        static::$releaseVersion = str_replace($branchPrefix, '', $matches[0]);
        static::$isRelease      = strstr($matches[0], $branchPrefix) !== false;

        static::$io->write('<info>Creating a release:</info> <warning>' . (static::$isRelease ? 'Yes' : 'No') . '</warning>');
    }

    /**
     * @param string $branchName
     *
     * @return bool
     */
    protected static function isLocalGitBranchAvailable($branchName)
    {
        $showRef = trim(shell_exec('git show-ref'));
        return strpos($showRef, 'refs/heads/' . $branchName) !== false;
    }

    /**
     * symlinks var, media and other folders
     * folders will be created in the script: composer.prebase.php this one does only the symlinking
     */
    protected static function handlePersistentDirectories()
    {
        $symlinks = static::getConfigValue('directories/symlinks');
        $dataDir  = static::getConfigValue('directories/data');

        if (empty($symlinks) || !is_array($symlinks)) {
            static::$io->write('<info>No directory found to symlink.</info>');
        }

        foreach ($symlinks as $directory) {
            $from = static::getFilePath([$dataDir, $directory]);
            $to   = static::getFilePath([static::$magentoRootDir, $directory]);

            if (true === is_dir($to)) {
                static::executeCommand('rm -Rf ' . $to, true); // remove directory
            }
            if (true === is_link($to)) {
                static::executeCommand('rm -f ' . $to, true); // remove symlink
            }

            // create symlink, but the ../ is ugly :-( but it must be relative
            static::executeCommand('ln -s ../' . $from . ' ' . $to, true);
        }
    }

    /**
     * removes unneeded magento modules
     *
     * @return mixed
     */
    protected static function removeModules()
    {
        $mrm = new ModuleRemover(static::$magentoRootDir, static::$io);

        $buildTarget = isset(static::$target['build-target']) ? trim(static::$target['build-target']) : '';
        if (true === empty($buildTarget)) {
            return static::$io->write('<info>build-target not defined. Cannot remove any modules.</info>');
        }

        $modules = static::getConfigValue('targets/remove-modules/' . $buildTarget);
        if (true === empty($modules)) {
            return static::$io->write('<info>build-target is defined but remove-modules node is empty. No modules have been removed.</info>');
        }
        foreach ($modules as $module) {
            $rmm = $mrm->remove($module);
            static::$io->write('<info>Removed modules: ' . implode(', ', $rmm) . '.</info>');
        }
    }
}
