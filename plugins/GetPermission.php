<?php
/**
 * @link http://www.diemeisterei.de/
 * @copyright Copyright (c) 2016 diemeisterei GmbH, Stuttgart
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace hrzg\filefly\plugins;

use hrzg\filefly\models\FileflyHashmap;
use hrzg\filefly\Module;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class GetPermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class GetPermission extends Component implements PluginInterface
{
    /**
     * The yii component name of this filesystem
     * @var string
     */
    public $component;

    /**
     * @var FilesystemInterface $filesystem
     */
    protected $filesystem;

    /**
     * @param FilesystemInterface $filesystem
     */
    public function setFilesystem(FilesystemInterface $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return 'getPermission';
    }

    /**
     * @param $path
     *
     * @return bool
     */
    public function handle($path)
    {
        /** @var $hash \hrzg\filefly\models\FileflyHashmap */
        $query = FileflyHashmap::find();
        $query->andWhere(['component' => $this->component]);
        $query->andWhere(['path' => $path]);
        $hash = $query->one();

        if ($hash === null) {
            return false;
        } else {
            \Yii::error($hash->attributes, '$hash');

            // TODO return all auth items for use and set seleted properties from DB
            // ->  FileflyHashmap::getUsersAuthItems();

            $selectedRoles = [];

            // read access
            foreach ($hash->authItemStringToArray(Module::ACCESS_READ) as $readItem) {
                $selectedRoles['read'][] = ['role' => '<i class="glyphicon glyphicon-lock"></i> ' . $readItem, 'selected' => true];
            }

            // read update
            foreach ($hash->authItemStringToArray(Module::ACCESS_UPDATE) as $updateItem) {
                $selectedRoles['update'][] = ['role' => '<i class="glyphicon glyphicon-lock"></i> ' . $updateItem, 'selected' => true];
            }

            // read delete
            foreach ($hash->authItemStringToArray(Module::ACCESS_DELETE) as $deleteItem) {
                $selectedRoles['delete'][] = ['role' => '<i class="glyphicon glyphicon-lock"></i> ' . $deleteItem, 'selected' => true];
            }

            return $selectedRoles;
        }
    }
}