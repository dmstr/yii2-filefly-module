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
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use yii\base\Component;


/**
 * Class RemovePermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RemovePermissions extends Component implements PluginInterface
{
    /**
     * The yii component name of this filesystem
     * @var string
     */
    public $component;

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
        return 'removePermission';
    }

    /**
     * The full path strings of the file or directory to be removed
     *
     * @param string $itemPath
     *
     * @return bool
     */
    public function handle($itemPath = null)
    {
        $itemPath = ltrim($itemPath, '/');

        return $this->removeRecursive($itemPath);
    }

    /**
     * @param null $itemPath
     *
     * @return bool
     */
    private function removeRecursive($itemPath = null)
    {
        $items = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['like', 'path', $itemPath . '%', false])
            ->all();

        if ($items === null) {
            \Yii::error('Could not find items in [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        }

        foreach ($items as $item) {

            if (!$item->delete()) {
                \Yii::error('Could not delete item [' . $itemPath . '] in hash table!', __METHOD__);
                return false;
            }
        }

        return true;
    }
}
