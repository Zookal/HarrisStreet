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
use Zookal\HarrisStreet\Exceptions;

class ModuleRemover
{
    const MAGE_ADMINHTML = 'Mage_Adminhtml';
    const ALL_INACTIVE   = 'all-inactive';

    /**
     * @var string
     */
    protected $_rootFolder = null;

    /**
     * @var array
     */
    protected $_moduleCodePoolCache = array();

    /**
     * @var string
     */
    protected $_currentModuleName = null;

    /**
     * @var \SimpleXMLElement
     */
    protected $_currentModuleConfigXml = null;

    /**
     * @param   string $magentoRootDir
     */
    public function __construct($magentoRootDir)
    {
        $this->_rootFolder = rtrim($magentoRootDir, DIRECTORY_SEPARATOR);
    }

    /**
     * @param $moduleName
     *
     * @return array
     */
    public function remove($moduleName)
    {
        $removedModules = array();
        $modules        = $this->_getInActiveModules();
        if (self::ALL_INACTIVE === strtolower($moduleName)) {
            foreach ($modules as $module) {
                $removedModules[] = $this->_remove($module);
            }
        } else {
            $removedModules[] = $this->_remove($moduleName);
        }
        return $removedModules;
    }

    /**
     * @param $moduleName
     *
     * @return string
     */
    protected function _remove($moduleName)
    {
        $this->_currentModuleName = $moduleName;
        $this->_loadConfigXml();

        if (null === $this->_currentModuleConfigXml) {
            return 'Not removed: ' . $moduleName;
        }

        $layoutFiles = $this->_getLayoutUpdateFiles();
        $allFiles    = array_merge($this->_getTranslationFiles(), $layoutFiles, $this->_getModuleDirectoriesFiles(), $this->_getTemplateFiles($layoutFiles));

        foreach ($allFiles as $file) {
            $this->_removeReal($file);
        }
        return $moduleName;
    }

    /**
     * @param $pathToFile
     */
    protected function _removeReal($pathToFile)
    {
        $cmd = 'rm -Rf ' . $pathToFile;
        trim(shell_exec($cmd));
    }

    /**
     * @throws \Exception
     */
    protected function _loadConfigXml()
    {
        $parts                         = $this->_getNsMn();
        $file                          = $this->_path($this->_rootFolder, 'app', 'code',
            $this->_moduleCodePoolCache[$this->_currentModuleName], $parts[0], $parts[1], 'etc', 'config.xml');
        $this->_currentModuleConfigXml = null;
        if (true === file_exists($file)) {
            $this->_currentModuleConfigXml = simplexml_load_file($file);
        }
    }

    /**
     * @return array
     */
    protected function _getTranslationFiles()
    {
        $files = array();
        foreach (array('frontend', 'adminhtml') as $area) {
            $modules = $this->_currentModuleConfigXml->xpath($area . '/translate/modules/' . $this->_currentModuleName . '/files');
            foreach ($modules as $fem) {
                /** @var $fem \SimpleXMLElement */
                foreach ($fem as $fileName) {
                    $filePath         = $this->_path($this->_rootFolder, 'app', 'locale', '*', (string)$fileName);
                    $files[$filePath] = $filePath;
                }
            }
        }
        $globFiles = array_values($files);
        $return    = array();
        foreach ($globFiles as $globFile) {
            $return += glob($globFile);
        }

        return $return;
    }

    /**
     * @return array
     */
    protected function _getLayoutUpdateFiles()
    {
        $files = array();
        foreach (array('frontend', 'adminhtml') as $area) {
            $modules = $this->_currentModuleConfigXml->xpath($area . '/layout/updates');
            foreach ($modules as $fem) {
                /** @var $fem \SimpleXMLElement */
                foreach ($fem as $fileName) {
                    $layoutFile       = (string)$fileName->file;
                    $area2            = 'frontend' === $area ? 'base' : 'default';
                    $filePath         = $this->_path($this->_rootFolder, 'app', 'design', $area, $area2, 'default', 'layout', $layoutFile);
                    $files[$filePath] = $filePath;
                }
            }
        }

        return array_values($files);
    }

