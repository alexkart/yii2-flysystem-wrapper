<?php

namespace whc\filemanager;

use Integral\Flysystem\Adapter\PDOAdapter;
use whc\common\components\Query;
use whc\filemanager\models\File;
use whc\filemanager\models\FileMetadata;
use whc\filemanager\models\FileStorage;
use Yii;
use yii\db\ActiveQuery;
use yii\i18n\PhpMessageSource;

class FlysystemWrapper extends \yii\base\Widget
{
    public function init()
    {
        if (!isset(Yii::$app->get('i18n')->translations['message*'])) {
            Yii::$app->get('i18n')->translations['message*'] = [
                'class' => PhpMessageSource::className(),
                'basePath' => __DIR__ . '/messages',
                'sourceLanguage' => Yii::$app->language
            ];
        }

        parent::init(); // TODO: Change the autogenerated stub
    }

    /**
     * @param $files
     * @param $data
     * @return bool
     */
    public static function upload($files, $data)
    {
        $pdo = new \PDO(Yii::$app->db->dsn, Yii::$app->db->username, Yii::$app->db->password);
        $adapter = new PDOAdapter($pdo, 'file_storage');
        $config = new \League\Flysystem\Config;

        foreach ((array)$files as $file)
        {
            $filePath = Yii::getAlias($data['path']) . '/' . $file->name;
            $fileContent = file_get_contents($file->tempName);
            if($adapter->write($filePath, $fileContent, $config) !== false)
            {
                $fileModel = new File;
                $fileModel->file_name = $file->name;
                $fileModel->path = $filePath;
                $fileModel->size = $file->size;
                $fileModel->mime_type = $file->type;
                $fileModel->context = isset($data['context'])? $data['context'] : null;
                $fileModel->version = isset($data['version'])? $data['version'] : null;
                $fileModel->hash = sha1(uniqid(rand(), TRUE));
                $fileModel->save();

                if($fileModel->save())
                {
                    foreach ((array)$data['metadata'] as $metadata => $value)
                    {
                        $fileMetadataModel = new FileMetadata();
                        $fileMetadataModel->file_id = $fileModel->id;
                        $fileMetadataModel->metadata = $metadata;
                        $fileMetadataModel->value = (string)$value;
                        $fileMetadataModel->save();
                    }
                }
            }
            else
            {
                return false;
            }
        }
        return true;
    }

    /**
     * get file by hash key
     * @param $hash
     * @return array|\yii\db\ActiveRecord[]
     */
    public static function getByHash($hash)
    {
        return File::find()
            ->alias('f')
            ->innerJoinWith('fileMetadatas')
            ->where(['f.hash' => $hash, 'f.deleted_time' => null])
            ->asArray()
            ->all();
    }

    public function readByHash($hash)
    {
        $fileModel = File::find()->where(['hash' => $hash, 'deleted_time' => null])->one();
        $fileStorageModel = FileStorage::find()->where(['path' => $fileModel->path])->one();

        if($fileModel !== false && $fileStorageModel !== false)
        {
            header('Content-Description: File Transfer');
            header("Content-Type: " . $fileModel->mime_type);
            header('Content-Disposition: inline; filename="' . $fileModel->file_name);
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . $fileModel->size);

            echo $fileStorageModel->contents;
        }
        return false;
    }

    /**
     * search by metadatas
     * @param $metadata [key => value]
     * @return array|\yii\db\ActiveRecord[]
     */
    public function searchByMetadata($metadata)
    {
        $fileModel = File::find()
            ->distinct()
            ->select('hash')
            ->alias('f')
            ->innerJoinWith(['fileMetadatas' => function (ActiveQuery $query) {
            $query->alias('fm');
            }]);

        foreach ($metadata as $meta => $value)
        {
            $fileModel->orWhere(['metadata' => $meta, 'value' => $value]);
        }
        $fileModel->andWhere(['f.deleted_time' => null]);

        return $fileModel->all();
    }

    /**
     * delete a file by hash key
     * @param $hash
     */
    public static function delete($hash)
    {
        $fileModel = File::find()->where(['hash' => $hash, 'deleted_time' => null])->one();
        if($fileModel !== null)
        {
            $currentDate = (new \DateTime())->format('Y-m-d H:i:s');
            $fileModel->deleted_time = $currentDate;
            $fileModel->save();

            $query = new Query();
            $query->createCommand()->update(FileMetadata::tableName(), ['deleted_time' => $currentDate], ['file_id' => $fileModel->id])->execute();
        }
    }
}
