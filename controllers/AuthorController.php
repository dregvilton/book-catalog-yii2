<?php

declare(strict_types=1);

namespace app\controllers;

use app\forms\SubscriptionForm;
use app\models\Author;
use app\presenters\HttpExceptionPresenter;
use app\repositories\AuthorRepository;
use Throwable;
use Yii;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Контроллер просмотра и управления авторами.
 */
class AuthorController extends Controller
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
                    'subscribe' => ['post'],
                ],
            ],
        ];
    }

    /**
     * Список авторов.
     *
     * @return string
     */
    public function actionIndex(): string
    {
        return $this->render('index', [
            'provider' => $this->module->get(AuthorRepository::class)->page(),
        ]);
    }

    /**
     * Карточка автора.
     *
     * @param int $id
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionView(int $id): string
    {
        return $this->render('view', [
            'author' => $this->module->get(HttpExceptionPresenter::class)->notFound(
                fn (): Author => $this->module->get(AuthorRepository::class)->get($id)
            ),
            'subscriptionForm' => Yii::$container->get(SubscriptionForm::class, [$id]),
        ]);
    }

    /**
     * Создание автора.
     *
     * @return Response|string
     * @throws ServerErrorHttpException
     */
    public function actionCreate(): Response|string
    {
        $author = Yii::$container->get(Author::class);

        try {
            if ($author->load(Yii::$app->request->post()) && $author->save()) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Автор добавлен.'));

                return $this->redirect(['view', 'id' => $author->id]);
            }
        } catch (Throwable $exception) {
            throw new ServerErrorHttpException(Yii::t('app', 'Не удалось сохранить автора.'), 0, $exception);
        }

        return $this->render('form', [
            'author' => $author,
        ]);
    }

    /**
     * Редактирование автора.
     *
     * @param int $id
     * @return Response|string
     * @throws NotFoundHttpException
     * @throws ServerErrorHttpException
     */
    public function actionUpdate(int $id): Response|string
    {
        $author = $this->module->get(HttpExceptionPresenter::class)->notFound(
            fn (): Author => $this->module->get(AuthorRepository::class)->get($id)
        );

        try {
            if ($author->load(Yii::$app->request->post()) && $author->save()) {
                Yii::$app->session->setFlash('success', Yii::t('app', 'Автор обновлен.'));

                return $this->redirect(['view', 'id' => $author->id]);
            }
        } catch (Throwable $exception) {
            throw new ServerErrorHttpException(Yii::t('app', 'Не удалось обновить автора.'), 0, $exception);
        }

        return $this->render('form', [
            'author' => $author,
        ]);
    }

    /**
     * Удаление автора.
     *
     * @param int $id
     * @return Response
     * @throws NotFoundHttpException
     * @throws ServerErrorHttpException
     */
    public function actionDelete(int $id): Response
    {
        try {
            $author = $this->module->get(HttpExceptionPresenter::class)->notFound(
                fn (): Author => $this->module->get(AuthorRepository::class)->get($id)
            );

            if ($author->delete() === false) {
                throw new ServerErrorHttpException(Yii::t('app', 'Не удалось удалить автора.'));
            }
        } catch (NotFoundHttpException|ServerErrorHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ServerErrorHttpException(Yii::t('app', 'Не удалось удалить автора.'), 0, $exception);
        }

        Yii::$app->session->setFlash('success', Yii::t('app', 'Автор удален.'));

        return $this->redirect(['index']);
    }

    /**
     * Подписка гостя на новые книги автора.
     *
     * @param int $id
     * @return Response
     */
    public function actionSubscribe(int $id): Response
    {
        if (!Yii::$app->user->isGuest) {
            return $this->redirect(['view', 'id' => $id]);
        }

        $form = Yii::$container->get(SubscriptionForm::class, [$id]);

        if ($form->load(Yii::$app->request->post()) && $form->subscribe()) {
            Yii::$app->session->setFlash('success', Yii::t('app', 'Подписка оформлена.'));
        } else {
            Yii::$app->session->setFlash('error', implode(' ', $form->getFirstErrors()));
        }

        return $this->redirect(['view', 'id' => $id]);
    }
}
