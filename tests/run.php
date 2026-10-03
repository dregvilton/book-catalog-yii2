<?php

declare(strict_types=1);

// Небольшие сценарные проверки без дополнительного тестового фреймворка.
defined('YII_DEBUG') or define('YII_DEBUG', true);
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

use app\controllers\AuthorController;
use app\controllers\BookController;
use app\forms\BookForm;
use app\forms\SubscriptionForm;
use app\models\Author;
use app\models\Book;
use app\models\Subscription;
use app\repositories\AuthorReportRepository;
use app\repositories\AuthorRepository;
use app\repositories\BookRepository;
use app\services\BookService;
use app\services\S3Storage;
use app\services\SmsPilotClient;
use app\services\SubscriptionNotifier;
use yii\web\ForbiddenHttpException;
use yii\web\UploadedFile;

new yii\web\Application([
    'id' => 'catalog-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['cookieValidationKey' => 'test-only-key'],
        'user' => ['identityClass' => app\models\User::class, 'enableSession' => false, 'loginUrl' => null],
    ],
]);

$db = Yii::$app->db;
foreach ([
    'CREATE TABLE author (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)',
    'CREATE TABLE book (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, release_year INTEGER NOT NULL, description TEXT NOT NULL, isbn TEXT NOT NULL UNIQUE, cover_url TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)',
    'CREATE TABLE book_author (book_id INTEGER NOT NULL, author_id INTEGER NOT NULL, PRIMARY KEY (book_id, author_id))',
    'CREATE TABLE subscription (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL, phone TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE (author_id, phone))',
] as $sql) {
    $db->createCommand($sql)->execute();
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class TestStorage extends S3Storage
{
    public array $deleted = [];
    public function __construct() {}
    public function upload(UploadedFile $file, string $directory): string
    {
        return 'https://covers.test/book-' . bin2hex(random_bytes(4));
    }
    public function delete(string $url): void { $this->deleted[] = $url; }
}

class TestSms extends SmsPilotClient
{
    public array $sent = [];
    public function __construct() {}
    public function send(string $phone, string $text): void { $this->sent[] = $phone; }
}

class FixtureSms extends SmsPilotClient
{
    public int $status = 200;
    public string|false $response = '';
    public function __construct() { parent::__construct(['apiKey' => 'private-test-key', 'test' => true, 'apiUrl' => 'https://unused.test']); }
    protected function request(array $params): array { return [$this->status, $this->response]; }
}

class FailingNotifier extends SubscriptionNotifier
{
    public function __construct() {}
    public function notifyAboutNewBook(Book $book): void { throw new RuntimeException('Тестовый сбой SMS'); }
}

$storage = new TestStorage();
$sms = new TestSms();
$service = new BookService($storage, new SubscriptionNotifier($sms));

// Гость видит каталог, но не может менять книги и авторов.
check(Yii::$app->user->isGuest, 'Ожидался гость');
foreach ([BookController::class, AuthorController::class] as $controllerClass) {
    $controller = new $controllerClass('test', Yii::$app);
    foreach (['create', 'update', 'delete'] as $action) {
        try {
            $controller->runAction($action, ['id' => 1]);
            throw new RuntimeException('Гостю разрешено действие ' . $action);
        } catch (ForbiddenHttpException $exception) {
            // Запрет ожидается до вызова действия.
        }
    }
}

$first = new Author(['full_name' => 'Первый автор']);
$second = new Author(['full_name' => 'Второй автор']);
check($first->save() && $second->save(), 'Авторы не сохранились');

// Повторная подписка на того же автора отклоняется, на другого разрешена.
$phone = '+79990001122';
$subscription = new SubscriptionForm((int) $first->id);
$subscription->phone = $phone;
check($subscription->subscribe(), 'Первая подписка не сохранилась');
$duplicate = new SubscriptionForm((int) $first->id);
$duplicate->phone = $phone;
check(!$duplicate->subscribe(), 'Повторная подписка была разрешена');
$otherSubscription = new SubscriptionForm((int) $second->id);
$otherSubscription->phone = $phone;
check($otherSubscription->subscribe(), 'Подписка на второго автора не сохранилась');
check((int) Subscription::find()->count() === 2, 'Неверное число подписок');

// Несколько авторов одной книги учитываются в отчете, SMS на номер одна.
$form = new BookForm();
$form->title = 'Совместная книга';
$form->release_year = 2024;
$form->description = 'Описание';
$form->isbn = 'TEST-001';
$form->authorIds = [$first->id, $second->id];
$coverPath = tempnam(sys_get_temp_dir(), 'catalog-cover-');
file_put_contents($coverPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII='));
$form->coverFile = new UploadedFile(['name' => 'cover.png', 'tempName' => $coverPath, 'type' => 'image/png', 'size' => filesize($coverPath)]);
check($form->validate(), 'Форма книги невалидна: ' . json_encode($form->errors));
$book = $service->create($form);
check($book !== null && count($book->authors) === 2, 'Книга сохранилась без двух авторов');
check($sms->sent === [$phone], 'Одному номеру отправлено не одно SMS');
$report = (new AuthorReportRepository())->getTopByYear(2024);
check(count($report) === 2 && (int) $report[0]['books_count'] === 1 && (int) $report[1]['books_count'] === 1, 'Неверный отчет за 2024 год');
check((new AuthorReportRepository())->getTopByYear(2023) === [], 'Отчет не фильтруется по году');
check((new BookRepository())->page()->getModels()[0]->isRelationPopulated('authors'), 'Авторы книги не загружены заранее');
for ($number = 0; $number < 22; $number++) {
    check((new Author(['full_name' => 'Автор ' . $number]))->save(), 'Не удалось подготовить страницу авторов');
}
$authorPage = (new AuthorRepository())->page();
check(count($authorPage->getModels()) === 20, 'Первая страница авторов имеет неверный размер');
$authorPage->getPagination()->setPage(1);
$authorPage->refresh();
check(count($authorPage->getModels()) === 4, 'Вторая страница авторов имеет неверный размер');

// Ошибка сохранения после загрузки не оставляет новую обложку.
$bad = new BookForm();
$bad->title = 'Повтор ISBN';
$bad->release_year = 2024;
$bad->description = 'Описание';
$bad->isbn = 'TEST-001';
$bad->authorIds = [$first->id];
$bad->coverFile = $form->coverFile;
check($service->create($bad) === null, 'Повтор ISBN не отклонен');
check(count($storage->deleted) === 1, 'Новая обложка после ошибки не удалена');

$db->createCommand('ALTER TABLE book_author RENAME TO unavailable_book_author')->execute();
$failedLink = new BookForm();
$failedLink->title = 'Сбой связи';
$failedLink->release_year = 2024;
$failedLink->description = 'Описание';
$failedLink->isbn = 'TEST-FAIL';
$failedLink->authorIds = [$first->id];
$failedLink->coverFile = $form->coverFile;
try {
    $service->create($failedLink);
    throw new RuntimeException('Ошибка связи не остановила сохранение');
} catch (yii\db\Exception $exception) {
    check((int) Book::find()->where(['isbn' => 'TEST-FAIL'])->count() === 0, 'Книга не откатилась');
    check(count($storage->deleted) === 2, 'Обложка после отката транзакции не удалена');
}
$db->createCommand('ALTER TABLE unavailable_book_author RENAME TO book_author')->execute();

$oldCover = $book->cover_url;
$update = new BookForm($book);
$update->coverFile = $form->coverFile;
check($service->update($update) !== null, 'Обновление книги не удалось');
check(in_array($oldCover, $storage->deleted, true), 'Старая обложка после замены не удалена');
$newCover = $book->cover_url;
check($service->delete($book), 'Удаление книги не удалось');
check(in_array($newCover, $storage->deleted, true), 'Обложка удаленной книги не очищена');
unlink($coverPath);

// HTTP, JSON, ответ API и статус отдельного SMS проверяются без раскрытия данных.
$client = new FixtureSms();
foreach ([
    [503, '{"send":[{"server_id":"1","status":"0"}]}'],
    [200, 'not json'],
    [200, '{"error":{"description":"private-test-key +79990001122"}}'],
    [200, '{"send":[]}'],
    [200, '{"send":[{"server_id":"1","status":"-2"}]}'],
] as [$status, $response]) {
    $client->status = $status;
    $client->response = $response;
    $failed = false;
    try {
        $client->send($phone, 'Тест');
    } catch (RuntimeException $exception) {
        $failed = true;
        check(!str_contains($exception->getMessage(), $phone)
            && !str_contains($exception->getMessage(), 'private-test-key'), 'В ошибке раскрыты данные');
    }
    check($failed, 'Некорректный ответ SMSPilot принят');
}
$client->status = 200;
$client->response = '{"send":[{"server_id":"123","status":"0"}]}';
$client->send($phone, 'Тест');

// Сбой уведомления происходит после commit и не отменяет книгу.
$withoutSms = new BookForm();
$withoutSms->title = 'Книга при сбое SMS';
$withoutSms->release_year = 2024;
$withoutSms->description = 'Описание';
$withoutSms->isbn = 'TEST-002';
$withoutSms->authorIds = [$first->id];
check((new BookService($storage, new FailingNotifier()))->create($withoutSms) !== null, 'Сбой SMS отменил книгу');
check((int) Book::find()->where(['isbn' => 'TEST-002'])->count() === 1, 'Книга не осталась в БД');

echo "Сценарные проверки пройдены.\n";
