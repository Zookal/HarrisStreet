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
    protected $_isRelease = false;
    protected $_gitReleaseBranchName = '';
    protected $_dataDir = '';
    protected $_dataSubDirs = array();

    public function __construct($argv)
    {
        $this->_argv = $argv;
    }

    /**
     * main method
     * 1. load json files
     * 2. check if release branch
     * 3. create directories
     * 4. move htdocs folder to another location
     */
    public function run()
    {
        $this->_loadComposerJson();
        //$this->_checkRelease();

        if (false === is_dir($this->_dataDir)) {
            mkdir($this->_dataDir, 0751);
            $this->_print('Created dir ' . $this->_dataDir);
        }

        foreach ($this->_dataSubDirs as $subDir) {
            $dir = $this->_path(array($this->_dataDir, $subDir));
            if (false === is_dir($dir)) {
                if (false === mkdir($dir, 0755, true)) {
                    $this->_print('Failed to create dir:' . $dir, true);
                }
            }
        }
        /**
         * move magento root folder to data folder for backup reasons only
         */
        if (true === is_dir($this->_wwwRoot)) {
            $newRootDir = $this->_path(array($this->_dataDir, $this->_wwwRoot . '_' . date('Y-m-d_His')));
            if (false === rename($this->_wwwRoot, $newRootDir)) {
                $this->_print('Failed to move directory from :' . $this->_wwwRoot . ' to: ' . $newRootDir);
            }
            mkdir($this->_wwwRoot, 0755, true);
            touch($this->_wwwRoot . DIRECTORY_SEPARATOR . '.gitempty');
        }
    }

    /**
     * loads the composer.json and target.json file
     */
    protected function _loadComposerJson()
    {
        $jsonFile = 'composer.json';
        if (false === file_exists($jsonFile)) {
            $this->_print($jsonFile . ' file not found!', true);
        }
        $composerConfig = json_decode(file_get_contents($jsonFile), true);

        if (!isset($composerConfig['extra']) || !isset($composerConfig['extra']['magento-installer-config'])) {
            $this->_print($jsonFile . ' is corrupt! Some values are missing!', true);
        }

        $target = json_decode(file_get_contents($composerConfig['extra']['magento-installer-config']['target-file']), true);
        if (true === empty($target)) {
            $this->_print('target.json file is corrupt or not found!', true);
        }

        $this->_isRelease            = stristr($target['target'], 'dev') === false;
        $this->_gitReleaseBranchName = trim($composerConfig['extra']['magento-installer-config']['release-prefix-branch-name']);

        if (true === empty($this->_gitReleaseBranchName)) {
            $this->_print('release-prefix-branch-name cannot be empty!', true);
        }

        $this->_wwwRoot     = trim($composerConfig['extra']['magento-root-dir'], '/');
        $this->_dataDir     = $composerConfig['extra']['magento-installer-config']['directories']['data'];
        $this->_dataSubDirs = $composerConfig['extra']['magento-installer-config']['directories']['data-sub-dirs'];

        if (false === is_array($this->_dataSubDirs) || 0 === count($this->_dataSubDirs)) {
            $this->_print('data-sub-dirs must be an array!', true);
        }
    }

    /**
     * checks if the branch name starts with e.g. release-
     */
    protected function _checkRelease()
    {
        if (true === $this->_isRelease) {
            $currentGitBranchName = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
            if (true === empty($currentGitBranchName)) {
                $this->_print('Failed to figure out the current git branch name', true);
            }
            if (false === strstr($currentGitBranchName, $this->_gitReleaseBranchName)) {
                $this->_print('You are creating a release but your branch name is ' . $currentGitBranchName . ' but must start with ' .
                    $this->_gitReleaseBranchName, true);
            }
        }
    }

    /**
     * @param array $path
     *
     * @return string
     */
    protected function _path(array $path)
    {
        return implode(DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @todo nicer output on the CLI as this output will be overseen easily :-(
     *
     * @param string $str
     * @param bool   $die
     */
    protected function _print($str, $die = false)
    {
        echo PHP_EOL . $str . PHP_EOL . PHP_EOL;
        if (true === $die) {
            exit(2);
        }
    }
}

$rel = new PreBase($argv);
$rel->run();
