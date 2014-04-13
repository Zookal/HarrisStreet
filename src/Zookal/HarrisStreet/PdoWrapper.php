<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet;

class PdoWrapper
{
    /**
     * @var \PDO
     */
    protected $pdo;

    /**
     * http://php.net/manual/en/pdo.setattribute.php
     * @param $dsn
     * @param $user
     * @param $password
     */
    public function init($dsn, $user, $password)
    {
        $this->pdo = new \PDO($dsn, $user, $password, array(\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''));
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function query($statement)
    {
        return $this->pdo->query($statement);
    }

    public function quote($string)
    {
        return $this->pdo->quote($string);
    }

    public function getPdo()
    {
        return $this->pdo;
    }
}