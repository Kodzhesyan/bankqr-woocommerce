<?php
/**
 * Plugin Name: IBAN Payment for Ukraine
* Description: Додає спосіб оплати через IBAN для українських банків з інтеграцією платіжного шлюзу bank-qr.com.ua
 * Version: 1.0.0
 * Author: Roman Kodzhesian
 * Author URI: https://www.facebook.com/roman.kodzhesyan
 * Text Domain: bankqr-gateway
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

//────────────────────────────────────────────
//  Ініціалізація шлюзу
//────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // WooCommerce не активний.
	}

	class WC_Gateway_BankQR extends WC_Payment_Gateway {

		// ─────────────── Конструктор ───────────────
		public function __construct() {
			$this->id                 = 'bankqr_gateway';
			$this->method_title       = __( 'Оплата через Bank‑QR', 'bankqr-gateway' );
			$this->method_description = __( 'Оплата переказом на IBAN із генерацією посилання Bank‑QR.', 'bankqr-gateway' );
			$this->icon               = plugin_dir_url( __FILE__ ) . 'assets/bankqr-logo.png';
			$this->has_fields         = false;

			// Добавляем стили для логотипа напрямую
			add_action('wp_head', function() {
				echo '<style>.payment_method_bankqr_gateway img { height: 25px !important; }</style>';
			});

			$this->init_form_fields();
			$this->init_settings();

			// Опції адміну
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->iban        = $this->get_option( 'iban' );
			$this->api_key     = $this->get_option( 'api_key' );
			$this->name        = $this->get_option( 'name' );
			$this->code        = $this->get_option( 'code' );
			$this->use_api     = $this->get_option( 'use_api' ) === 'yes';

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
			add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
			add_action( 'woocommerce_email_after_order_table', [ $this, 'email_instructions' ], 10, 3 );
		}

		// ───────────── Settings fields ─────────────
		public function init_form_fields() {
			$this->form_fields = [
				'enabled' => [
					'title'   => __( 'Увімкнути', 'bankqr-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Увімкнути Bank‑QR', 'bankqr-gateway' ),
					'default' => 'yes',
				],
				'use_api' => [
					'title'   => __( 'Використовувати API', 'bankqr-gateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Увімкнути інтеграцію з API Bank‑QR', 'bankqr-gateway' ),
					'default' => 'no',
					'description' => __( 'При вимкненні будуть показані тільки реквізити без QR-коду', 'bankqr-gateway' ),
				],
				'title' => [
					'title'   => __( 'Заголовок', 'bankqr-gateway' ),
					'type'    => 'text',
					'default' => __( 'Оплата через Bank‑QR', 'bankqr-gateway' ),
				],
				'description' => [
					'title' => __( 'Опис', 'bankqr-gateway' ),
					'type'  => 'textarea',
					'default' => __( 'Після оформлення замовлення зʼявиться кнопка для переходу на Bank‑QR.', 'bankqr-gateway' ),
				],
				'iban'    => [ 'title' => 'IBAN', 'type' => 'text' ],
				'name'    => [ 'title' => __( 'Отримувач', 'bankqr-gateway' ), 'type' => 'text' ],
				'code'    => [ 'title' => __( 'Код отримувача', 'bankqr-gateway' ), 'type' => 'text' ],
				'api_key' => [ 
					'title' => 'API‑KEY', 
					'type' => 'text',
					'description' => __( 'Потрібен тільки якщо увімкнена інтеграція з API', 'bankqr-gateway' ),
				],
			];
		}

		// ───────────── Fetch / Create payment URL ──
		private function get_payment_url( WC_Order $order ) {
			$cached = get_post_meta( $order->get_id(), '_bankqr_payment_url', true );
			if ( $cached ) {
				return $cached;
			}

			$body = [
				'account'     => $this->iban,
				'amount'      => $order->get_total(),
				'description' => 'Оплата замовлення №' . $order->get_id(),
				'name'        => $this->name,
				'code'        => $this->code,
			];

			$response = wp_remote_post( 'https://api.bank-qr.com.ua/v1/qr/create', [
				'headers' => [ 'API-KEY' => $this->api_key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			] );

			if ( is_wp_error( $response ) ) {
				return false;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['resultData']['pagePaymentLink'] ) ) {
				return false;
			}
			$payment_url = esc_url_raw( $data['resultData']['pagePaymentLink'] );
			update_post_meta( $order->get_id(), '_bankqr_payment_url', $payment_url );
			return $payment_url;
		}

		// ───────────── Банківські реквізити HTML ──
		private function details_html( $order_id ) {
			$purpose = 'Оплата замовлення №' . $order_id;
			return '<ul class="wc-bacs-bank-details order_details bacs-details">'
				. '<li>' . __( 'Отримувач', 'bankqr-gateway' ) . ': <strong>' . esc_html( $this->name ) . '</strong></li>'
				. '<li>IBAN: <strong>' . esc_html( $this->iban ) . '</strong></li>'
				. '<li>' . __( 'Код отримувача', 'bankqr-gateway' ) . ': <strong>' . esc_html( $this->code ) . '</strong></li>'
				. '<li>' . __( 'Призначення платежу', 'bankqr-gateway' ) . ': <strong>' . esc_html( $purpose ) . '</strong></li>'
				. '</ul>';
		}

		// ───────────── Process Payment ───────────
		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$order->update_status( 'on-hold', __( 'Очікується оплата через Bank‑QR', 'bankqr-gateway' ) );
			WC()->cart->empty_cart();
			return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ];
		}

		// ───────────── Thank‑you page ─────────────
		public function thankyou_page( $order_id ) {
			$order = wc_get_order( $order_id );
			
			echo '<section class="woocommerce-bacs-instructions"><h2>' . __( 'Реквізити для оплати', 'bankqr-gateway' ) . '</h2>';
			echo $this->details_html( $order_id ) . '</section>';

			if ( $this->use_api ) {
				$payment_url = $this->get_payment_url( $order );
				if ( $payment_url ) {
					// Добавляем сообщение о переадресації
					echo '<div id="bankqr-redirect-message" style="text-align:center; margin:10px 0;">' . 
						 __( 'Переадресація на сторінку оплати через <span>3</span> секунди...', 'bankqr-gateway' ) . 
						 '</div>';
					
					// Кнопка оплаты
					echo '<p style="text-align:center; margin:20px 0;">' .
						 '<a href="' . esc_url( $payment_url ) . '" class="button alt" id="bankqr-payment-button" ' . 
						 'style="background:#2ecc71; color:#fff; padding:10px 20px; border:none; border-radius:5px; text-decoration:none;">' .
						 __( 'Перейти до оплати', 'bankqr-gateway' ) . '</a></p>';

					// Обновленный скрипт для перенаправления
					?>
					<script type="text/javascript">
						document.addEventListener('DOMContentLoaded', function() {
							var seconds = 3;
							var paymentUrl = '<?php echo esc_js($payment_url); ?>';
							var countdownEl = document.querySelector('#bankqr-redirect-message span');
							var redirectInitiated = false;
							
							var timer = setInterval(function() {
								seconds--;
								if (countdownEl) {
									countdownEl.textContent = seconds;
								}
								
								if (seconds <= 0 && !redirectInitiated) {
									clearInterval(timer);
									redirectInitiated = true;
									// Перенаправляем на страницу оплаты
									window.location.href = paymentUrl;
								}
							}, 1000);

							// Обработчик клика по кнопке
							document.querySelector('#bankqr-payment-button').addEventListener('click', function(e) {
								clearInterval(timer);
								document.querySelector('#bankqr-redirect-message').style.display = 'none';
								redirectInitiated = true;
							});
						});
					</script>
					<?php
				}
			}
		}

		// ───────────── Email instructions ─────────
		public function email_instructions( $order, $sent_to_admin, $plain_text ) {
			if ( $sent_to_admin || $order->get_payment_method() !== $this->id ) {
				return;
			}

			echo '<section class="woocommerce-bacs-instructions"><h2>' . __( 'Реквізити для оплати', 'bankqr-gateway' ) . '</h2>';
			echo $this->details_html( $order->get_id() ) . '</section>';

			if ( $this->use_api ) {
				$payment_url = $this->get_payment_url( $order );
				if ( $payment_url ) {
					if ( $plain_text ) {
						echo PHP_EOL . __( 'Посилання для оплати:', 'bankqr-gateway' ) . ' ' . esc_url( $payment_url ) . PHP_EOL;
					} else {
						echo '<p style="text-align:center; margin:20px 0;">'
							. '<a href="' . esc_url( $payment_url ) . '" class="button alt" target="_blank" style="background:#2ecc71; color:#fff; padding:10px 20px; border:none; border-radius:5px; text-decoration:none;">'
							. __( 'Перейти до оплати', 'bankqr-gateway' ) . '</a></p>';
					}
				}
			}
		}

		// ───────────── Validation methods ─────────
		public function validate_api_key_field($key, $value) {
			$value = sanitize_text_field($value);
			if ($this->get_option('use_api') === 'yes' && empty($value)) {
				WC_Admin_Settings::add_error(__('API ключ обов\'язковий при використанні API', 'bankqr-gateway'));
				return '';
			}
			return $value;
		}

		public function validate_iban_field($key, $value) {
			$value = sanitize_text_field($value);
			if (!preg_match('/^UA[0-9]{27}$/', str_replace(' ', '', $value))) {
				WC_Admin_Settings::add_error(__('Невірний формат IBAN', 'bankqr-gateway'));
				return '';
			}
			return $value;
		}
	}

	// Регіструємо шлюз у WooCommerce
	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		$methods[] = 'WC_Gateway_BankQR';
		return $methods;
	} );
} );
