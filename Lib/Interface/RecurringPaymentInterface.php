<?php
/**
 *
 */
interface RecurringPaymentsInterface {

	public function cancelSubscription($id, array $options = array());

	public function createSubscription($data);

	public function updateSubscription($data);

}