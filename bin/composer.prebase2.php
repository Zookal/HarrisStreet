<?php

/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */
class PreBase
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
     * 1. ....
     */
    public function run()
    {
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
}

$rel = new PreBase($argv);
$rel->run();
