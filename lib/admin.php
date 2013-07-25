<?php

/**
 *
 */

if ( !class_exists( 'ES_Admin' ) ) :

class ES_Admin {

	private static $instance;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( "Please don't __clone ES_Admin" ); }

	public function __wakeup() { wp_die( "Please don't __wakeup ES_Admin" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new ES_Admin;
			self::$instance->setup();
		}
		return self::$instance;
	}

	public function setup() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_print_styles-tools_page_elasticsearch_sync', array( $this, 'admin_styles' ) );
		add_action( 'admin_post_es_full_sync', array( $this, 'full_sync' ) );
		add_action( 'admin_post_es_cancel_sync', array( $this, 'cancel_sync' ) );
		add_action( 'admin_post_es_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_es_sync_status', array( $this, 'es_sync_status' ) );
	}


	public function admin_menu() {
		// Add new admin menu and save returned page hook
		$hook_suffix = add_management_page( __('Elasticsearch Sync'), __('Elasticsearch'), 'manage_options', 'elasticsearch_sync', array( $this, 'sync' ) );
	}


	public function sync() {
		if ( !current_user_can( 'manage_options' ) ) wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		$sync = ES_Sync_Meta();
		?>
		<div class="wrap">
			<h2>Elasticsearch</h2>

				<h3>Settings</h3>
				<form method="post" action="<?php echo admin_url( 'admin-post.php' ) ?>">
					<input type="hidden" name="action" value="es_settings" />
					<?php wp_nonce_field( 'es_settings', 'es_settings_nonce' ); ?>
					<p>
						<input type="text" name="es_host" value="<?php echo esc_url( ES_Config()->get_setting( 'host' ) ) ?>" style="width:100%;max-width:500px" />
					</p>
					<?php submit_button( 'Save Settings', 'primary' ) ?>
				</form>

				<hr />

			<?php if ( $sync->running ) : ?>

				<h3>Sync in progress</h3>
				<div class="progress">
					<div class="progress-text"><span id="sync-processed"><?php echo number_format( intval( $sync->processed ) ) ?></span> / <span id="sync-total"><?php echo number_format( intval( $sync->total ) ) ?></span></div>
					<div class="progress-bar" data-processed="<?php echo intval( $sync->processed ) ?>" data-total="<?php echo intval( $sync->total ) ?>" style="width:<?php echo intval( round( 100 * $sync->processed / $sync->total ) ) ?>%;"></div>
				</div>
				<script type="text/javascript">
					var progress_total, progress_processed;
					jQuery( function( $ ) {
						progress_total = $( '.progress-bar' ).data( 'total' ) - 0;;
						progress_processed = $( '.progress-bar' ).data( 'processed' ) - 0;
						setInterval( function() {
							$.get( ajaxurl, { action : 'es_sync_status' }, function( data ) {
								if ( data.processed ) {
									if ( data.processed > progress_processed ) {
										progress_processed = data.processed;
										$( '#sync-processed' ).text( data.processed );
										var new_width = Math.round( data.processed / progress_total * 100 );
										$( '.progress-bar' ).animate( { width: new_width + '%' }, 1000 );
									}
								}
							}, 'json' );
						}, 5000);
					} );
				</script>
				<form method="post" action="<?php echo admin_url( 'admin-post.php' ) ?>">
					<input type="hidden" name="action" value="es_cancel_sync" />
					<?php wp_nonce_field( 'es_sync', 'es_sync_nonce' ); ?>
					<?php submit_button( 'Cancel Sync', 'delete' ) ?>
				</form>

			<?php else : ?>

				<h3>Full Sync</h3>
				<p>Running a full sync will wipe the current index if there is one and rebuild it from scratch.</p>
				<p>
					Your site has <?php echo number_format( intval( ES_Sync_Manager()->count_posts() ) ) ?> posts to index.
					<?php if ( ES_Sync_Manager()->count_posts() > 25000 ) : ?>
						As a result of there being so many posts, this may take a long time to index.
					<?php endif ?>
					Exactly how long this will take will vary on a number of factors, like your server's CPU and memory,
					connection speed, current traffic, average post length, and associated terms and post meta.
				</p>
				<p>Your site will not use elasticsearch until the indexing is complete.</p>

				<form method="post" action="<?php echo admin_url( 'admin-post.php' ) ?>">
					<input type="hidden" name="action" value="es_full_sync" />
					<?php wp_nonce_field( 'es_sync', 'es_sync_nonce' ); ?>
					<?php submit_button( 'Run Full Sync', 'delete' ) ?>
				</form>

			<?php endif ?>
		</div>
		<?php
	}

	public function save_settings() {
		if ( !isset( $_POST['es_settings_nonce'] ) || ! wp_verify_nonce( $_POST['es_settings_nonce'], 'es_settings' ) ) {
			wp_die( 'You are not authorized to perform that action' );
		} else {
			$updates = array();

			if ( isset( $_POST['es_host'] ) )
				$updates['host'] = esc_url( $_POST['es_host'] );

			ES_Config()->update_settings( $updates );

			wp_redirect( admin_url( 'tools.php?page=elasticsearch_sync&save=1' ) );
			exit;
		}
	}

	public function full_sync() {
		if ( !isset( $_POST['es_sync_nonce'] ) || ! wp_verify_nonce( $_POST['es_sync_nonce'], 'es_sync' ) ) {
			wp_die( 'You are not authorized to perform that action' );
		} else {
			ES_Sync_Manager()->do_cron_reindex();
			wp_redirect( admin_url( 'tools.php?page=elasticsearch_sync' ) );
			exit;
		}
	}

	public function cancel_sync() {
		if ( !isset( $_POST['es_sync_nonce'] ) || ! wp_verify_nonce( $_POST['es_sync_nonce'], 'es_sync' ) ) {
			wp_die( 'You are not authorized to perform that action' );
		} else {
			ES_Sync_Manager()->cancel_reindex();
			wp_redirect( admin_url( 'tools.php?page=elasticsearch_sync&cancel=1' ) );
			exit;
		}
	}

	public function es_sync_status() {
		echo json_encode( array(
			'processed' => ES_Sync_Meta()->processed,
			'page' => ES_Sync_Meta()->page
		) );
		exit;
	}

	public function admin_styles() {
		?>
		<style type="text/css">
			div.progress {
				position: relative;
				height: 50px;
				border: 2px solid #111;
				background: #333;
				margin: 9px 0 18px;
			}
			div.progress-bar {
				background: #0074a2;
				position: absolute;
				left: 0;
				top: 0;
				height: 50px;
				z-index: 1;
			}
			div.progress-text {
				color: white;
				text-shadow: 1px 1px 0 #333;
				line-height: 50px;
				text-align: center;
				position: absolute;
				width: 100%;
				z-index: 2;
			}
		</style>
		<?php
	}
}

function ES_Admin() {
	return ES_Admin::instance();
}
add_action( 'after_setup_theme', 'ES_Admin' );

endif;