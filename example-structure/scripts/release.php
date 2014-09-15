<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

/**
 * this script runs on the production server to unpack the tgz file of the current production version
 */
class Releaser
{
    private $_argv = null;
    protected $_wwwRoot = null;
    protected $_symlinkTarget = null;
    protected $_maintenanceFolder = null;
    protected $_mageRunCfg = array();
    protected $_tarFileName = '';
    protected $_version = null;

    public function __construct($argv)
    {
        $this->_argv        = $argv;
        $this->_tarFileName = isset($argv[1]) ? $argv[1] : '';
    }

    protected function _initVersion()
    {
        $matches = array();
        $re      = '/(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)(?:-(?P<pres>[\da-z\-]+(?:\.[\da-z\-]+)*))?(?:\+(?P<posts>[\da-z\-]+(?:\.[\da-z\-]+)*))?/i';
        if (preg_match($re, $this->_tarFileName, $matches) !== 1 || !isset($matches[0])) {
            $this->_print("Usage: php {$this->_argv[0]} aPrefix-<semver>.tgz\n", true);
        }
        $this->_version = 'v' . str_replace(array('.tar.gz', '.tgz', '.zip'), '', strtolower($matches[0]));
    }

    protected function _loadComposerJson()
    {
        $jsonFile = $this->_path([$this->_version, 'composer.json']);
        if (false === file_exists($jsonFile)) {
            $this->_print($jsonFile . ' file not found!', true);
        }
        $composerConfig = json_decode(file_get_contents($jsonFile), true);

        if (!isset($composerConfig['extra']) || !isset($composerConfig['extra']['magento-installer-config'])) {
            $this->_print($jsonFile . ' is corrupt! Some values are missing!', true);
        }

        $this->_mageRunCfg        = array(
            'script' => $composerConfig['extra']['magento-installer-config']['n98-script']['file'],
            'flag'   => $composerConfig['extra']['magento-installer-config']['n98-script']['success-flag'],
        );
        $this->_wwwRoot           = trim($composerConfig['extra']['magento-root-dir'], '/');
        $this->_maintenanceFolder = trim($composerConfig['extra']['magento-installer-config']['maintenance-folder'], '/');
        $this->_symlinkTarget     = trim($composerConfig['extra']['magento-installer-config']['current-version-symlink-name'], '/');
    }

    /**
     * main method
     * 1. check if version as folder exists, if not extract tar file
     * 2. load config from composer.json file found vX.Y.Z/composer.json
     * 3. removes symlink
     * 4. creates new symlink to maintenance folder e.g.: vX.Y.Z/htdocs/maintenance (because nginx goes to htdocs)
     * 5. Runs the backup and config import via n98
     * 6. checks the flag file if n98 was successful
     * 7. if so remove the symlink to maintenance and symlink it to the version
     */
    public function run()
    {
        $this->_initVersion();

        if (false === is_dir($this->_version)) {
            $this->_runCmd('mkdir ' . $this->_version);
            $this->_runCmd('tar xzf ' . $this->_tarFileName . ' -C ' . $this->_version);
        } else {
            $this->_print('Folder ' . $this->_version . ' already exists ...');
        }

        $this->_loadComposerJson();

        $maintenanceRealTarget = $this->_path([$this->_version, $this->_wwwRoot, $this->_maintenanceFolder]);
        $maintenanceWwwTarget  = $this->_path([$this->_version, $this->_wwwRoot, $this->_maintenanceFolder, $this->_wwwRoot]);
        if (false === is_dir($maintenanceWwwTarget)) {
            $this->_print('Maintenance folder did not exists: ' . $maintenanceRealTarget, true);
        }

        if (false === file_exists($this->_path([$this->_version, $this->_mageRunCfg['script']]))) {
            $this->_print('Magerun Script not found in location: ' . $this->_version . DIRECTORY_SEPARATOR . $this->_mageRunCfg['script'], true);
        }

        $this->_runCmd('rm -Rf ' . $this->_symlinkTarget);
        $this->_runCmd('ln -s ' . $maintenanceRealTarget . ' ' . $this->_symlinkTarget);

        $this->_print('Running backup and environment import via n98-magerun ...');
        $this->_runCmd98('script ./' . $this->_mageRunCfg['script']);

        // run incremental updates
        $this->_runCmd98('cache:clean');
        $this->_runCmd98('cache:flush');
        $this->_runCmd98('sys:setup:incremental -n'); // -n === --no-interaction

        if (true === file_exists($this->_path([$this->_version, $this->_mageRunCfg['flag']]))) {
            $this->_runCmd('rm -Rf ' . $this->_symlinkTarget);
            $this->_runCmd('ln -s ' . $this->_version . ' ' . $this->_symlinkTarget);
            $this->_print('Done!');
        } else {
            $this->_print('ERROR in n98-magerun! Maintenance site is still up!');
        }
    }

    protected function _path(array $path)
    {
        return implode(DIRECTORY_SEPARATOR, $path);
    }

    protected function _print($str, $die = false)
    {
        echo $str . PHP_EOL;
        if (true === $die) {
            exit;
        }
    }

    protected function _runCmd($cmd)
    {
        $this->_print($cmd);
        echo shell_exec($cmd);
    }

    protected function _runCmd98($cmd)
    {
        $this->_runCmd('cd ' . $this->_version . ' && php -f n98-magerun.phar -- ' . $cmd);
    }
}

$rel = new Releaser($argv);
$rel->run();
