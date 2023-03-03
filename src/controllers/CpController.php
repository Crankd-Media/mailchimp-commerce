<?php

/**
 * Mailchimp for Craft Commerce
 *
 * @link      https://crankdcreative.co.uk
 * @copyright Copyright (c) 2023 Crankd Creative
 */

namespace crankd\mc\controllers;

use Craft;
use craft\base\Field;
use craft\fields\Assets;
use craft\web\Controller;
use craft\elements\Address;
use craft\models\FieldGroup;
use craft\fields\Lightswitch;
use crankd\mc\MailchimpCommerceSync;
use craft\models\AssetTransform;
use craft\commerce\records\Discount;
use craft\commerce\models\OrderStatus;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Store;
use craft\models\ImageTransform;

/**
 * Class CpController
 *
 * @author  Crankd Creative
 * @package crankd\mc\controllers
 */
class CpController extends Controller
{

	public function actionIndex()
	{
		$settings = MailchimpCommerceSync::$i->getSettings();


		if ($settings->apiKey && $settings->listId)
			return $this->redirect('mailchimp-commerce-sync/sync');

		return $this->redirect('mailchimp-commerce-sync/connect');
	}

	public function actionConnect()
	{
		$this->requireAdmin();

		return $this->renderTemplate('mailchimp-commerce-sync/_connect', [
			'settings' => MailchimpCommerceSync::$i->getSettings(),
		]);
	}

	public function actionList()
	{
		$this->requireAdmin();

		$storeLocation = Commerce::getInstance()
			->getStore()
			->getStore()
			->getLocationAddress();

		$hasCountry = $storeLocation && $storeLocation->countryCode;

		return $this->renderTemplate('mailchimp-commerce-sync/_list', [
			'settings' => MailchimpCommerceSync::$i->getSettings(),
			'lists' => MailchimpCommerceSync::$i->lists->all(),
			'hasCountry' => $hasCountry,
		]);
	}

	public function actionSync()
	{
		$i = MailchimpCommerceSync::$i;

		return $this->renderTemplate('mailchimp-commerce-sync/_sync', [
			'settings' => $i->getSettings(),
			'totalProductsSynced' => $i->products->getTotalProductsSynced(),
			'products' => $this->_getProducts(),
			'totalCartsSynced' => $i->orders->getTotalOrdersSynced(true),
			'totalOrdersSynced' => $i->orders->getTotalOrdersSynced(),
			'totalPromosSynced' => $i->promos->getTotalPromosSynced(),
			'totalDiscounts' => Discount::find()->count(),
		]);
	}

	public function actionMappings()
	{
		$this->requireAdmin();

		$fields = array_reduce(
			Craft::$app->getFields()->getAllGroups(),
			function (array $a, FieldGroup $group) {
				$a[] = [
					'optgroup' => $group->name,
				];

				/** @var Field $field */
				foreach ($group->getFields() as $field) {
					$a[] = [
						'label' => $field->name,
						'value' => $field->uid,
					];
				}

				return $a;
			},
			[
				['label' => MailchimpCommerceSync::t('None'), 'value' => ''],
			]
		);
		$assetFields = array_reduce(
			Craft::$app->getFields()->getAllGroups(),
			function (array $a, FieldGroup $group) {
				$fields = [];

				/** @var Field $field */
				foreach ($group->getFields() as $field) {
					if (!($field instanceof Assets))
						continue;

					$fields[] = [
						'label' => $field->name,
						'value' => $field->uid,
					];
				}

				if (empty($fields))
					return $a;

				$a[] = [
					'optgroup' => $group->name,
				];

				return array_merge($a, $fields);
			},
			[
				['label' => MailchimpCommerceSync::t('None'), 'value' => ''],
			]
		);
		$lightswitchFields = array_reduce(
			Craft::$app->getFields()->getAllGroups(),
			function (array $a, FieldGroup $group) {
				$fields = [];

				/** @var Field $field */
				foreach ($group->getFields() as $field) {
					if (!($field instanceof Lightswitch))
						continue;

					$fields[] = [
						'label' => $field->name,
						'value' => $field->uid,
					];
				}

				if (empty($fields))
					return $a;

				$a[] = [
					'optgroup' => $group->name,
				];

				return array_merge($a, $fields);
			},
			[
				['label' => MailchimpCommerceSync::t('None'), 'value' => ''],
			]
		);

		return $this->renderTemplate('mailchimp-commerce-sync/_mappings', [
			'settings' => MailchimpCommerceSync::$i->getSettings(),
			'products' => $this->_getProducts(),
			'fields' => $fields,
			'assetFields' => $assetFields,
			'lightswitchFields' => $lightswitchFields,
		]);
	}

	public function actionSettings()
	{
		$this->requireAdmin();

		$orderStatuses = array_map(function (OrderStatus $orderStatus) {
			return [
				'label' => $orderStatus->name,
				'value' => $orderStatus->handle,
			];
		}, Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses());

		$imageTransforms = array_reduce(
			Craft::$app->getImageTransforms()->getAllTransforms(),
			function ($a, ImageTransform $transform) {
				$a[] = [
					'label' => $transform->name,
					'value' => $transform->uid,
				];

				return $a;
			},
			[['label' => MailchimpCommerceSync::t('None'), 'value' => '']]
		);

		return $this->renderTemplate('mailchimp-commerce-sync/_settings', [
			'settings' => MailchimpCommerceSync::$i->getSettings(),
			'orderStatuses' => $orderStatuses,
			'imageTransforms' => $imageTransforms,
		]);
	}

	public function actionPurge()
	{
		$this->requireAdmin();

		return $this->renderTemplate('mailchimp-commerce-sync/_purge');
	}

	// Helpers
	// =========================================================================

	private function _getProducts()
	{
		$products          = [];
		$mailchimpProducts =
			MailchimpCommerceSync::getInstance()->chimp->getProducts();

		foreach ($mailchimpProducts as $mcProduct) {
			$types = $mcProduct->getProductTypes;
			$types = $types();
			$productTypes = array_reduce(
				$types,
				function ($a, $type) {
					$a[] = [
						'label' => $type->name,
						'value' => $type->id,
					];

					return $a;
				},
				[
					[
						'label' => MailchimpCommerceSync::t('All') . ' ' . $mcProduct->productName,
						'value' => '',
					]
				]
			);

			$products[] = [
				'name'  => $mcProduct->productName,
				'class' => $mcProduct->productClass,
				'types' => $types,
				'typeOptions' => $productTypes,
			];
		}

		return $products;
	}
}