<?php
App::uses('CartAppController', 'Cart.Controller');
App::uses('CakeEventManager', 'Event');
App::uses('CakeEvent', 'Event');
App::uses('PaymentProcessors', 'Cart.Payment');
/**
 * Carts Controller
 *
 * @author Florian Krämer
 * @copyright 2012 Florian Krämer
 */
class CartsController extends CartAppController {
/**
 * Components
 *
 * @var array
 */
	public $components = array(
		'Cart.CartManager',
		'Session');

/**
 * beforeFilter callback
 *
 * @return void
 */
	public function beforeFilter() {
		parent::beforeFilter();
		$this->Auth->allow('index', 'view', 'remove_item', 'checkout', 'callback');
	}

/**
 * Display all carts a user has, active one first
 *
 * @return void
 */
	public function index() {
		$this->paginate = array(
			'contain' => array(),
			'order' => array('Cart.active DESC'),
			'conditions' => array(
				'Cart.user_id' => $this->Auth->user('id')));
		$this->set('carts', $this->paginate());
	}

/**
 * Shows a cart for a user
 *
 * @param
 * @return void
 */
	public function view($cartId = null) {
		if (!empty($this->request->data)) {
			//debug($this->request->data);
			$cart = $this->CartManager->content();
			foreach ($this->request->data['CartsItem'] as $key => $cartItem) {
				$cartItem = Set::merge($cart['CartsItem'][$key], $cartItem);
				$this->CartManager->addItem($cartItem);
			}
		}

		$cart = $this->CartManager->content();

		$this->request->data = $cart;
		$this->set('cart', $cart);
		$this->set('paymentMethods', ClassRegistry::init('Cart.PaymentMethod')->getPaymentMethods());
	}

/**
 * Removes an item from the cart
 */
	public function remove_item() {
		if (!isset($this->request->named['model']) || !isset($this->request->named['id'])) {
			$this->Session->setFlash(__d('cart', 'Invalid cart item'));
			$this->redirect($this->referer());
		}

		$result = $this->CartManager->removeItem(array(
			'foreign_key' => $this->request->named['id'],
			'model' => $this->request->named['model']));

		if ($result) {
			$this->Session->setFlash(__d('cart', 'Item removed'));
		} else {
			$this->Session->setFlash(__d('cart', 'Could not remove item'));
		}

		$this->redirect($this->referer());
	}

/**
 * Default callback entry point for API callbacks for payment processors
 *
 * @param string $processor
 * @return void
 */
	public function callback($processor = Null, $action = null) {
		$this->log($_POST, 'cart-callback');
		$this->log($_GET, 'cart-callback');

		// @todo check for valid processor?
		//$Processor = PaymentProcessors::load($processor, array('request' => $this->request, 'response' => $this->response));
		if (empty($processor)) {
			$this->cakeError(404);
		}

		CakeEventManager::dispatch(new CakeEvent('Payment.callback', $this->request));
	}

/**
 * Triggers the checkout
 *
 * - checks if the cart is not empty
 * - checks if the payment processor is valid
 *
 * @param 
 * @return void
 */
	public function checkout($processor = null, $action = Null) {
		$cartData = $this->CartManager->content();
		if (empty($cartData['CartsItem'])) {
			$this->Session->setFlash(__d('cart', 'Your cart is empty.'));
			$this->redirect(array('action' => 'view'));
		}

		$this->__anonymousCheckoutIsAllowed();

		$processorClass = $this->__mapProcessorClass($processor);
		$Processor = $this->__loadPaymentProcessor($processorClass);

		$Order = ClassRegistry::init('Cart.Order');
		$newOrder = $Order->createOrder($cartData, $processorClass);

		if ($newOrder) {
			$this->CartManager->emptyCart();

			$ApiLog = ClassRegistry::init('Cart.PaymentApiTransaction');
			$token = $ApiLog->initialize($processorClass, $newOrder['Order']['id']);
			$newOrder['Order']['token'] = $token;
			$Order->saveField('token', $token);

			$Processor->cancelUrl[] = CakeSession::read('Payment.token');
			$Processor->returnUrl[] = CakeSession::read('Payment.token');
			$Processor->checkout($newOrder);
		}

		$this->Session->setFlash(__d('cart', 'There was a problem creating your order.'));
		$this->redirect(array('action' => 'view'));
	}

/**
 * Last step for so called express checkout processors
 *
 * @return void
 */
	public function confirm_order($transactionToken = null) {
		if ($transactionToken != CakeSession::read('Payment.token')) {
			$this->Session->setFlash(__d('cart', 'Invalid Order'));
			$this->redirect('/');
		}

		$Order = ClassRegistry::init('Cart.Order');
		$order = $Order->find('first', array(
			'contain' => array(),
			'conditions' => array(
				'Order.id' => CakeSession::read('Payment.orderId'))));

		$Processor = $this->__loadPaymentProcessor($order['Order']['processor']);

		if (!method_exists($Processor, 'confirmOrder')) {
			$this->Session->setFlash(__d('cart', 'Unsupported payment processor for this type of checkout!'));
			$this->redirect(array('action' => 'view'));
		}

		debug($Processor->confirmOrder($order));

		//$ApiLog = ClassRegistry::init('Cart.PaymentApiTransaction');
		//$ApiLog->finish($processor, $neworder['Order']['id']);
	}

/**
 * @todo
 */
	public function finish_order($transactionToken) {
		
	}

/**
 * @todo
 */
	public function cancel_order($transactionToken) {
		
	}

/**
 * Loads the processor instance, handles errors and redirects in the case of an error
 *
 * @param string $processor
 * @return PaymentProcessor
 */
	protected function __loadPaymentProcessor($processor) {
		try {
			return PaymentProcessors::load($processor, array('request' => $this->request, 'response' => $this->response));
		} catch (MissingPaymentProcessorException $e) {
			$this->Session->setFlash(__d('cart', 'The payment method does not exist!'));
			$this->redirect(array('action' => 'view'));
		} catch (Exception $e) {
			$this->Session->setFlash($e->getMessage());
			$this->redirect(array('action' => 'view'));
		}
	}

/**
 * Checks if the processor name is mapped in the static configure class or if 
 * it is mapped in the PaymentMethod model.
 *
 * The payment method model gives you greater flexibility to en/disable processors
 * on the fly through the admin backend.
 *
 * If no mapped processor classname is found it will return the passed name and
 * the PaymentProcessors::load() method will throw an exception if it cant find
 * that processor.
 *
 * @param string $processorAlias
 * @return string
 */
	protected function __mapProcessorClass($processorAlias) {
		$processorClass = Configure::read('Cart.PaymentMethod.'. $processorAlias . '.processor');
		if (!empty($processorClass)) {
			return $processorClass;
		}
		$processorClass = ClassRegistry::init('Cart.PaymentMethod')->getMappedClassName($processorAlias);
		if (!empty($processorClass)) {
			return $processorClass;
		}
		return $processorAlias;
	}

/**
 * __allowAnonymousCheckout
 *
 * @param boolean $redirect
 * @return boolean
 */
	protected function __anonymousCheckoutIsAllowed($redirect = true) {
		if (Configure::read('Cart.anonymousCheckout') === false && is_null($this->Auth->user())) {#
			$this->Session->setFlash(__d('cart', 'Sorry, but you have to login to check this cart out.'));
			if ($redirect) {
				$this->redirect(array('action' => 'view'));
			}
			return false;
		}
		return true;
	}

/**
 * 
 */
	public function admin_index() {
		$this->set('carts', $this->paginate());
	}

/**
 * 
 */
	public function admin_view($cartId = null) {
		$this->set('cart', $this->Cart->view($cartId));
	}

/**
 *
 */
	public function admin_delete($cartId = null) {
		$this->Cart->delete($cartId);
	}

}