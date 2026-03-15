<?php
/**
 * Admin UI for WC Order Export/Import.
 *
 * Registers the submenu page under WooCommerce, handles form submissions for
 * export and import, and displays result notices.
 *
 * @package WC_Order_Export_Import
 */

defined( 'ABSPATH' ) || exit;

class WCEI_Admin {

	/**
	 * Capability required to access this plugin.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Transient key used to pass result messages across the redirect.
	 */
	const NOTICE_TRANSIENT = 'wcei_admin_notice';

	public function __construct() {
		add_action( 'admin_menu',             array( $this, 'register_menu' ) );
		add_action( 'admin_init',             array( $this, 'register_settings' ) );
		add_action( 'admin_post_wcei_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_wcei_import', array( $this, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
	}

	// -------------------------------------------------------------------------
	// Menu & Settings registration
	// -------------------------------------------------------------------------

	/**
	 * Add a submenu page under the WooCommerce menu.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Export/Import', 'wc-order-export-import' ),
			__( 'Order Export/Import', 'wc-order-export-import' ),
			self::CAPABILITY,
			'wcei-order-export-import',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'wcei_settings',
			'wcei_duplicate_handling',
			array(
				'type'              => 'string',
				'default'           => 'skip',
				'sanitize_callback' => array( $this, 'sanitize_duplicate_handling' ),
			)
		);
	}

	/**
	 * Sanitize the duplicate handling setting — only allow 'skip' or 'update'.
	 *
	 * @param  string $value Raw value from the form.
	 * @return string        'skip' or 'update'.
	 */
	public function sanitize_duplicate_handling( $value ) {
		return in_array( $value, array( 'skip', 'update' ), true ) ? $value : 'skip';
	}

	// -------------------------------------------------------------------------
	// Asset enqueue
	// -------------------------------------------------------------------------

	/**
	 * Enqueue scripts and styles only on the plugin's admin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wcei-order-export-import' !== $hook ) {
			return;
		}

		// jQuery UI Datepicker (bundled with WordPress core).
		wp_enqueue_script( 'jquery-ui-datepicker' );

		// jQuery UI base theme — use the bundled WP version.
		wp_enqueue_style(
			'jquery-ui-style',
			includes_url( 'css/jquery-ui/themes/smoothness/jquery-ui.css' ),
			array(),
			WCEI_VERSION
		);

		// Plugin admin styles.
		wp_enqueue_style(
			'wcei-admin',
			WCEI_PLUGIN_URL . 'assets/admin.css',
			array(),
			WCEI_VERSION
		);

		// Inline JS to initialise datepickers.
		wp_add_inline_script(
			'jquery-ui-datepicker',
			'jQuery(function($){ $(".wcei-datepicker").datepicker({ dateFormat: "yy-mm-dd" }); });'
		);
	}

	// -------------------------------------------------------------------------
	// Admin page render
	// -------------------------------------------------------------------------

	/**
	 * Render the full admin page with Export, Import, and Settings tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-order-export-import' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification

		$this->show_admin_notices();
		?>
		<div class="wrap wcei-wrap">
			<h1><?php esc_html_e( 'WooCommerce Order Export/Import', 'wc-order-export-import' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php
				$tabs = array(
					'export'   => __( 'Export', 'wc-order-export-import' ),
					'import'   => __( 'Import', 'wc-order-export-import' ),
					'settings' => __( 'Settings', 'wc-order-export-import' ),
				);
				foreach ( $tabs as $slug => $label ) {
					printf(
						'<a href="%s" class="nav-tab %s">%s</a>',
						esc_url( admin_url( 'admin.php?page=wcei-order-export-import&tab=' . $slug ) ),
						$active_tab === $slug ? 'nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<div class="wcei-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'import':
						$this->render_import_tab();
						break;
					case 'settings':
						$this->render_settings_tab();
						break;
					default:
						$this->render_export_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Export tab.
	 */
	private function render_export_tab() {
		$today     = date( 'Y-m-d' );
		$month_ago = date( 'Y-m-d', strtotime( '-30 days' ) );
		?>
		<div class="wcei-section">
			<h2><?php esc_html_e( 'Export Orders to CSV', 'wc-order-export-import' ); ?></h2>
			<p><?php esc_html_e( 'Select a date range and download all orders as a CSV file.', 'wc-order-export-import' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wcei_export">
				<?php wp_nonce_field( 'wcei_export_action', 'wcei_nonce' ); ?>

				<table class="form-table wcei-form-table">
					<tr>
						<th scope="row">
							<label for="wcei_start_date"><?php esc_html_e( 'Date From', 'wc-order-export-import' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="wcei_start_date"
								name="wcei_start_date"
								class="wcei-datepicker"
								value="<?php echo esc_attr( $month_ago ); ?>"
								placeholder="YYYY-MM-DD"
								autocomplete="off"
							>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wcei_end_date"><?php esc_html_e( 'Date To', 'wc-order-export-import' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="wcei_end_date"
								name="wcei_end_date"
								class="wcei-datepicker"
								value="<?php echo esc_attr( $today ); ?>"
								placeholder="YYYY-MM-DD"
								autocomplete="off"
							>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary wcei-btn-export">
						<?php esc_html_e( 'Export CSV', 'wc-order-export-import' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Import tab.
	 */
	private function render_import_tab() {
		$duplicate = get_option( 'wcei_duplicate_handling', 'skip' );
		?>
		<div class="wcei-section">
			<h2><?php esc_html_e( 'Import Orders from CSV', 'wc-order-export-import' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: duplicate handling setting */
					esc_html__( 'Upload a CSV file using the same format as the export. Duplicate orders will be %s (configurable in Settings).', 'wc-order-export-import' ),
					'<strong>' . esc_html( $duplicate === 'update' ? __( 'updated', 'wc-order-export-import' ) : __( 'skipped', 'wc-order-export-import' ) ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wcei_import">
				<?php wp_nonce_field( 'wcei_import_action', 'wcei_nonce' ); ?>

				<table class="form-table wcei-form-table">
					<tr>
						<th scope="row">
							<label for="wcei_csv"><?php esc_html_e( 'CSV File', 'wc-order-export-import' ); ?></label>
						</th>
						<td>
							<input
								type="file"
								id="wcei_csv"
								name="wcei_csv"
								accept=".csv,text/csv,text/plain"
								required
							>
							<p class="description">
								<?php esc_html_e( 'Accepted format: .csv  — must use the same column headers as the export.', 'wc-order-export-import' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary wcei-btn-import">
						<?php esc_html_e( 'Import CSV', 'wc-order-export-import' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 */
	private function render_settings_tab() {
		$duplicate = get_option( 'wcei_duplicate_handling', 'skip' );
		?>
		<div class="wcei-section">
			<h2><?php esc_html_e( 'Settings', 'wc-order-export-import' ); ?></h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'wcei_settings' ); ?>

				<table class="form-table wcei-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'On duplicate order', 'wc-order-export-import' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="wcei_duplicate_handling" value="skip" <?php checked( $duplicate, 'skip' ); ?>>
									<?php esc_html_e( 'Skip — leave the existing order unchanged', 'wc-order-export-import' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="wcei_duplicate_handling" value="update" <?php checked( $duplicate, 'update' ); ?>>
									<?php esc_html_e( 'Update — overwrite the existing order with CSV data', 'wc-order-export-import' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'wc-order-export-import' ) ); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle the export form submission.
	 * Streams the CSV file to the browser on success, or redirects with an error.
	 */
	public function handle_export() {
		// Security: capability + nonce.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wc-order-export-import' ) );
		}
		check_admin_referer( 'wcei_export_action', 'wcei_nonce' );

		$start_date = isset( $_POST['wcei_start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['wcei_start_date'] ) ) : '';
		$end_date   = isset( $_POST['wcei_end_date'] )   ? sanitize_text_field( wp_unslash( $_POST['wcei_end_date'] ) )   : '';

		$result = WCEI_Exporter::export( $start_date, $end_date );

		// export() only returns on error (otherwise it exits after streaming).
		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wcei-order-export-import&tab=export' ) );
		exit;
	}

	/**
	 * Handle the import form submission.
	 * Processes the uploaded CSV and redirects with a summary notice.
	 */
	public function handle_import() {
		// Security: capability + nonce.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'wc-order-export-import' ) );
		}
		check_admin_referer( 'wcei_import_action', 'wcei_nonce' );

		// Validate file upload presence.
		if ( empty( $_FILES['wcei_csv'] ) || $_FILES['wcei_csv']['error'] !== UPLOAD_ERR_OK ) {
			$upload_error = isset( $_FILES['wcei_csv']['error'] ) ? (int) $_FILES['wcei_csv']['error'] : UPLOAD_ERR_NO_FILE;
			$this->set_notice( 'error', $this->upload_error_message( $upload_error ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wcei-order-export-import&tab=import' ) );
			exit;
		}

		// Validate file extension and mime type.
		$file_info = wp_check_filetype(
			basename( sanitize_file_name( $_FILES['wcei_csv']['name'] ) ),
			array(
				'csv' => 'text/csv',
			)
		);

		// Also allow text/plain (some OSes report CSV as text/plain).
		$allowed_types = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		$detected_type = isset( $_FILES['wcei_csv']['type'] ) ? sanitize_mime_type( wp_unslash( $_FILES['wcei_csv']['type'] ) ) : '';

		if ( empty( $file_info['ext'] ) && ! in_array( $detected_type, $allowed_types, true ) ) {
			$this->set_notice( 'error', __( 'Invalid file type. Please upload a .csv file.', 'wc-order-export-import' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wcei-order-export-import&tab=import' ) );
			exit;
		}

		// Move the uploaded file to the uploads directory.
		add_filter( 'upload_mimes', array( $this, 'allow_csv_mime' ) );
		$uploaded = wp_handle_upload( $_FILES['wcei_csv'], array( 'test_form' => false ) );
		remove_filter( 'upload_mimes', array( $this, 'allow_csv_mime' ) );

		if ( isset( $uploaded['error'] ) ) {
			$this->set_notice( 'error', $uploaded['error'] );
			wp_safe_redirect( admin_url( 'admin.php?page=wcei-order-export-import&tab=import' ) );
			exit;
		}

		$file_path         = $uploaded['file'];
		$duplicate_handling = get_option( 'wcei_duplicate_handling', 'skip' );

		$result = WCEI_Importer::import( $file_path, $duplicate_handling );

		// Clean up the temporary uploaded file.
		@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} else {
			$message = sprintf(
				/* translators: 1: imported count, 2: updated count, 3: skipped count */
				__( 'Import complete. Imported: %1$d | Updated: %2$d | Skipped: %3$d.', 'wc-order-export-import' ),
				(int) $result['imported'],
				(int) $result['updated'],
				(int) $result['skipped']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= '<br>' . __( 'Row errors:', 'wc-order-export-import' ) . '<ul>';
				foreach ( $result['errors'] as $err ) {
					$message .= '<li>' . esc_html( $err ) . '</li>';
				}
				$message .= '</ul>';
				$this->set_notice( 'warning', $message );
			} else {
				$this->set_notice( 'success', $message );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wcei-order-export-import&tab=import' ) );
		exit;
	}

	/**
	 * Allow CSV mime type for wp_handle_upload().
	 *
	 * @param  array $mimes Existing mime types.
	 * @return array
	 */
	public function allow_csv_mime( $mimes ) {
		$mimes['csv'] = 'text/csv';
		return $mimes;
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	/**
	 * Store an admin notice in a transient to survive the redirect.
	 *
	 * @param string $type    'success', 'error', or 'warning'.
	 * @param string $message HTML message.
	 */
	private function set_notice( $type, $message ) {
		set_transient( self::NOTICE_TRANSIENT, array( 'type' => $type, 'message' => $message ), 60 );
	}

	/**
	 * Display and clear any pending admin notice.
	 */
	public function show_admin_notices() {
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( ! $notice ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );

		$class = 'notice notice-' . sanitize_html_class( $notice['type'] );
		// $notice['message'] may contain intentional HTML (lists for errors).
		printf( '<div class="%s is-dismissible"><p>%s</p></div>', esc_attr( $class ), wp_kses_post( $notice['message'] ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Human-readable message for PHP file upload error codes.
	 *
	 * @param  int    $error_code PHP UPLOAD_ERR_* constant.
	 * @return string
	 */
	private function upload_error_message( $error_code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The file exceeds the server upload size limit.', 'wc-order-export-import' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The file exceeds the form upload size limit.', 'wc-order-export-import' ),
			UPLOAD_ERR_PARTIAL    => __( 'The file was only partially uploaded.', 'wc-order-export-import' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded. Please choose a CSV file.', 'wc-order-export-import' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Missing temporary folder on the server.', 'wc-order-export-import' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'wc-order-export-import' ),
			UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'wc-order-export-import' ),
		);
		return isset( $messages[ $error_code ] )
			? $messages[ $error_code ]
			: __( 'An unknown upload error occurred.', 'wc-order-export-import' );
	}
}