    /**
     *
     * @param array $layoutFiles
     *
     * @return array
     */
    protected function _getTemplateFiles(array $layoutFiles)
    {
        $files = array();
        if (0 === count($layoutFiles)) {
            return $files;
        }
        foreach ($layoutFiles as $layoutFile) {
            //$xmlString    = file_get_contents($layoutFile);
            $securePrefix = basename($layoutFile, '.xml');

            $area               = strpos($layoutFile, '/frontend/') !== false ? 'frontend' : 'adminhtml';
            $area2              = 'frontend' === $area ? 'base' : 'default';
            $folderPath         = $this->_path($this->_rootFolder, 'app', 'design', $area, $area2, 'default', 'template', $securePrefix);
            $files[$folderPath] = $folderPath;
            /* too dangerous ...
             * to detect files via regex each template file must start with the string of basename(layoutfile.xml,'.xml')
             * otherwise we will match things like page/1column.phtml or catalog/product/list.phtml ... in other files
            $matches      = array();
            preg_match_all('~(' . $securePrefix . '[a-z0-9\-_/]+\.phtml)~i', $xmlString, $matches, PREG_SET_ORDER);
            foreach($matches as $match){
                if(isset($match[1])){
                    $files[$match[1]] = $match[1];
                }
            } */
        }

        return array_values($files);
    }

    /**
     * @return array
     */
    protected function _getModuleDirectoriesFiles()
    {
        $parts    = $this->_getNsMn();
        $return   = array();
        $return[] = $this->_path($this->_rootFolder, 'app', 'etc', 'modules', $this->_currentModuleName . '.xml');
        $return[] = $this->_path($this->_rootFolder, 'app', 'code', $this->_moduleCodePoolCache[$this->_currentModuleName], $parts[0], $parts[1]);

        /**
         * remove everything adminhtml related
         */
        if (self::MAGE_ADMINHTML === $this->_currentModuleName) {
            $return = array_merge(
                $return,
                $this->_getFindResult('Adminhtml'),
                $this->_getFindResult('adminhtml*'),
                $this->_getFindResult('system.xml')
            );
        }

        return $return;
    }

    /**
     * @param $name
     *
     * @return array
     */
    protected function _getFindResult($name)
    {
        $return = trim(shell_exec('find ' . $this->_rootFolder . ' -name "' . $name . '" -print0'));
        return explode("\0", $return);
    }

    /**
     * get NameSpace ModuleName
     *
     * @return array
     * @throws \Exception
     */
    protected function _getNsMn()
    {
        $parts = explode('_', $this->_currentModuleName);
        if (count($parts) !== 2) {
            throw new \Exception("Problem determining namespace and modulename for module: $this->_currentModuleName");
        }
        return $parts;
    }

    /**
     * @return array
     */
    protected function _getInActiveModules()
    {
        $moduleFiles = $this->_getDeclaredModuleFiles();

        $inActiveModules = array();
        foreach ($moduleFiles as $file) {
            $inActiveModules = array_merge($inActiveModules, $this->_getInActiveModulesFromFile($file));
        }
        return $inActiveModules;
    }

    /**
     * @param string $file
     *
     * @return array
     */
    protected function _getInActiveModulesFromFile($file)
    {
        $xml = simplexml_load_file($file);
        /** @var \SimpleXMLElement $modules */
        $modules = $xml->modules;

        $return = array();
        foreach ($modules->children() as $module) {
            /** @var $module \SimpleXMLElement */
            $isActive = (string)$module->active === 'true';
            if (false === $isActive) {
                $return[$module->getName()] = $module->getName();
            }
            if (!isset($this->_moduleCodePoolCache[$module->getName()])) {
                $pool                                           = (string)$module->codePool;
                $this->_moduleCodePoolCache[$module->getName()] = empty($pool) ? 'core' : $pool;
            }
        }
        return $return;
    }

    /**
     * Retrieve Declared Module file list
     *
     * @return array
     */
    protected function _getDeclaredModuleFiles()
    {
        $etcDir      = $this->_path($this->_rootFolder, 'app', 'etc', 'modules', '*.xml');
        $moduleFiles = glob($etcDir);

        if (!$moduleFiles) {
            return false;
        }

        $collectModuleFiles = array(
            'base'   => array(),
            'mage'   => array(),
            'custom' => array()
        );

        foreach ($moduleFiles as $v) {
            $name = explode(DIRECTORY_SEPARATOR, $v);
            $name = substr($name[count($name) - 1], 0, -4);

            if ($name == 'Mage_All') {
                $collectModuleFiles['base'][] = $v;
            } else if (substr($name, 0, 5) == 'Mage_') {
                $collectModuleFiles['mage'][] = $v;
            } else {
                $collectModuleFiles['custom'][] = $v;
            }
        }

        return array_merge(
            $collectModuleFiles['base'],
            $collectModuleFiles['mage'],
            $collectModuleFiles['custom']
        );
    }

    /**
     * @return string
     */
    protected function _path()
    {
        $path = func_get_args();
        return implode(DIRECTORY_SEPARATOR, $path);
    }
}
