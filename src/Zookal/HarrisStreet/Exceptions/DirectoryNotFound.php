<?php
/**
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @copyright   2014-present Zookal Pty Ltd, Sydney, Australia
 * @author      Cyrill at Schumacher dot fm [@SchumacherFM]
 */

namespace Zookal\HarrisStreet\Exceptions;

class DirectoryNotFound extends \Exception
{
    public function __construct($dirname)
    {
        parent::__construct(sprintf('The directory %s doesn\'t exists.', $dirname));
    }
}