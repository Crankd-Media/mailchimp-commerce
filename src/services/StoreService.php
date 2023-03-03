<?php

/**
 * Mailchimp for Craft Commerce
 *
 * @link      https://crankdcreative.co.uk
 * @copyright Copyright (c) 2023 Crankd Creative
 */

namespace crankd\mc\services;

use Craft;
use Throwable;
use craft\helpers\App;
use yii\base\Exception;
use craft\base\Component;
use craft\elements\Address;
use crankd\mc\MailchimpCommerceSync;
use crankd\mc\migrations\Install;
use crankd\mc\helpers\AddressHelper;
use yii\base\InvalidConfigException;
use craft\commerce\Plugin as Commerce;
use craft\errors\SiteNotFoundException;
use craft\errors\ElementNotFoundException;

/**
 * Class StoreService
 *
 * @author  Crankd Creative
 * @package crankd\mc\services
 */
class StoreService extends Component
{

	/**
	 * Generates a unique store ID and saves it in the plugin settings
	 *
	 * @throws Exception
	 */
	public function setStoreId()
	{
		$i = MailchimpCommerceSync::$i;
		if ($i->getSettings()->storeId)
			return;

		Craft::$app->getPlugins()->savePluginSettings(MailchimpCommerceSync::$i, [
			'storeId' => Craft::$app->getSecurity()->generateRandomString(),
		]);
	}

	/**
	 * Creates the Mailchimp store
	 *
	 * @param $listId
	 *
	 * @return boolean
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 */
	public function create($listId)
	{
		if (MailchimpCommerceSync::getInstance()->getSettings()->disableSyncing)
			return true;

		$i = MailchimpCommerceSync::$i;

		if ($i->getSettings()->listId) {
			Craft::error('You can\'t change the list ID', 'mailchimp-commerce-sync');
			return false;
		}

		// dd($this->_buildStoreData($listId));

		list($success, $data, $error) = MailchimpCommerceSync::$i->chimp->post(
			'ecommerce/stores',
			$this->_buildStoreData($listId)
		);

		if ($error) {
			Craft::error($error, 'mailchimp-commerce-sync');
			return $success;
		}

		Craft::$app->getPlugins()->savePluginSettings(MailchimpCommerceSync::$i, [
			'listId' => $listId,
		]);

		return $success;
	}

	/**
	 * Updates the current store
	 *
	 * @return mixed
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 */
	public function update()
	{
		if (MailchimpCommerceSync::getInstance()->getSettings()->disableSyncing)
			return true;

		$id = MailchimpCommerceSync::$i->getSettings()->storeId;

		list($success, $data, $error) = MailchimpCommerceSync::$i->chimp->patch(
			'ecommerce/stores/' . $id,
			$this->_buildStoreData()
		);

		if ($error)
			Craft::error($error, 'mailchimp-commerce-sync');

		return $success;
	}

	/**
	 * Deletes the store from Mailchimp (include all synced products, orders,
	 * etc.)
	 *
	 * @throws Exception
	 */
	public function delete()
	{
		if (MailchimpCommerceSync::getInstance()->getSettings()->disableSyncing)
			return;

		try {
			MailchimpCommerceSync::$i->chimp->delete(
				'ecommerce/stores/' .
					MailchimpCommerceSync::$i->getSettings()->storeId
			);
		} catch (\Exception $e) {
		}

		Craft::$app->getPlugins()->savePluginSettings(MailchimpCommerceSync::$i, [
			'storeId' => null,
			'listId' => null,
		]);

		MailchimpCommerceSync::$i->store->setStoreId();

		ob_start();
		(new Install())->safeDown();
		(new Install())->safeUp();
		ob_end_clean();
	}

	// Helpers
	// =========================================================================

	/**
	 * Build the store data for syncing
	 *
	 * @param null|string $listId - Should only be set when creating a store
	 *
	 * @return array
	 * @throws SiteNotFoundException
	 * @throws Throwable
	 * @throws ElementNotFoundException
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	private function _buildStoreData($listId = null)
	{
		$primarySite = Craft::$app->getSites()->getPrimarySite();
		$dummyCart   = Commerce::getInstance()->getCarts()->getCart();

		$id = MailchimpCommerceSync::$i->getSettings()->storeId;

		// if id null this setStoreId
		if (!$id) {
			$id = MailchimpCommerceSync::$i->store->setStoreId();
		}

		$storeData = [
			'id'            => $id,
			'platform'      => 'Craft Commerce',
			'name'          => $this->getStoreName(),
			'domain'        => $primarySite->getBaseUrl(),
			'email'         => $this->_getStoreEmail(),
			'currency_code' => $dummyCart->getPaymentCurrency(),
		];

		if ($listId)
			$storeData['list_id'] = $listId;

		$storeLocation = Address::find()->all();

		// Craft::dd($storeLocation);

		// $storeLocation = Commerce::getInstance()->getAddresses()->getStoreLocationAddress();

		if ($storeLocation) {
			$storeData['address'] = array_filter(@AddressHelper::asArray($storeLocation[0]));
		}

		return $storeData;
	}

	/**
	 * Get the name of the store (or primary site)
	 *
	 * @return string|null
	 * @throws SiteNotFoundException
	 */
	public function getStoreName()
	{
		$commerceSettings = Commerce::getInstance()->getSettings();
		$primarySite = Craft::$app->getSites()->getPrimarySite();

		return $commerceSettings->emailSenderName
			?: $commerceSettings->emailSenderNamePlaceholder
			?: $primarySite->name;
	}

	/**
	 * Get the stores email address
	 *
	 * @return string|null
	 */
	private function _getStoreEmail()
	{
		$commerceSettings = Commerce::getInstance()->getSettings();

		return $commerceSettings->emailSenderAddress
			?: $commerceSettings->emailSenderAddressPlaceholder
			?: App::mailSettings()->fromEmail;
	}
}