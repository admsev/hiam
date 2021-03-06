<?php
/**
 * Identity and Access Management server providing OAuth2, multi-factor authentication and more
 *
 * @link      https://github.com/hiqdev/hiam
 * @package   hiam
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2014-2018, HiQDev (http://hiqdev.com/)
 */

namespace hiam\controllers;

use filsh\yii2\oauth2server\models\OauthAccessTokens;
use filsh\yii2\oauth2server\Request;
use hiam\base\User;
use hiqdev\yii2\mfa\filters\ValidateAuthenticationFilter;
use Yii;
use yii\filters\ContentNegotiator;
use yii\helpers\ArrayHelper;
use yii\web\BadRequestHttpException;
use yii\web\IdentityInterface;
use yii\web\Response;

class OauthController extends \yii\web\Controller
{
    public $enableCsrfValidation = false;

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            'contentNegotiator' => [
                'class' => ContentNegotiator::className(),
                'only' => ['resource'],
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
            'validateAuthentication' => [
                'class' => ValidateAuthenticationFilter::class,
                'only' => ['authorize'],
            ],
        ]);
    }

    /**
     * @return \filsh\yii2\oauth2server\Module
     */
    public function getModule()
    {
        return Yii::$app->getModule('oauth2');
    }

    public function getServer()
    {
        return $this->getModule()->getServer();
    }

    /**
     * @return Request
     */
    public function getRequest()
    {
        return $this->getModule()->getRequest();
    }

    /**
     * @return \filsh\yii2\oauth2server\Response
     */
    public function getResponse()
    {
        return $this->getModule()->getResponse();
    }

    /**
     * Get request parameter from POST then GET.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public function getRequestValue($name, $default = null)
    {
        $request = $this->getModule()->getRequest();

        return isset($request->request[$name]) ? $request->request[$name] : $request->query($name, $default);
    }

    public function getTokenParamName()
    {
        return $this->getServer()->getConfig('token_param_name');
    }

    /**
     * @param OauthAccessTokens $token
     * @return IdentityInterface|User
     */
    public function findIdentityByToken(OauthAccessTokens $token)
    {
        return Yii::$app->user->findIdentity($token->user_id);
    }

    public function findToken($access_token)
    {
        return OauthAccessTokens::findOne($access_token);
    }

    private function sendResponse($oauthResponse)
    {
        $response = Yii::$app->response;

        foreach ($oauthResponse->getHttpHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->setStatusCode($oauthResponse->getStatusCode(), $oauthResponse->getStatusText());

        return $oauthResponse->getResponseBody();
    }

    public function actionToken()
    {
        $response = $this->getServer()->handleTokenRequest($this->getRequest());
        $access_token = $response->getParameter($this->getTokenParamName());
        if ($access_token) {
            $token = $this->findToken($access_token);
            $user_attributes = $this->findIdentityByToken($token)->getAttributes();
            $response->addParameters(compact('user_attributes'));
        }

        return $this->sendResponse($response);
    }

    public function actionResource()
    {
        $ok = $this->getServer()->verifyResourceRequest($this->request);
        if (!$ok) {
            return $this->sendResponse($this->getServer()->getResponse());
        }
        $access_token = $this->getRequestValue($this->getTokenParamName());
        $token = $this->findToken($access_token);
        $user = $this->findIdentityByToken($token);

        if (!is_object($user)) { /// TODO fix error returning
            return ['error' => 'no user'];
        }

        return array_merge(array_filter($user->getAttributes()), [
            'token' => $token,
        ]);
    }

    public function isAuthorizedClient($client)
    {
        return !empty(Yii::$app->params['hiam.authorizedClients'][$client]);
    }

    public function actionAuthorize()
    {
        $request = $this->getRequest();
        $response = $this->getResponse();
        if (!$this->getServer()->validateAuthorizeRequest($request, $response)) {
            return $this->sendResponse($response);
        }

        $id = Yii::$app->getUser()->id;
        if (!$id) {
            return $this->redirect(['/site/login']);
        }

        $is_authorized = $this->isAuthorizedClient($this->getRequestValue('client_id'));
        if (!$is_authorized) {
            if (empty($_POST)) {
                return $this->render('authorizeClient', [
                    'client_id' => 'THE CLIENT_ID',
                ]);
            }

            if (!Yii::$app->getRequest()->validateCsrfToken()) {
                throw new BadRequestHttpException(Yii::t('hiam', 'Unable to verify your data submission.'));
            }
            $is_authorized = ($_POST['authorized'] === 'yes');
        }

        if ($request->query('user_id') && $this->canImpersonate()) {
            $id = $request->query('user_id');
        }

        $this->getServer()->handleAuthorizeRequest($request, $response, $is_authorized, $id);

        return $this->sendResponse($response);
    }

    private function canImpersonate()
    {
        return Yii::$app->user->can('client.impersonate'); // TODO: more wise check
    }
}
