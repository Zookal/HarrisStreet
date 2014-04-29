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
    const ALL_INACTIVE = 'all-inactive';

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
     * @param string $moduleName
     */
    public function remove($moduleName)
    {
        $modules = $this->_getInActiveModules();
        if (self::ALL_INACTIVE === strtolower($moduleName)) {
            foreach ($modules as $module) {
                $this->_remove($module);
            }
        } else {
            $this->_remove($moduleName);
        }
    }

    /**
     * @param $moduleName
     *
     * @return bool
     */
    protected function _remove($moduleName)
    {
        $this->_currentModuleName = $moduleName;
        $this->_loadConfigXml();

        if (null === $this->_currentModuleConfigXml) {
            return false;
        }

        $allFiles = array_merge($this->_getTranslationFiles(), $this->_getLayoutUpdateFiles(), $this->_getModuleDirectoriesFiles());

        foreach ($allFiles as $file) {
            $this->_removeReal($file);
        }
        return true;
    }

    /**
     * @param $pathToFile
     */
    protected function _removeReal($pathToFile)
    {
        $cmd = 'rm -Rf ' . $pathToFile;
        echo "$cmd\n";
        //trim(shell_exec($cmd));
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

    protected function _getTranslationFiles()
    {
        $files = array();
        foreach (array('frontend', 'adminhtml') as $area) {
            $modules = $this->_currentModuleConfigXml->xpath($area . '/translate/modules/' . $this->_currentModuleName . '/files');
            foreach ($modules as $fem) {
                /** @var $fem \SimpleXMLElement */
                foreach ($fem as $key => $fileName) {
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

    protected function _getLayoutUpdateFiles()
    {
        $files = array();
        foreach (array('frontend', 'adminhtml') as $area) {
            $modules = $this->_currentModuleConfigXml->xpath($area . '/layout/updates');
            if(count($modules)>0){
                var_dump([$modules,$this->_currentModuleName]); // @todo
            }

            foreach ($modules as $fem) {
                /** @var $fem \SimpleXMLElement */
                foreach ($fem as $key => $fileName) {
                    $filePath         = $this->_path($this->_rootFolder, 'app', 'locale', '*', (string)$fileName);
                    $files[$filePath] = $filePath;
                }
            }
        }

        return $files;
    }

    protected function _getTemplateFiles()
    {
        // maybe preg_match_all for template="[^"]+" in all layout files
        $files = array();

        return $files;
    }

    protected function _getModuleDirectoriesFiles()
    {
        $parts    = $this->_getNsMn();
        $return   = array();
        $return[] = $this->_path($this->_rootFolder, 'app', 'etc', 'modules', $this->_currentModuleName . '.xml');
        $return[] = $this->_path($this->_rootFolder, 'app', 'code', $this->_moduleCodePoolCache[$this->_currentModuleName], $parts[0], $parts[1]);
        return $return;
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
