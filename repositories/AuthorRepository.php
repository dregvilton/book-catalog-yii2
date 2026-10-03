<?php

declare(strict_types=1);

namespace app\repositories;

use app\exceptions\EntityNotFoundException;
use app\models\Author;
use Yii;
use yii\data\ActiveDataProvider;

/**
 * Репозиторий чтения и поиска авторов.
 */
class AuthorRepository
{
    /**
     * Возвращает всех авторов по алфавиту.
     *
     * @return ActiveDataProvider
     */
    public function page(): ActiveDataProvider
    {
        return new ActiveDataProvider([
            'query' => Author::find()->orderBy(['full_name' => SORT_ASC, 'id' => SORT_ASC]),
            'pagination' => ['pageSize' => 20],
        ]);
    }

    /**
     * Возвращает автора или доменную ошибку, если запись не найдена.
     *
     * @param int $id
     * @return Author
     * @throws EntityNotFoundException
     */
    public function get(int $id): Author
    {
        $author = Author::findOne($id);

        if ($author === null) {
            throw new EntityNotFoundException(Yii::t('app', 'Автор не найден.'));
        }

        return $author;
    }

    /**
     * Возвращает список авторов для поля выбора.
     *
     * @return array<int, string>
     */
    public function listForSelect(): array
    {
        return Author::find()
            ->select(['full_name', 'id'])
            ->orderBy(['full_name' => SORT_ASC])
            ->indexBy('id')
            ->column();
    }
}
