<?php

add_action('plugins_loaded','mycred_payir_plugins_loaded');

function mycred_payir_plugins_loaded()
{
	add_filter('mycred_setup_gateways', 'Add_Payir_to_Gateways');

	function Add_Payir_to_Gateways($installed)
	{
		$installed['payir'] = array(

			'title'    => get_option('payir_name') ? get_option('payir_name') : 'درگاه پرداخت و کیف پول الکترونیک Pay.ir',
			'callback' => array('myCred_Payir')
		);

		return $installed;
	}

	add_filter('mycred_buycred_refs', 'Add_Payir_to_Buycred_Refs');

	function Add_Payir_to_Buycred_Refs($addons)
	{
		$addons['buy_creds_with_payir'] = __('Buy Cred Purchase (Pay.ir)', 'mycred');

		return $addons;
	}
	
	add_filter('mycred_buycred_log_refs', 'Add_Payir_to_Buycred_Log_Refs');

	function Add_Payir_to_Buycred_Log_Refs($refs)
	{
		$payir = array('buy_creds_with_payir');

		return $refs = array_merge($refs, $payir);
	}
}

spl_autoload_register('mycred_payir_plugin');

function mycred_payir_plugin()
{
	if (!class_exists('myCRED_Payment_Gateway')) {

		return;
	}
	
	if (!class_exists('myCred_Payir')) {

		class myCred_Payir extends myCRED_Payment_Gateway 
		{
			function __construct($gateway_prefs)
			{
				$types = mycred_get_types();
				$default_exchange = array();

				foreach ($types as $type => $label) {

					$default_exchange[$type] = 1000;
				}

				parent::__construct(array(

					'id'       => 'payir',
					'label'    => get_option('payir_name') ? get_option('payir_name') : 'درگاه پرداخت و کیف پول الکترونیک Pay.ir',
					'defaults' => array(

						'payir_api'  => NULL,
						'payir_name' => 'درگاه پرداخت و کیف پول الکترونیک Pay.ir',
						'currency'   => 'ریال',
						'exchange'   => $default_exchange,
						'item_name'  => __('Purchase of myCRED %plural%', 'mycred')
					)
				), $gateway_prefs);
			}

			public function Payir_Iranian_currencies($currencies)
			{
				unset($currencies);

				$currencies['ریال']  = 'ریال';
				$currencies['تومان'] = 'تومان';

				return $currencies;
			}

			function preferences()
			{
				add_filter('mycred_dropdown_currencies', array($this, 'Payir_Iranian_currencies'));

				$prefs = $this->prefs;
				?>

				<label class="subheader" for="<?php echo $this->field_id('payir_api'); ?>"><?php _e('API Key', 'mycred'); ?></label>
				<ol>
					<li>
						<div class="h2">
							<input id="<?php echo $this->field_id('payir_api'); ?>" name="<?php echo $this->field_name('payir_api'); ?>" type="text" value="<?php echo $prefs['payir_api']; ?>" class="long" />
						</div>
					</li>
				</ol>

				<label class="subheader" for="<?php echo $this->field_id('payir_name'); ?>"><?php _e('Title', 'mycred'); ?></label>
				<ol>
					<li>
						<div class="h2">
							<input id="<?php echo $this->field_id('payir_name'); ?>" name="<?php echo $this->field_name('payir_name'); ?>" type="text" value="<?php echo $prefs['payir_name'] ? $prefs['payir_name'] : 'درگاه پرداخت و کیف پول الکترونیک Pay.ir'; ?>" class="long" />
						</div>
					</li>
				</ol>

				<label class="subheader" for="<?php echo $this->field_id('currency'); ?>"><?php _e('Currency', 'mycred'); ?></label>
				<ol>
					<li>
						<?php $this->currencies_dropdown('currency', 'mycred-gateway-payir-currency'); ?>
					</li>
				</ol>

				<label class="subheader" for="<?php echo $this->field_id('item_name'); ?>"><?php _e('Item Name', 'mycred'); ?></label>
				<ol>
					<li>
						<div class="h2">
							<input id="<?php echo $this->field_id('item_name'); ?>" name="<?php echo $this->field_name('item_name'); ?>" type="text" value="<?php echo $prefs['item_name']; ?>" class="long" />
						</div>
						<span class="description"><?php _e( 'Description of the item being purchased by the user.', 'mycred' ); ?></span>
					</li>
				</ol>

				<label class="subheader"><?php _e('Exchange Rates', 'mycred'); ?></label>
				<ol>
					<li>
						<?php $this->exchange_rate_setup(); ?>
					</li>
				</ol>
			<?php
            }

			public function sanitise_preferences($data)
			{
				$new_data['payir_api']  = sanitize_text_field($data['payir_api']);
				$new_data['payir_name'] = sanitize_text_field($data['payir_name']);
				$new_data['currency']   = sanitize_text_field($data['currency']);
				$new_data['item_name']  = sanitize_text_field($data['item_name']);

				if (isset($data['exchange'])) {

					foreach ((array)$data['exchange'] as $type => $rate) {

						if ($rate != 1 && in_array(substr($rate, 0, 1), array('.', ','))) {

							$data['exchange'][$type] = (float)'0' . $rate;
						}
					}
				}

				$new_data['exchange'] = $data['exchange'];

				update_option('payir_name', $new_data['payir_name']);

				return $data;
			}

			public function buy()
			{
				if (!isset($this->prefs['payir_api']) || empty($this->prefs['payir_api'])) {

					wp_die( __('Please setup this gateway before attempting to make a purchase!', 'mycred'));
				}

				$type   = $this->get_point_type();
				$mycred = mycred($type);

				$amount = $mycred->number($_REQUEST['amount']);
				$amount = abs($amount);
				$cost   = $this->get_cost($amount, $type);

				$to   = $this->get_to();
				$from = $this->current_user_id;

				if (isset($_REQUEST['revisit'])) {

					$payment = strtoupper($_REQUEST['revisit']);

					$this->transaction_id = $payment;

				} else {

					$post_id = $this->add_pending_payment(array($to, $from, $amount, $cost, $this->prefs['currency'], $type));
					$payment = get_the_title($post_id);

					$this->transaction_id = $payment;
				}

				$item_name = str_replace('%number%', $amount, $this->prefs['item_name']);
				$item_name = $mycred->template_tags_general($item_name);

				$from_user = get_userdata($from);

				if (extension_loaded('curl')) {

					$api_key  = $this->prefs['payir_api'];  
					$callback = add_query_arg('payment_id', $this->transaction_id, $this->callback_url());

					$amount = ($this->prefs['currency'] == 'ریال') ? $cost : ($cost * 10);
					$amount = intval(str_replace(',' , '', $amount));

					$params = array(

						'api'          => $api_key,
						'amount'       => $amount,
						'redirect'     => urlencode($callback),
						'factorNumber' => $payment
					);

					$result = $this->common('https://pay.ir/payment/send', $params);

					if ($result && isset($result->status) && $result->status == 1) {

						$message = 'شماره تراکنش ' . $result->transId;

						$this->log_call($payment, array(__($message, 'mycred')));

						$gateway_url = 'https://pay.ir/payment/gateway/' . $result->transId;

						wp_redirect($gateway_url);
						exit;

					} else {

						$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
						$message = isset($result->errorMessage) ? $result->errorMessage : $message;

						$this->log_call($payment, array(__($message, 'mycred')));

						wp_die($message);
						exit;
					}

				} else {

					$message = 'تابع cURL در سرور فعال نمی باشد';

					$this->log_call($payment, array(__($message, 'mycred')));

					wp_die($message);
					exit;
				}
			}

			public function process()
			{
				$fault = FALSE;

				if (isset($_REQUEST['payment_id']) && isset($_REQUEST['mycred_call']) && $_REQUEST['mycred_call'] == 'payir') {	

					$pending_post_id     = sanitize_text_field($_REQUEST['payment_id']);
					$org_pending_payment = $pending_payment = $this->get_pending_payment($pending_post_id);

					if (isset($_POST['status']) && isset($_POST['transId']) && isset($_POST['factorNumber'])) {

						if (is_object($pending_payment)) {

							$pending_payment = (array)$pending_payment;
						}

						if ($pending_payment !== FALSE) {

							$status        = sanitize_text_field($_POST['status']);
							$trans_id      = sanitize_text_field($_POST['transId']);
							$factor_number = sanitize_text_field($_POST['factorNumber']);
							$message       = sanitize_text_field($_POST['message']);

							if (isset($status) && $status == 1) {

								$api_key = $this->prefs['payir_api'];

								$params = array (

									'api'     => $api_key,
									'transId' => $trans_id
								);

								$result = $this->common('https://pay.ir/payment/verify', $params);

								if ($result && isset($result->status) && $result->status == 1) {

									$card_number = isset($_POST['cardNumber']) ? sanitize_text_field($_POST['cardNumber']) : 'Null';

									$cost = (str_replace(',', '', $pending_payment['cost']));
									$cost = (int)$cost;

									$amount = ($this->prefs['currency'] == 'ریال') ? $cost : ($cost * 10);

									if ($amount == $result->amount) {

										if ($this->complete_payment($org_pending_payment, $trans_id)) {

											$message = 'تراکنش شماره ' . $trans_id . ' با موفقیت انجام شد. شماره کارت پرداخت کننده ' . $card_number;

											$this->log_call($pending_post_id, array(__($message, 'mycred')));
											//$this->trash_pending_payment($pending_post_id);

											wp_redirect($this->get_thankyou());
											exit;

										} else {

											$fault   = TRUE;
											$message = 'در حین تراکنش خطای نامشخصی رخ داده است';

											$this->log_call($pending_post_id, array(__($message, 'mycred')));
										}

									} else {

										$fault   = TRUE;
										$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';

										$this->log_call($pending_post_id, array(__($message, 'mycred')));
									}

								} else {

									$fault   = TRUE;
									$message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
									$message = isset($result->errorMessage) ? $result->errorMessage : $message;

									$this->log_call($pending_post_id, array(__($message, 'mycred')));
								}

							} else {

								$fault = TRUE;

								if ($message) {

									$this->log_call($pending_post_id, array(__($message, 'mycred')));
									
								} else {

									$message = 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';

									$this->log_call($pending_post_id, array(__($message, 'mycred')));
								}
							}

						} else {

							$fault   = TRUE;
							$message = 'در حین تراکنش خطای نامشخصی رخ داده است';

							$this->log_call($pending_post_id, array(__($message, 'mycred')));
						}

					} else {

						$fault   = TRUE;
						$message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';

						$this->log_call($pending_post_id, array(__($message, 'mycred')));
					}

				} else {

					$fault = TRUE;

					wp_redirect($this->get_cancelled(''));
					exit;
				}

				if ($fault) {

					wp_redirect($this->get_cancelled(''));
					exit;
				}
			}

			public function returning()
			{
				if (isset($_REQUEST['payment_id']) && isset($_REQUEST['mycred_call']) && $_REQUEST['mycred_call'] == 'payir') {

					// Returning Actions
				}
			}

			private static function common($url, $params)
			{
				$ch = curl_init();

				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

				$response = curl_exec($ch);
				$error    = curl_errno($ch);

				curl_close($ch);

				$output = $error ? FALSE : json_decode($response);

				return $output;
			}
		}
	}
}
