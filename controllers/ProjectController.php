<?php

namespace app\controllers;

use app\components\feed\Feed;
use app\components\feed\Item;
use app\components\UserPermissions;
use app\models\ImageUploadForm;
use app\models\Project;
use app\models\ProjectFilterForm;
use app\notifier\NewProjectNotification;
use app\notifier\Notifier;
use Yii;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;
use yii\helpers\Markdown;
use yii\helpers\Url;
use yii\web\Controller;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\web\ServerErrorHttpException;
use yii\web\UploadedFile;

class ProjectController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['create', 'update'], //only be applied to
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['create', 'update'],
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        $featuredProvider = new ActiveDataProvider([
            'query' => Project::find()
                ->with('images')
                ->featured()
                ->publishedOrEditable()
                ->orderBy('created_at DESC')
                ->limit(10)
        ]);

        $newProvider = new ActiveDataProvider([
            'query' => Project::find()
                ->with('images')
                ->featured(false)
                ->publishedOrEditable()
                ->orderBy('created_at DESC')
                ->limit(10)
        ]);

        return $this->render('index', [
            'featuredProvider' => $featuredProvider,
            'newProvider' => $newProvider,
        ]);
    }

    public function actionList()
    {
        $filterForm = new ProjectFilterForm();
        $filterForm->load(Yii::$app->request->get());

        return $this->render('list', [
            'dataProvider' => $filterForm->getDataProvider(),
            'filterForm' => $filterForm,
        ]);
    }

    public function actionCreate()
    {
        $model = new Project();
        if (UserPermissions::canManageProjects()) {
            $model->setScenario(Project::SCENARIO_MANAGE);
        }

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            $notifier = new Notifier(new NewProjectNotification($model));
            $notifier->sendEmails();
            Yii::$app->session->setFlash('project.project_successfully_added');
            return $this->redirect(['view', 'id' => $model->id, 'slug' => $model->slug]);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    public function actionRss()
    {
        /** @var Project[] $projects */
        $projects = Project::find()
            ->with('images')
            ->where(['status' => Project::STATUS_PUBLISHED])
            ->orderBy('created_at DESC')
            ->limit(50)
            ->all();

        $feed = new Feed();
        $feed->title = 'YiiPowered';
        $feed->link = Url::to('');
        $feed->selfLink = Url::to(['project/rss'], true);
        $feed->description = 'Yii powered projects';
        $feed->language = 'en';
        $feed->setWebMaster('sam@rmcreative.ru', 'Alexander Makarov');
        $feed->setManagingEditor('sam@rmcreative.ru', 'Alexander Makarov');

        foreach ($projects as $project) {
            $item = new Item();
            $item->title = $project->title;
            $item->link = Url::to(['project/view', 'id' => $project->id, 'slug' => $project->slug], true);
            $item->guid = Url::to(['project/view', 'id' => $project->id, 'slug' => $project->slug], true);
            $item->description = HtmlPurifier::process(Markdown::process($project->getDescription()));

            if (!empty($project->link)) {
                $item->description .= Html::a(Html::encode($project->url), $project->url);
            }

            $item->pubDate = $project->created_at;
            $item->setAuthor('noreply@yiipowered.com', 'YiiPowered');
            $feed->addItem($item);
        }

        $feed->render();
    }

    public function actionUpdate($id)
    {
        $model = $this->findModel(['id' => $id]);

        if (!UserPermissions::canManageProject($model)) {
            throw new ForbiddenHttpException(Yii::t('project', 'You can not update this project.'));
        }

        if (Yii::$app->user->can(UserPermissions::MANAGE_PROJECTS)) {
            $model->setScenario(Project::SCENARIO_MANAGE);
        }
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            return $this->redirect(['view', 'id' => $model->id, 'slug' => $model->slug]);
        } else {
            return $this->render('update', [
                'model' => $model,
            ]);
        }
    }


    public function actionView($id, $slug)
    {
        $project = $this->findModel([
            'id' => $id,
            'slug' => $slug,
        ]);

        $imageUploadForm = null;

        if (UserPermissions::canManageProject($project)) {
            $imageUploadForm = new ImageUploadForm($id);
            if (Yii::$app->request->isPost) {
                $imageUploadForm->files = UploadedFile::getInstances($imageUploadForm, 'files');
                if ($imageUploadForm->upload()) {
                    return $this->refresh();
                }
            }
        }

        return $this->render('view', [
            'model' => $project,
            'imageUploadForm' => $imageUploadForm,
        ]);
    }

    /**
     * @param array $condition
     * @return Project
     * @throws NotFoundHttpException
     */
    protected function findModel($condition)
    {
        /** @var Project $model */
        $model = Project::find()
            ->publishedOrEditable()
            ->andWhere($condition)->one();

        if ($model !== null) {
            return $model;
        } else {
            throw new NotFoundHttpException('The requested page does not exist.');
        }
    }


}
