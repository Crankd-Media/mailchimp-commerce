<?php

/**
 * Mailchimp for Craft Commerce
 *
 * @link      https://crankdcreative.co.uk
 * @copyright Copyright (c) 2023 Crankd Creative
 */

namespace crankd\mc\jobs;

use Craft;
use craft\db\QueryAbortedException;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;
use crankd\mc\MailchimpCommerceSync;
use Throwable;
use yii\db\Exception;
use yii\queue\Queue;

/**
 * Class SyncPromos
 *
 * @author  Crankd Creative
 * @package crankd\mc\jobs
 */
class SyncPromos extends BaseJob
{

	// Properties
	// =========================================================================

	public $promoIds = [];

	// Methods
	// =========================================================================

	/**
	 * @param QueueInterface|Queue $queue
	 *
	 * @throws QueryAbortedException
	 * @throws Throwable
	 * @throws \yii\base\Exception
	 * @throws Exception
	 */
	public function execute($queue): void
	{
		$promos = MailchimpCommerceSync::$i->promos;
		$i = 0;
		$total = count($this->promoIds);

		$hasFailure = false;

		foreach ($this->promoIds as $id) {
			if (!$promos->syncPromoById($id)) {
				$hasFailure = true;
				Craft::error(
					'Failed to sync promo ' . $id,
					'mailchimp-commerce-sync'
				);
			}

			$this->setProgress($queue, $i++ / $total);
		}

		if ($hasFailure)
			throw new QueryAbortedException('Failed to sync promo');
	}

	protected function defaultDescription(): ?string
	{
		return MailchimpCommerceSync::t('Syncing Promos to Mailchimp');
	}
}