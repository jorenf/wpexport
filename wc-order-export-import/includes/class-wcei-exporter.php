<?php
/**
 * CSV Exporter for WooCommerce orders.
 *
 * Queries orders by date range and streams a UTF-8 CSV file to the browser.
 *
 * @package WC_Order_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class WCEI_Exporter {

	/**
	 * CSV column headers — must stay in sync with WCEI_Importer::EXPECTED_HEADERS.
	 */
	const CSV_HEADERS = array(
		'order_number',
		'order_date',
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'products',
		'total',
		'payment_status',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
	);

	/**
	 * Export orders within the given date range as a CSV download.
	 *
	 * Outputs CSV directly and calls exit() on success so WordPress does not
	 * append any HTML. Returns a WP_Error on validation/query failure.
	 *
	 * @param  string $start_date Start date in YYYY-MM-DD format.
	 * @param  string $end_date   End date in YYYY-MM-DD format.
	 * @return WP_Error|void      WP_Error on failure; otherwise exits after streaming.
	 */
	public static function export( $start_date, $end_date ) {
		// ── Validate input dates ────────────────────────────────────────────
		if ( empty( $start_date ) || empty( $end_date ) ) {
			return new WP_Error( 'missing_dates', __( 'Please provide both a start date and an end date.', 'wc-order-export-import' ) );
		}

		if ( ! self::is_valid_date( $start_date ) ) {
			return new WP_Error( 'invalid_start_date', __( 'Invalid start date. Use YYYY-MM-DD format.', 'wc-order-export-import' ) );
		}

		if ( ! self::is_valid_date( $end_date ) ) {
			return new WP_Error( 'invalid_end_date', __( 'Invalid end date. Use YYYY-MM-DD format.', 'wc-order-export-import' ) );
		}

		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			return new WP_Error( 'date_range_reversed', __( 'Start date must not be after end date.', 'wc-order-export-import' ) );
		}

		// ── Query orders ───────────────────────────────────────────────────
		// The date range uses wc_get_orders() with the "start_date...end_date"
		// notation. End date gets +1 day so the full end day is included.
		$end_inclusive = date( 'Y-m-d', strtotime( $end_date . ' +1 day' ) );

		$orders = wc_get_orders( array(
			'date_created' => $start_date . '...' . $end_inclusive,
			'limit'        => -1,
			'return'       => 'objects',
			'orderby'      => 'date',
			'order'        => 'ASC',
			'type'         => 'shop_order',
		) );

		if ( empty( $orders ) ) {
			return new WP_Error(
				'no_orders',
				sprintf(
					/* translators: 1: start date, 2: end date */
					__( 'No orders found between %1$s and %2$s.', 'wc-order-export-import' ),
					esc_html( $start_date ),
					esc_html( $end_date )
				)
			);
		}

		// ── Stream CSV to browser ──────────────────────────────────────────
		// Disable any output buffering so the CSV is not mixed with HTML.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		$filename = 'wc-orders-' . sanitize_file_name( $start_date ) . '-to-' . sanitize_file_name( $end_date ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens the file correctly.
		fwrite( $output, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv( $output, self::CSV_HEADERS );

		// Data rows.
		foreach ( $orders as $order ) {
			fputcsv( $output, self::order_to_row( $order ) );
		}

		fclose( $output );
		exit;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a WC_Order object to a flat array matching CSV_HEADERS.
	 *
	 * @param  WC_Order $order
	 * @return array
	 */
	private static function order_to_row( WC_Order $order ) {
		return array(
			'order_number'       => $order->get_order_number(),
			'order_date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			'billing_first_name' => $order->get_billing_first_name(),
			'billing_last_name'  => $order->get_billing_last_name(),
			'billing_email'      => $order->get_billing_email(),
			'products'           => self::get_products_string( $order ),
			'total'              => $order->get_total(),
			'payment_status'     => $order->get_status(),
			'shipping_address_1' => $order->get_shipping_address_1(),
			'shipping_address_2' => $order->get_shipping_address_2(),
			'shipping_city'      => $order->get_shipping_city(),
			'shipping_state'     => $order->get_shipping_state(),
			'shipping_postcode'  => $order->get_shipping_postcode(),
			'shipping_country'   => $order->get_shipping_country(),
		);
	}

	/**
	 * Build the pipe-delimited products string for a single order.
	 *
	 * Format: "Product Name x Qty | Product 2 x Qty"
	 *
	 * @param  WC_Order $order
	 * @return string
	 */
	private static function get_products_string( WC_Order $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$parts[] = $item->get_name() . ' x ' . (int) $item->get_quantity();
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Check if a string is a valid YYYY-MM-DD date.
	 *
	 * @param  string $date
	 * @return bool
	 */
	private static function is_valid_date( $date ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		list( $y, $m, $d ) = explode( '-', $date );
		return checkdate( (int) $m, (int) $d, (int) $y );
	}
}
