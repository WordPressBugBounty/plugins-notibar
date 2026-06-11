<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'YayReviewMain' ) ) {
	class YayReviewMain {
		private $list_review_plugins = []; 

		protected static $instance = null;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
				self::$instance->do_hooks();
			}
			return self::$instance;
		}

		public function do_hooks() {

			add_action( 'wp_ajax_yay_save_review', array( $this, 'save_review' ) );

			//each global var for review
			global $yay_reviews_plugins;
			if (!isset($yay_reviews_plugins) || !is_array($yay_reviews_plugins)) {
				$yay_reviews_plugins = array();
			}

			if (empty($yay_reviews_plugins)) {
				return;
			}

			foreach ($yay_reviews_plugins as $plugin) {
				$option = get_option( $plugin['option_name'], false );
				if ( false === $option ) {
					self::update_time_display($plugin['option_name'], $plugin['display_time']);
					continue;
				}
				// Cast to string: update_option( $option, 0 ) stores an int, and a
				// persistent object cache can return it as int 0 on later loads.
				// A strict `'0' !== 0` would be true and wrongly re-show the notice,
				// so normalise to string before the "never show again" check.
				if ( ( isset( $_GET['display-reviews'] ) ) || ( time() >= intval( $option ) && '0' !== (string) $option ) ) {
					$this->list_review_plugins[] = $plugin;
				}
            }

			if ( ! empty( $this->list_review_plugins ) ) {
				add_action( 'admin_notices', array( $this, 'give_review' ) );
			}
		}

        public function check_nonce( $nonce ) {
            if ( ! wp_verify_nonce( $nonce, 'yay_review_nonce' ) ) {
                wp_send_json_error( array( 'status' => 'Wrong nonce validate!' ) );
                exit();
            }
        }

        public function has_field( $field, $request ) {
            return isset( $request[ $field ] ) ? sanitize_text_field( $request[ $field ] ) : null;
        }

	    public function save_review() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error(
                    array( 'mess' => 'You do not have permission to perform this action.' ),
                    403
                );
            }
		
            if ( count( $_REQUEST ) ) {
                $nonce  = $this->has_field( 'nonce', $_REQUEST );
                $field  = $this->has_field( 'field', $_REQUEST );
				$option = $this->has_field( 'option', $_REQUEST );
				
                $this->check_nonce( $nonce );

                if ( 'later' == $field ) {
                    update_option( $option, time() + 5 * 60 * 60 * 24 );  // Re display after 5 days
                } elseif ( 'alreadyDid' == $field || 'rateNow' == $field ) {
                    update_option( $option, 0 ); // Never show again
                }
                wp_send_json_success();
            }
            wp_send_json_error( array( 'message' => 'Update fail!' ) );
        }

        public static function update_time_display( $option_name, $display_time ) {
            $option = get_option( $option_name, false );
            // (string) cast for the same int-0-from-cache reason as do_hooks();
            // a "never" value must never be rescheduled back into display.
            if ( '0' !== (string) $option ) {
                update_option( $option_name, time() + $display_time * 60 * 60 * 24 ); // Re display after X days
            }
        }

        public function give_review() {
            if ( function_exists( 'get_current_screen' ) ) {
				foreach ( $this->list_review_plugins as $plugin ) {
					if ( get_current_screen()->id == 'plugins' || $plugin['display_pages']() ) {
						$selector = esc_attr( $plugin['slug'] ) . '-review';
						?>
						<div class="notice notice-success is-dismissible" id="<?php echo esc_attr( $selector ); ?>">
							<h3>Give <?php echo esc_html( $plugin['name'] ); ?> a review</h3>
							<p>Thank you for choosing <strong><?php echo esc_html( $plugin['name'] ); ?></strong>. We hope you love it. Could you take a couple of seconds posting a nice review to share your happy experience?</p>
							<p>We will be forever grateful. Thank you in advance.</p>
							<p>
								<a href="javascript:;" data="rateNow" class="button button-primary" style="margin-right: 5px">Rate now</a>
								<a href="javascript:;" data="later" class="button" style="margin-right: 5px">Later</a>
								<a href="javascript:;" data="alreadyDid" class="button">No, thanks</a>
							</p>
						</div>
						<script>
						jQuery(document).ready(function () {
							jQuery('body').on('click', '#<?php echo esc_attr( $selector ); ?> a,#<?php echo esc_attr( $selector ); ?> button.notice-dismiss', function() {
								var thisElement = this;
								var fieldValue = jQuery(thisElement).attr("data");
								var link = "<?php echo esc_url( $plugin['review_link'] ); ?>";
								var hidePopup = false;
								if (fieldValue == "rateNow") {
									window.open(link, "_blank");
								} else {
									hidePopup = true;
								}

								if (jQuery(thisElement).hasClass('notice-dismiss')) {
									fieldValue = 'later'
								}

								jQuery.ajax({
									dataType: 'json',
									url: window.ajaxurl,
									type: "post",
									data: {
										action: 'yay_save_review',
										field: fieldValue,
										option: '<?php echo esc_attr( $plugin['option_name']) ?>',
										nonce: '<?php echo esc_attr( wp_create_nonce( 'yay_review_nonce' ) ); ?>',
									},
									}).done(function (result) {
									if (result.success) {
										if (hidePopup == true) {
											jQuery('#<?php echo esc_attr( $selector ); ?>').hide("slow");
										}
									} else {
										console.log("Error", result.message);
										if (hidePopup == true) {
											jQuery('#<?php echo esc_attr( $selector ); ?>').hide("slow");
										}
									}
									}).fail(function (res) {
									console.log(res.responseText);

									if (hidePopup == true) {
										jQuery('#<?php echo esc_attr( $selector ); ?>').hide("slow");
									}
								});
							})
						});
						</script>
						<?php
					}
				}
			}
        }
	}
}

