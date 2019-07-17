<?php

/*
 * This file is part of the flarum-translations-builder.
 *
 * Copyright (c) 2019 Robert Korulczyk <robert@korulczyk.pl>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Fake class to define code completion for IDE.
 *
 * Define your components as properties of this class to get code completion for `Yii::$app->`.
 *
 * @author Robert Korulczyk
 */
class Yii extends \yii\BaseYii {

	/**
	 * @var BaseApplication|WebApplication|ConsoleApplication the application instance
	 */
	public static $app;
}

abstract class BaseApplication extends \yii\base\Application {

}

class WebApplication extends \yii\web\Application {

//	/**
//	 * @var \app\components\MyComponent
//	 */
//	public $myComponent;
}

class ConsoleApplication extends \yii\console\Application {

}
