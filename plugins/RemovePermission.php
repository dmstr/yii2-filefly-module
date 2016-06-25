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
 * Class RemovePermission
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class RemovePermission extends Component implements PluginInterface
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
        \Yii::error($itemPath, '$removePermission.$itemPath');

        $item = FileflyHashmap::find()
            ->andWhere(['component' => $this->component])
            ->andWhere(['path' => $itemPath])
            ->andWhere(['access_domain' => \Yii::$app->language])
            ->one();

        if ($item === null) {
            \Yii::error('Could not find item [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        }
        \Yii::error($item->attributes, '$remove.$item');
        if (!$item->delete()) {
            \Yii::error('Could not delete item [' . $itemPath . '] in hash table!', __METHOD__);
            return false;
        }

        return true;
    }
}
