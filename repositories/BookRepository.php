<?php

declare(strict_types=1);

namespace app\repositories;

use app\exceptions\EntityNotFoundException;
use app\models\Book;
use Yii;
use yii\data\ActiveDataProvider;

/**
 * Репозиторий чтения и поиска книг.
 */
class BookRepository
{
    /**
     * Возвращает книги для списка.
     *
     * @return ActiveDataProvider
     */
    public function page(): ActiveDataProvider
    {
        return new ActiveDataProvider([
            'query' => Book::find()->with('authors')->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC]),
            'pagination' => ['pageSize' => 20],
        ]);
    }

    /**
     * Возвращает книгу или доменную ошибку, если запись не найдена.
     *
     * @param int $id
     * @return Book
     * @throws EntityNotFoundException
     */
    public function get(int $id): Book
    {
        $book = Book::find()
            ->with('authors')
            ->where(['id' => $id])
            ->one();

        if ($book === null) {
            throw new EntityNotFoundException(Yii::t('app', 'Книга не найдена.'));
        }

        return $book;
    }
}
