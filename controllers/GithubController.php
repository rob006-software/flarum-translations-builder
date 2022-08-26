<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2022 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace app\controllers;

use app\components\release\ReleasePullRequestGenerator;
use app\jobs\MergeReleasePullRequestJob;
use app\models\Translations;
use Yii;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

/**
 * Class GithubController.
 *
 * @author Robert Korulczyk <robert@korulczyk.pl>
 */
class GithubController extends Controller {

	public $enableCsrfValidation = false;

	public $configFile = '@app/translations/config.php';

	public function beforeAction($action) {
		$this->response->format = Response::FORMAT_RAW;

		return parent::beforeAction($action);
	}

	public function actionLanguageSubsplit() {
		/* @noinspection DegradedSwitchInspection */
		switch ($this->getGithubEvent()) {
			case 'pull_request_review':
				return $this->handleReview();
			default:
				return $this->generateResponse('Nothing interesting - skip.');
		}
	}

	private function handleReview(): Response {
		$payload = $this->getPayload();
		if ($payload['action'] !== 'submitted') {
			return $this->generateResponse('Irrelevant action - skip.');
		}
		if (!in_array($payload['review']['author_association'], ReleasePullRequestGenerator::MAINTAINER_ASSOCIATIONS, true)) {
			return $this->generateResponse('Irrelevant review author - skip.');
		}
		if ($payload['review']['state'] !== 'approved') {
			return $this->generateResponse('Irrelevant review state - skip.');
		}
		if ($payload['pull_request']['state'] !== 'open') {
			return $this->generateResponse('Irrelevant pull request state - skip.');
		}

		$translations = $this->getTranslations();
		$subsplit = $translations->findSubsplitIdForRepository($payload['repository']['ssh_url'], $payload['pull_request']['base']['ref']);
		if ($subsplit === null) {
			return $this->generateResponse('Cannot find subsplit - skip.');
		}

		Yii::$app->queue->push(new MergeReleasePullRequestJob([
			'configFile' => $this->configFile,
			'subsplit' => $subsplit,
		]));

		return $this->generateResponse("Queued MergeReleasePullRequestJob for '$subsplit' subsplit.", 202);
	}

	private function getGithubEvent(): string {
		$event = Yii::$app->request->getHeaders()->get('X-GitHub-Event');
		if (empty($event)) {
			throw new BadRequestHttpException('Missing "X-GitHub-Event" header.');
		}

		return $event;
	}

	private function getPayload(): array {
		return Yii::$app->request->getBodyParams();
	}

	private function generateResponse(string $content, int $statusCode = 200): Response {
		$this->response->setStatusCode($statusCode);
		$this->response->content = $content;

		return $this->response;
	}

	private function getTranslations(): Translations {
		$config = require Yii::getAlias($this->configFile);
		return new Translations(Yii::$app->params['translationsRepository'], null, $config);
	}
}
