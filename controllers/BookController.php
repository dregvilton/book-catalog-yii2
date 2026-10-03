<?php

declare(strict_types=1);

namespace app\controllers;

use app\forms\BookForm;
use app\models\Book;
use app\presenters\HttpExceptionPresenter;
use app\repositories\AuthorRepository;
use app\repositories\BookRepository;
use app\services\BookService;
use Throwable;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Контроллер просмотра и управления книгами.
 */
class BookController extends Controller
{
    /**
     * Настраивает доступы и HTTP-методы.
     *
     * @return array<string, mixed>
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['create', 'update', 'delete'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Список книг.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        return $this->render('index', [
            'provider' => $this->module->get(BookRepository::class)->page(),
        ]);
    }

    /**
     * Карточка книги.
     *
     * @param int $id
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): string
    {
        return $this->render('view', [
            'book' => $this->module->get(HttpExceptionPresenter::class)->notFound(
                fn (): Book => $this->module->get(BookRepository::class)->get($id)
            ),
        ]);
    }

    /**
     * Создание книги.
     *
     * @return Response|string
     * @throws ServerErrorHttpException
     */
    public function actionCreate(): Response|string
    {
        $form = Yii::$container->get(BookForm::class);

        if (Yii::$app->request->isPost) {
            try {
                $form->load(Yii::$app->request->post());
                $form->loadCoverFile();
                $book = $form->validate()
                    ? $this->module->get(BookService::class)->create($form)
                    : null;

                if ($book !== null) {
                    Yii::$app->session->setFlash('success', Yii::t('app', 'Книга добавлена.'));

                    return $this->redirect(['view', 'id' => $book->id]);
                }
            } catch (Throwable $exception) {
                throw new ServerErrorHttpException(Yii::t('app', 'Не удалось сохранить книгу.'), 0, $exception);
            }
        }

        return $this->render('form', [
            'form' => $form,
            'authors' => $this->module->get(AuthorRepository::class)->listForSelect(),
        ]);
    }

    /**
     * Редактирование книги.
     *
     * @param int $id
     * @return Response|string
     * @throws NotFoundHttpException
     * @throws ServerErrorHttpException
     */
    public function actionUpdate(int $id): Response|string
    {
        $book = $this->module->get(HttpExceptionPresenter::class)->notFound(
            fn (): Book => $this->module->get(BookRepository::class)->get($id)
        );
        $form = Yii::$container->get(BookForm::class, [$book]);

        if (Yii::$app->request->isPost) {
            try {
                $form->load(Yii::$app->request->post());
                $form->loadCoverFile();
                $book = $form->validate()
                    ? $this->module->get(BookService::class)->update($form)
                    : null;

                if ($book !== null) {
                    Yii::$app->session->setFlash('success', Yii::t('app', 'Книга обновлена.'));

                    return $this->redirect(['view', 'id' => $book->id]);
                }
            } catch (Throwable $exception) {
                throw new ServerErrorHttpException(Yii::t('app', 'Не удалось обновить книгу.'), 0, $exception);
            }
        }

        return $this->render('form', [
            'form' => $form,
            'authors' => $this->module->get(AuthorRepository::class)->listForSelect(),
        ]);
    }

    /**
     * Удаление книги.
     *
     * @param int $id
     * @return Response
     * @throws NotFoundHttpException
     * @throws ServerErrorHttpException
     */
    public function actionDelete(int $id): Response
    {
        try {
            $book = $this->module->get(HttpExceptionPresenter::class)->notFound(
                fn (): Book => $this->module->get(BookRepository::class)->get($id)
            );

            if (!$this->module->get(BookService::class)->delete($book)) {
                throw new ServerErrorHttpException(Yii::t('app', 'Не удалось удалить книгу.'));
            }
        } catch (NotFoundHttpException|ServerErrorHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServerErrorHttpException(Yii::t('app', 'Не удалось удалить книгу.'), 0, $exception);
        }

        Yii::$app->session->setFlash('success', Yii::t('app', 'Книга удалена.'));

        return $this->redirect(['index']);
    }
}
