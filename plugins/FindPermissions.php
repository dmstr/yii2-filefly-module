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
 * Class FindPermissions
 * @package hrzg\filefly\plugins
 * @author Christopher Stebe <c.stebe@herzogkommunikation.de>
 */
class FindPermissions extends Component implements PluginInterface
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
        return 'getPermissions';
    }

    /**
     * @param array $contents
     *
     * @return bool
     */
    public function handle(array $contents)
    {
        $files = [];

        foreach ($contents as $file) {
            $files[] = $file['path'];
        }

        $hashes = FileflyHashmap::find()
            ->select(['path'])
            ->andWhere(['component' => $this->component])
            ->andWhere(['IN', 'path', $files])
            ->asArray()
            ->all();

        return $hashes;
    }
}