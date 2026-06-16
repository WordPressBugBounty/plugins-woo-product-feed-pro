jQuery(document).ready(function($) {
	// For shop pages
	$(".add_to_cart_button").click(function(){
		var productId = $(this).attr('data-product_id');

		// Ajax frontend
		var inputdata = {
			'action': 'woosea_addtocart_details',
			'data_to_pass': productId,
		}

		$.post(adtFrontEndAjax.ajaxurl, inputdata, function( response ) {
			fbq("track", "AddToCart", {
				content_ids: [String(response.product_id)],
				content_name: response.product_name,
				content_category: response.product_cats,
				content_type: "product",
				value: response.product_price,
				currency: response.product_currency,
			});
		}, 'json' );
	});

	// For product pages
	$(".single_add_to_cart_button").click(function(){
		var productId = $('input[name=product_id]').val();

		if(!productId){
			productId = $(this).attr('value');
		}

		// Ajax frontend
		var inputdata = {
			'action': 'woosea_addtocart_details',
			'data_to_pass': productId,
		}

		$.post(adtFrontEndAjax.ajaxurl, inputdata, function( response ) {
			fbq("track", "AddToCart", {
				content_ids: [String(response.product_id)],
				content_name: response.product_name,
				content_category: response.product_cats,
				content_type: "product",
				value: response.product_price,
				currency: response.product_currency,
			});
		}, 'json' );
	});
});
