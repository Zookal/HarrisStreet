<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet;

use MagentoHackathon\Composer\Magento\ModmanParser;

class PhpStormConfigurator
{
    protected $rootDir = '';
    protected $vendorDir = '';
    protected $config = array();

    /**
     * @param $file
     *
     * @return array|bool
     */
    protected function loadXmlFile($file)
    {
        if (false === file_exists($file)) {
            return false;
        }

        $xmlDeclaration = '<?xml version="1.0" encoding="UTF-8"?>';
        $content        = str_replace($xmlDeclaration, '', file_get_contents($file));;
        return array(
            'xml'  => new \SimpleXMLElement($xmlDeclaration . "\n" . '<stormroot>' . $content . '</stormroot>'),
            'file' => $file
        );
    }

    /**
     * @param $file
     * @param $xmlContent
     *
     * @return int
     */
    protected function writeXmlFile($file, $xmlContent)
    {
        $xmlContent = str_replace(array('<stormroot>', '</stormroot>'), '', $xmlContent);
        return file_put_contents($file, $xmlContent);
    }

    /**
     * @return array|int
     */
    protected function getPhpStormIml()
    {
        $modulesFile = '.idea/modules.xml';
        if (false == file_exists($modulesFile)) {
            return false;
        }
        $modules = simplexml_load_file($modulesFile);

        if (isset($modules->component) && isset($modules->component->modules->module)) {
            $attributes = $modules->component->modules->module->attributes(); // @todo possible bug
            if (isset($attributes['filepath'])) {
                $file = str_replace('$PROJECT_DIR$/', '', (string)$attributes['filepath']);
                if (!file_exists($file)) {
                    return false;
                }
                return $this->loadXmlFile($file);
            }
        }
        return false;
    }

    /**
     * @return array|bool
     */
    public function addExcludedFolders()
    {
        $return = array();

        $stormDir = '.idea';
        if (!is_dir($stormDir)) {
            return false;
        }

        $iml = $this->getPhpStormIml();
        if (false === $iml || !isset($iml['xml']->module)) {
            $return[] = array('msg' => 'PhpStorm .iml file not found.', 'type' => 'info');
            return $return;
        }

        $existingNodes = array();
        foreach ($iml['xml']->module->component->content->children() as $child) {
            $attributes          = $child->attributes();
            $url                 = (string)$attributes['url'];
            $existingNodes[$url] = $url;
        }

        $vendorDirs = explode("\n", trim(shell_exec('find ' . $this->getVendorDir() . ' -depth 2 -type d -print')));
        foreach ($vendorDirs as $secondLevelDir) {
            $attributeLink = 'file://$MODULE_DIR$/' . $secondLevelDir;
            if (is_dir($secondLevelDir) && !isset($existingNodes[$attributeLink]) && true === $this->isValidModuleDirForExclusion($secondLevelDir)) {
                /** @var \SimpleXMLElement $folder */
                $folder = $iml['xml']->module->component->content->addChild('excludeFolder');
                $folder->addAttribute('url', $attributeLink);
            }
        }

        // now also add those symlinks from magento root folder which are not exlcuded defined, reverse.
        // load per repo extra->map or modman file then add those folders to the exclusion of magento root folder
        $symlinks = $this->getSymlinksTargetsFromNonExcludedFolders();

        foreach ($symlinks as $linkTarget) {
            $dir           = $this->getRootDir() . DIRECTORY_SEPARATOR . $linkTarget;
            $attributeLink = 'file://$MODULE_DIR$/' . $dir;

            if (is_dir($dir) && !isset($existingNodes[$attributeLink])) {
                /** @var \SimpleXMLElement $folder */
                $folder = $iml['xml']->module->component->content->addChild('excludeFolder');
                $folder->addAttribute('url', $attributeLink);
            }
        }
        $xmlContent = $iml['xml']->asXML();

        if ($xmlContent !== false) {
            $this->writeXmlFile($iml['file'], $xmlContent);
            $return[] = array('msg' => 'PhpStorm .iml rewritten with new excluded folders!', 'type' => 'info');
        } else {
            $return[] = array('msg' => 'Failed to write PhpStorm .iml file.', 'type' => 'warning');
        }
        return $return;
    }

