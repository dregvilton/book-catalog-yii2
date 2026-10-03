<?php

declare(strict_types=1);

namespace app\services;

use app\models\Book;
use app\models\Subscription;
use Yii;

/**
 * Сервис уведомления подписчиков о новых книгах.
 */
class SubscriptionNotifier
{
    /**
     * @param SmsPilotClient $smsPilot
     */
    public function __construct(
        private readonly SmsPilotClient $smsPilot
    ) {
    }

    /**
     * Уведомляет подписчиков авторов о новой книге.
     *
     * @param Book $book
     * @return void
     */
    public function notifyAboutNewBook(Book $book): void
    {
        $authorIds = array_map(static fn ($author): int => $author->id, $book->authors);

        if ($authorIds === []) {
            return;
        }

        $subscriptions = Subscription::find()
            ->where(['author_id' => $authorIds])
            ->all();
        $phones = array_unique(array_map(static fn (Subscription $subscription): string => $subscription->phone, $subscriptions));

        foreach ($phones as $phone) {
            try {
                $this->smsPilot->send($phone, $this->message($book));
            } catch (\Throwable $exception) {
                Yii::warning('Не удалось отправить SMS для книги ' . $book->id, __METHOD__);
            }
        }
    }

    /**
     * Формирует текст SMS по книге.
     *
     * @param Book $book
     * @return string
     */
    private function message(Book $book): string
    {
        return Yii::t('app', 'В каталог добавлена книга "{title}" ({year}). Автор(ы): {authors}.', [
            'title' => $book->title,
            'year' => $book->release_year,
            'authors' => $book->getAuthorNames(),
        ]);
    }
}
