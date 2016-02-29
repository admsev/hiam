<?php

namespace hiam\controllers;

use hiam\common\models\User;
use hiam\common\models\RemoteUser;
use hiam\common\models\LoginForm;
use hiam\models\PasswordResetRequestForm;
use hiam\models\ResetPasswordForm;
use hiam\models\SignupForm;
use hiam\models\ContactForm;
use Yii;
use yii\base\InvalidParamException;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\helpers\Url;

/**
 * Site controller
 */
class SiteController extends Controller
{

    public $defaultAction = 'lockscreen';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['login', 'signup', 'request-password-reset', 'remote-proceed', 'lockscreen'],
                'denyCallback' => [$this, 'denyCallback'],
                'rules' => [
                    // ? - guest
                    [
                        'actions' => ['login', 'confirm', 'signup', 'request-password-reset', 'remote-proceed'],
                        'roles' => ['?'],
                        'allow' => true,
                    ],
                    // @ - authenticated
                    [
                        'actions' => ['lockscreen'],
                        'roles' => ['@'],
                        'allow' => true,
                    ],
                ],
            ],
        ];
    }

    public function denyCallback ()
    {
        return $this->redirect([Yii::$app->user->getIsGuest() ? 'login' : 'lockscreen']);
    }

    /** @inheritdoc */
    public function actions()
    {
        return [
            'auth' => [
                'class' => 'yii\authclient\AuthAction',
                'successCallback' => [$this, 'successCallback'],
            ],
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    public function successCallback ($client)
    {
        $user = User::findIdentityByAuthClient($client);
        if ($user) {
            Yii::$app->user->login($user, 3600 * 24 * 30);
            return;
        };
        return;
    }

    public function actionLockscreen()
    {
        return $this->render('lockscreen');
    }

    public function actionIndex () {
        return $this->render('index');
    }

    protected function doLogin ($view,$username = null)
    {
        $model = new LoginForm();
        if ($model->load(Yii::$app->request->post()) && $model->login()) {
            return $this->goBack();
        } else {
            $model->username = $username;
            return $this->render($view, compact('model'));
        }
    }

    public function actionLogin ($confirm = false)
    {
        $client = Yii::$app->authClientCollection->getActiveClient();
        if ($client) {
            return $this->redirect(['remote-proceed']);
        };

        return $this->doLogin('login');
    }

    public function actionConfirm ()
    {
        $client = Yii::$app->authClientCollection->getActiveClient();
        if (!$client) {
            return $this->redirect(['login']);
        };

        $email = $client->getUserAttributes()['email'];
        $user = User::findOne(['email' => $email]);

        $res = $this->doLogin('confirm',$user ? $user->email : null);
        $user = Yii::$app->getUser()->getIdentity();
        if ($user) {
            RemoteUser::set($client,$user);
        };
        return $res;
    }

    public function actionRemoteProceed ()
    {
        $client = Yii::$app->authClientCollection->getActiveClient();
        if (!$client) {
            return $this->redirect(['login']);
        };
        $email = $client->getUserAttributes()['email'];
        $user = User::findByEmail($email);
        if ($user) {
            return $this->redirect(['confirm']);
        };
        return $this->redirect(['signup']);
    }

    public function actionSignup ()
    {
        $client = Yii::$app->authClientCollection->getActiveClient();

        $model = new SignupForm();
        if ($model->load(Yii::$app->request->post())) {
            if ($user = $model->signup()) {
                if (Yii::$app->getUser()->login($user)) {
                    if ($client) {
                        RemoteUser::set($client,$user);
                    };
                    return $this->goHome();
                }
            }
        } else {
            if ($client) {
                $model->load([$model->formName() => $client->getUserAttributes()]);
            };
        };

        return $this->render('signup', compact('model'));
    }

    public function actionLogout ()
    {
        Yii::$app->user->logout();
        Yii::$app->getSession()->destroy();
        $post = Yii::$app->request->post('back');
        $back = isset($post) ? $post : Yii::$app->request->get('back');

        return $back ? $this->redirect($back) : $this->goHome();
    }

    public function actionContact()
    {
        $model = new ContactForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail(Yii::$app->params['adminEmail'])) {
                Yii::$app->session->setFlash('success', 'Thank you for contacting us. We will respond to you as soon as possible.');
            } else {
                Yii::$app->session->setFlash('error', 'There was an error sending email.');
            }

            return $this->refresh();
        } else {
            return $this->render('contact', [
                'model' => $model,
            ]);
        }
    }

    public function actionAbout()
    {
        return $this->render('about');
    }

    public function actionRequestPasswordReset()
    {
        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->getSession()->setFlash('success', 'Check your email for further instructions.');

                return $this->goHome();
            } else {
                Yii::$app->getSession()->setFlash('error', 'Sorry, we are unable to reset password for email provided.');
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    public function actionResetPassword ($token)
    {
        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidParamException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->getSession()->setFlash('success', 'New password was saved.');

            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

}