    /**
     * @return array
     */
    protected function getSymlinksTargetsFromNonExcludedFolders()
    {
        $nonExcludedModules = $this->getConfig('non-excluded-modules');
        $match              = true;
        if (null === $nonExcludedModules) {
            return array();
        }

        $moduleMappings = array();
        foreach ($nonExcludedModules as $repo) {
            $basePath         = $this->getVendorDir() . DIRECTORY_SEPARATOR . $repo . DIRECTORY_SEPARATOR;
            $repoComposerJson = $basePath . 'composer.json';
            $repoModman       = $basePath . 'modman';

            if (true === file_exists($repoComposerJson)) {
                $composerJson = json_decode(file_get_contents($repoComposerJson), true);
                if (isset($composerJson['extra']) && isset($composerJson['extra']['map']) && is_array($composerJson['extra']['map'])) {
                    $moduleMappings = array_merge($moduleMappings, $composerJson['extra']['map']);
                }
            }
            if (true === file_exists($repoModman)) {
                $modmanParser   = new ModmanParser($basePath);
                $mappings       = $modmanParser->getMappings();
                $moduleMappings = array_merge($moduleMappings, $mappings);
            }
        }

        $returnDirs = array();
        foreach ($moduleMappings as $mapped) {
            if (true === $this->isValidDirForExclusion($mapped[1])) {
                $returnDirs[] = $mapped[1];
            }
        }
        return $returnDirs;
    }

    /**
     * @param string $dirName
     *
     * @return bool
     */
    protected function isValidDirForExclusion($dirName)
    {
        $dirs = array(
            array('app', 'code'),
            array('lib'),
            array('shell'),
            array('js'),
        );

        $return = false;
        foreach ($dirs as $dir) {
            $return = strpos($dirName, implode(DIRECTORY_SEPARATOR, $dir) . DIRECTORY_SEPARATOR) !== false;
            if ($return === true) {
                break;
            }
        }
        return $return;
    }

    /**
     * @param string $dirName full path including the name of the vendor folder. e.g. vendor/magento/magento
     *
     * @return bool
     */
    protected function isValidModuleDirForExclusion($dirName)
    {
        $nonExcludedModules = $this->getConfig('non-excluded-modules');
        $match              = true;
        if (null === $nonExcludedModules) {
            return $match;
        }
        foreach ($nonExcludedModules as $nonExModule) {
            if (stristr($dirName, $this->getVendorDir() . DIRECTORY_SEPARATOR . $nonExModule) !== false) {
                $match = false;
                break;
            }
        }
        return $match;
    }

    /**
     * @return array
     */
    public function addGitRoots()
    {
        $dirPrefix = '$PROJECT_DIR$';
        $return    = array();
        $vcs       = $this->loadXmlFile('.idea/vcs.xml');
        if (empty($vcs) || !($vcs['xml'] instanceof \SimpleXMLElement)) {
            $return[] = array('msg' => 'PhpStorm vcs.xml not found.', 'type' => 'warning');
            return $return;
        }

        $currentDirs = array();
        foreach ($vcs['xml']->project->component->mapping as $mapping) {
            $attr                     = $mapping->attributes();
            $currentDir               = (string)$attr['directory'];
            $currentDirs[$currentDir] = rtrim($currentDir, '/');
        }

        $gitFolders = explode("\n", trim(shell_exec('find ' . $this->getVendorDir() . ' -type d -name .git -print')));
        // @todo check for name="VcsDirectoryMappings"
        foreach ($gitFolders as $folder) {
            $folder    = rtrim(DIRECTORY_SEPARATOR . str_replace('.git', '', $folder), '/');
            $addFolder = $dirPrefix . $folder;
            if (!isset($currentDirs[$addFolder]) && false === $this->isValidModuleDirForExclusion($folder)) {
                $mapped = $vcs['xml']->project->component->addChild('mapping');
                $mapped->addAttribute('directory', $addFolder);
                $mapped->addAttribute('vcs', 'Git');
            }
        }

        $xmlContent = $vcs['xml']->asXML();
        if ($xmlContent !== false) {
            $this->writeXmlFile($vcs['file'], $xmlContent);
            $return[] = array('msg' => 'PhpStorm vcs.xml added new GIT mappings', 'type' => 'info');
        } else {
            $return[] = array('msg' => 'Failed to write PhpStorm vcs.xml file.', 'type' => 'warning');
        }
        return $return;
    }

    /**
     * @param $rootDir
     *
     * @return $this
     */
    public function setRootDir($rootDir)
    {
        $this->rootDir = $rootDir;
        return $this;
    }

    /**
     * @return string
     */
    public function getRootDir()
    {
        return $this->rootDir;
    }

    /**
     * @param $vendorDir
     *
     * @return $this
     */
    public function setVendorDir($vendorDir)
    {
        $this->vendorDir = $vendorDir;
        return $this;
    }

    /**
     * @return string
     */
    public function getVendorDir()
    {
        return $this->vendorDir;
    }

    /**
     * @param array $config
     *
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * @return array
     */
    public function getConfig($key = null)
    {
        if (null !== $key) {
            return isset($this->config[$key]) ? $this->config[$key] : null;
        }
        return $this->config;
    }
}
