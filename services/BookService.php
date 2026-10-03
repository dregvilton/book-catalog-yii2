<?php

declare(strict_types=1);

namespace app\services;

use app\forms\BookForm;
use app\models\Book;
use app\models\BookAuthor;
use Yii;

/**
 * Сервис сценариев создания и редактирования книг.
 */
class BookService
{
    /**
     * @param S3Storage $storage
     * @param SubscriptionNotifier $notifier
     */
    public function __construct(
        private readonly S3Storage $storage,
        private readonly SubscriptionNotifier $notifier
    ) {
    }

    /**
     * Создает книгу и уведомляет подписчиков авторов.
     *
     * @param BookForm $form
     * @return Book|null
     * @throws \Throwable
     */
    public function create(BookForm $form): ?Book
    {
        $book = $this->save($form);

        if ($book !== null) {
            try {
                $this->notifier->notifyAboutNewBook($book);
            } catch (\Throwable $exception) {
                Yii::warning('Не удалось уведомить подписчиков книги ' . $book->id, __METHOD__);
            }
        }

        return $book;
    }

    /**
     * Обновляет книгу.
     *
     * @param BookForm $form
     * @return Book|null
     * @throws \Throwable
     */
    public function update(BookForm $form): ?Book
    {
        return $this->save($form);
    }

    public function delete(Book $book): bool
    {
        $coverUrl = $book->cover_url;

        if ($book->delete() === false) {
            return false;
        }

        $this->deleteCoverSafely($coverUrl);

        return true;
    }

    /**
     * Сохраняет книгу, обложку и связи с авторами.
     *
     * @param BookForm $form
     * @return Book|null
     * @throws \Throwable
     */
    private function save(BookForm $form): ?Book
    {
        $book = $form->getBook();
        $oldCoverUrl = $book->cover_url;
        $newCoverUrl = null;
        $committed = false;
        $transaction = Yii::$app->db->beginTransaction();

        try {
            $book->setAttributes($form->bookAttributes());

            if ($form->coverFile !== null) {
                $newCoverUrl = $this->storage->upload($form->coverFile, 'covers');
                $book->cover_url = $newCoverUrl;
            }

            if (!$book->save()) {
                $form->addErrors($book->getErrors());
                $transaction->rollBack();

                return null;
            }

            $this->syncAuthors($book, $form->normalizedAuthorIds());
            $transaction->commit();
            $committed = true;

            if ($newCoverUrl !== null && $oldCoverUrl !== null) {
                $this->deleteCoverSafely($oldCoverUrl);
            }

            return $book;
        } catch (\Throwable $exception) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }

            throw $exception;
        } finally {
            if (!$committed && $newCoverUrl !== null) {
                $this->deleteCoverSafely($newCoverUrl);
            }
        }
    }

    private function deleteCoverSafely(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        try {
            $this->storage->delete($url);
        } catch (\Throwable $exception) {
            Yii::warning('Не удалось очистить обложку в хранилище.', __METHOD__);
        }
    }

    /**
     * Синхронизирует связи книги с авторами.
     *
     * @param Book $book
     * @param list<int> $authorIds
     * @return void
     */
    private function syncAuthors(Book $book, array $authorIds): void
    {
        BookAuthor::deleteAll(['book_id' => $book->id]);
        $rows = array_map(
            static fn (int $authorId): array => [$book->id, $authorId],
            $authorIds
        );

        Yii::$app->db->createCommand()
            ->batchInsert(BookAuthor::tableName(), ['book_id', 'author_id'], $rows)
            ->execute();
    }
}
