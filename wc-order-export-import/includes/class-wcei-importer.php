<?php
/**
 * CSV Importer for WooCommerce orders.
 *
 * Parses a CSV file (using the same schema as the exporter) and creates or
 * updates WooCommerce orders accordingly.
 *
 * @package WC_Order_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class WCEI_Importer {

	/**
	 * Expected CSV column headers (lower-case, trimmed).
	 * Must stay in sync with WCEI_Exporter::CSV_HEADERS.
	 */
	const EXPECTED_HEADERS = array(
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
	 * Fields that must be non-empty for a row to be processed.
	 */
	const REQUIRED_FIELDS = array( 'order_number', 'billing_email', 'products' );

	/**
	 * Import orders from a CSV file.
	 *
	 * @param  string $file_path          Absolute path to the CSV file.
	 * @param  string $duplicate_handling 'skip' or 'update'.
	 * @return array|WP_Error             Summary array or WP_Error on critical failure.
	 */
	public static function import( $file_path, $duplicate_handling ) {
		// ── Open file ──────────────────────────────────────────────────────
		$handle = @fopen( $file_path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $handle ) {
			return new WP_Error( 'file_open_failed', __( 'Could not open the uploaded CSV file.', 'wc-order-export-import' ) );
		}

		// ── Validate headers ───────────────────────────────────────────────
		$raw_headers = fgetcsv( $handle );
		if ( false === $raw_headers ) {
			fclose( $handle );
			return new WP_Error( 'empty_file', __( 'The CSV file appears to be empty.', 'wc-order-export-import' ) );
		}

		$headers = array_map( 'strtolower', array_map( 'trim', $raw_headers ) );

		if ( $headers !== self::EXPECTED_HEADERS ) {
			fclose( $handle );
			return new WP_Error(
				'invalid_headers',
				sprintf(
					/* translators: %s: expected headers list */
					__( 'CSV headers do not match the expected format. Expected columns: %s', 'wc-order-export-import' ),
					implode( ', ', self::EXPECTED_HEADERS )
				)
			);
		}

		// ── Process rows ───────────────────────────────────────────────────
		$results = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		$row_number = 1; // Header is row 1; data starts at row 2.

		while ( ( $raw_row = fgetcsv( $handle ) ) !== false ) {
			$row_number++;

			// Skip completely blank rows.
			if ( empty( array_filter( $raw_row ) ) ) {
				continue;
			}

			// Map columns to header keys.
			if ( count( $raw_row ) !== count( $headers ) ) {
				$results['errors'][] = sprintf(
					/* translators: 1: row number, 2: expected column count, 3: actual column count */
					__( 'Row %1$d: column count mismatch (expected %2$d, got %3$d).', 'wc-order-export-import' ),
					$row_number,
					count( $headers ),
					count( $raw_row )
				);
				continue;
			}

			$row = array_combine( $headers, $raw_row );

			// Sanitize all values.
			$row = self::sanitize_row( $row );

			// Validate required fields.
			$missing = array();
			foreach ( self::REQUIRED_FIELDS as $field ) {
				if ( empty( $row[ $field ] ) ) {
					$missing[] = $field;
				}
			}
			if ( ! empty( $missing ) ) {
				$results['errors'][] = sprintf(
					/* translators: 1: row number, 2: missing field names */
					__( 'Row %1$d: missing required field(s): %2$s — row skipped.', 'wc-order-export-import' ),
					$row_number,
					implode( ', ', $missing )
				);
				continue;
			}

			// Process the row, catching any unexpected exceptions.
			try {
				$row_result = self::process_row( $row, $duplicate_handling );
			} catch ( Exception $e ) {
				$results['errors'][] = sprintf(
					/* translators: 1: row number, 2: exception message */
					__( 'Row %1$d: unexpected error — %2$s', 'wc-order-export-import' ),
					$row_number,
					$e->getMessage()
				);
				continue;
			}

			if ( is_wp_error( $row_result ) ) {
				$results['errors'][] = sprintf( 'Row %d: %s', $row_number, $row_result->get_error_message() );
				continue;
			}

			// $row_result is one of 'imported', 'updated', 'skipped'.
			if ( isset( $results[ $row_result ] ) ) {
				$results[ $row_result ]++;
			}
		}

		fclose( $handle );

		return $results;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Process a single CSV data row.
	 *
	 * @param  array  $row                Associative array keyed by header name.
	 * @param  string $duplicate_handling 'skip' or 'update'.
	 * @return string|WP_Error            'imported', 'updated', or 'skipped' on success; WP_Error on failure.
	 */
	private static function process_row( array $row, $duplicate_handling ) {
		$order_number = (int) $row['order_number'];

		// ── Check for existing order ───────────────────────────────────────
		$existing_order = self::find_order( $order_number );

		if ( $existing_order ) {
			if ( 'skip' === $duplicate_handling ) {
				return 'skipped';
			}
			// Update existing order.
			$order = $existing_order;
			// Remove existing line items so we can re-add from the CSV.
			foreach ( $order->get_items() as $item_id => $item ) {
				$order->remove_item( $item_id );
			}
			self::populate_order( $order, $row );
			$order->save();
			return 'updated';
		}

		// ── Create new order ───────────────────────────────────────────────
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return new WP_Error(
				'order_create_failed',
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Could not create order: %s', 'wc-order-export-import' ),
					$order->get_error_message()
				)
			);
		}

		self::populate_order( $order, $row );
		$order->save();

		return 'imported';
	}

	/**
	 * Populate (or repopulate) a WC_Order with data from a CSV row.
	 *
	 * @param WC_Order $order The order to populate.
	 * @param array    $row   Sanitized row data.
	 */
	private static function populate_order( WC_Order $order, array $row ) {
		// ── Dates ──────────────────────────────────────────────────────────
		if ( ! empty( $row['order_date'] ) ) {
			$timestamp = strtotime( $row['order_date'] );
			if ( false !== $timestamp ) {
				$order->set_date_created( $timestamp );
			}
		}

		// ── Billing address ────────────────────────────────────────────────
		$order->set_billing_first_name( $row['billing_first_name'] );
		$order->set_billing_last_name( $row['billing_last_name'] );
		$order->set_billing_email( $row['billing_email'] );

		// ── Shipping address ───────────────────────────────────────────────
		$order->set_shipping_address_1( $row['shipping_address_1'] );
		$order->set_shipping_address_2( $row['shipping_address_2'] );
		$order->set_shipping_city( $row['shipping_city'] );
		$order->set_shipping_state( $row['shipping_state'] );
		$order->set_shipping_postcode( $row['shipping_postcode'] );
		$order->set_shipping_country( $row['shipping_country'] );

		// ── Products ───────────────────────────────────────────────────────
		if ( ! empty( $row['products'] ) ) {
			self::add_products_to_order( $order, $row['products'] );
		}

		// ── Totals & status ────────────────────────────────────────────────
		$order->calculate_totals();

		// Override the calculated total with the CSV value if present.
		if ( '' !== $row['total'] && is_numeric( $row['total'] ) ) {
			$order->set_total( (float) $row['total'] );
		}

		// Sanitize and set order status; WooCommerce strips the 'wc-' prefix internally.
		if ( ! empty( $row['payment_status'] ) ) {
			$status = str_replace( 'wc-', '', $row['payment_status'] );
			// Only allow known WC statuses.
			$valid_statuses = array_keys( wc_get_order_statuses() );
			$prefixed       = 'wc-' . $status;
			if ( in_array( $prefixed, $valid_statuses, true ) ) {
				$order->set_status( $status );
			}
		}
	}

	/**
	 * Parse the pipe-separated products string and add items to the order.
	 *
	 * Format: "Product Name x Qty | Product 2 x Qty"
	 *
	 * If a matching WooCommerce product is found by name it is linked; otherwise
	 * a manual line item is created so the order data is preserved regardless.
	 *
	 * @param WC_Order $order
	 * @param string   $products_str
	 */
	private static function add_products_to_order( WC_Order $order, $products_str ) {
		$parts = explode( ' | ', $products_str );

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			// Split on last " x " to separate name from quantity.
			$last_x = strrpos( $part, ' x ' );
			if ( false === $last_x ) {
				// No quantity marker — treat as qty 1.
				$product_name = $part;
				$qty          = 1;
			} else {
				$product_name = substr( $part, 0, $last_x );
				$qty          = (int) substr( $part, $last_x + 3 );
				$qty          = max( 1, $qty );
			}

			$product_name = trim( $product_name );

			// Try to find a matching WC product by exact name.
			$products = wc_get_products( array(
				'name'   => $product_name,
				'limit'  => 1,
				'status' => 'any',
			) );

			if ( ! empty( $products ) ) {
				$order->add_product( $products[0], $qty );
			} else {
				// Product not found — add a manual line item to preserve data.
				$item = new WC_Order_Item_Product();
				$item->set_name( $product_name );
				$item->set_quantity( $qty );
				$item->set_subtotal( 0 );
				$item->set_total( 0 );
				$order->add_item( $item );
			}
		}
	}

	/**
	 * Find an existing WooCommerce order by order number.
	 *
	 * Supports both HPOS and legacy post-based storage.
	 *
	 * @param  int          $order_number The order number (usually matches the order ID).
	 * @return WC_Order|false
	 */
	private static function find_order( $order_number ) {
		if ( $order_number <= 0 ) {
			return false;
		}

		// Direct lookup by ID (works for most stores where order_number = order_id).
		$order = wc_get_order( $order_number );
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		// Fallback: search by _order_number meta (used by some plugins like
		// WooCommerce Sequential Order Numbers).
		$orders = wc_get_orders( array(
			'meta_key'   => '_order_number',
			'meta_value' => (string) $order_number,
			'limit'      => 1,
			'return'     => 'objects',
		) );

		if ( ! empty( $orders ) ) {
			return reset( $orders );
		}

		return false;
	}

	/**
	 * Sanitize all values in a CSV row.
	 *
	 * @param  array $row Raw row data.
	 * @return array      Sanitized row data.
	 */
	private static function sanitize_row( array $row ) {
		$sanitized = array();
		foreach ( $row as $key => $value ) {
			if ( 'billing_email' === $key ) {
				$sanitized[ $key ] = sanitize_email( $value );
			} elseif ( 'total' === $key ) {
				// Preserve numeric value; strip anything non-numeric except dot/comma.
				$sanitized[ $key ] = sanitize_text_field( $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}
		return $sanitized;
	}
}
