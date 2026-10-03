<?php

use app\models\Author;
use yii\helpers\Html;
use yii\widgets\LinkPager;

/** @var yii\web\View $this */
/** @var yii\data\ActiveDataProvider $provider */

$this->title = Yii::t('app', 'Авторы');
?>
<h1><?= Html::encode($this->title) ?></h1>

<?php if (!Yii::$app->user->isGuest): ?>
    <div class="actions">
        <?= Html::a(Yii::t('app', 'Добавить автора'), ['create'], ['class' => 'button']) ?>
    </div>
<?php endif; ?>

<table>
    <thead>
    <tr>
        <th><?= Yii::t('app', 'ФИО') ?></th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($provider->getModels() as $author): ?>
        <tr>
            <td><?= Html::encode($author->full_name) ?></td>
            <td><?= Html::a(Yii::t('app', 'Открыть'), ['view', 'id' => $author->id]) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?= LinkPager::widget(['pagination' => $provider->getPagination()]) ?>
