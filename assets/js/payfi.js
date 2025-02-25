jQuery(function ($) {
	var payfi_submit = false;

	jQuery('#tbz-payfi-wc-payment-button').click(function () {
		return tbzWCPayfiPaymentHandler();
	});

	function tbzWCPayfiPaymentHandler() {
		if (payfi_submit) {
			payfi_submit = false;
			return true;
		}

		var $form = $('form#payment-form, form#order_review'),
			payfi_txnref = $form.find('input.tbz_wc_payfi_txnref');

		payfi_txnref.val('');

		var payfi_callback = function (response) {
			$form.append(
				'<input type="hidden" class="tbz_wc_payfi_txnref" name="tbz_wc_payfi_txnref" value="' +
				response.reference +
				'"/>'
			);

			payfi_submit = true;

			$form.submit();

			$('body').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6,
				},
				css: {
					cursor: 'wait',
				},
			});
		};

		const payfi = new Payfi({
			apiKey: tbz_wc_payfi_params.public_key,
			callback: payfi_callback,

			meta: {
				channel: 'wordpress',
				order: tbz_wc_payfi_params.orderList,
				billingDetails: {
					email: tbz_wc_payfi_params.customer_email,
					phone: tbz_wc_payfi_params.customer_phone,
					firstName: tbz_wc_payfi_params.customer_first_name,
					lastName: tbz_wc_payfi_params.customer_last_name,
				}
			},
			//meta: tbz_wc_payfi_params.orderList,
			onClose: () => {
				$(this.el).unblock();
			},
		});
		payfi.pay({
			amount: tbz_wc_payfi_params.amount,
			reference: `${tbz_wc_payfi_params.txref}_${Date.now()}`,
			meta: {
				channel: 'wordpress',
				order: tbz_wc_payfi_params.orderList,
				billingDetails: {
					email: tbz_wc_payfi_params.customer_email,
					phone: tbz_wc_payfi_params.customer_phone,
					firstName: tbz_wc_payfi_params.customer_first_name,
					lastName: tbz_wc_payfi_params.customer_last_name,
				}
			},
		});

		return false;
	}
});

