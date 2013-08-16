<?php
/**
 * @package IssueM's Leaky Paywall
 * @since 1.0.0
 */

if ( !function_exists( 'issuem_do_leaky_paywall_shortcode_wp_enqueue_scripts' ) ) { 

	/**
	 * Helper function used for printing out debug information
	 *
	 * HT: Glenn Ansley @ iThemes.com
	 *
	 * @since 1.1.6
	 *
	 * @param int $args Arguments to pass to print_r
	 * @param bool $die TRUE to die else FALSE (default TRUE)
	 */
	function issuem_do_leaky_paywall_shortcode_wp_enqueue_scripts() {
	
		$settings = get_issuem_leaky_settings();
	
		switch( $settings['css_style'] ) {
			
			case 'none' :
				break;
			
			case 'default' :
			default : 
				wp_enqueue_style( 'issuem_leaky_paywall_style', IM_URL . '/css/issuem-leaky-paywall.css', '', ISSUEM_LP_VERSION );
				break;
				
		}
		
	}
	//add_action( 'wp_enqueue_scripts', array( $this, 'issuem_do_leaky_paywall_shortcode_wp_enqueue_scripts' ) );

}

if ( !function_exists( 'do_issuem_leaky_paywall_login' ) ) { 

	/**
	 * Shortcode for IssueM's Leaky Paywall
	 * Prints out the IssueM's Leaky Paywall
	 *
	 * @since 1.0.0
	 */
	function do_issuem_leaky_paywall_login( $atts ) {
		
		global $post;
		
		$settings = get_issuem_leaky_paywall_settings();
		$results = '';

		if ( isset( $_REQUEST['submit-leaky-login'] ) ) {
			
			if ( isset( $_REQUEST['email'] ) && is_email( $_REQUEST['email'] ) ) {
			
				if ( send_leaky_paywall_email( $_REQUEST['email'] ) )
					return '<h3>' . __( 'Email sent. Please check your email for the login link.', 'issuem-leaky-paywall' ) . '</h3>';
				else
					$results .= '<h1 class="error">' . __( 'Error sending login email, please try again later.', 'issuem-leaky-paywall' ) . '</h1>';
				
				
			} else {
			
				$results .= '<h1 class="error">' . __( 'Please supply a valid email address.', 'issuem-leaky-paywall' ) . '</h1>';
				
			}
			
		}
		
		$results .= '<h2>' . __( 'Email address:', 'issuem-leaky-paywall' ) . '</h2>';
		$results .= '<form action="" method="post">';
		$results .= '<input type="text" id="leaky-paywall-login-email" name="email" placeholder="valid@email.com" value="" />';
		$results .= '<input type="submit" id="leaky-paywall-submit-buttom" name="submit-leaky-login" value="' . __( 'Send Login Email', 'issuem-leaky-paywall' ) . '" />';
		$results .= '</form>';
		$results .= '<h3>' . __( 'Check your email for a link to log in.', 'issuem-leaky-paywall' ) . '</h3>';
	
		
		return $results;
		
	}
	add_shortcode( 'leaky_paywall_login', 'do_issuem_leaky_paywall_login' );
	
}

if ( !function_exists( 'do_issuem_leaky_paywall_subscription' ) ) { 

	/**
	 * Shortcode for IssueM's Leaky Paywall
	 * Prints out the IssueM's Leaky Paywall
	 *
	 * @since 1.0.0
	 */
	function do_issuem_leaky_paywall_subscription( $atts ) {
		
		global $post;
		
		$settings = get_issuem_leaky_paywall_settings();
		
		$defaults = array(
			'plan_id'			=> $settings['plan_id'],
			'price'				=> $settings['price'],
			'interval_count'	=> $settings['interval_count'],
			'interval'			=> $settings['interval'],
			'description'		=> $settings['charge_description'],
		);

	
		// Merge defaults with passed atts
		// Extract (make each array element its own PHP var
		$args = shortcode_atts( $defaults, $atts );
		extract( $args );
		
		$results = '';
		$stripe_price = number_format( $price, '2', '', '' ); //no decimals
		
		if ( !empty( $_SESSION['issuem_lp_hash'] ) && empty( $_SESSION['issuem_lp_email'] ) ) {
		
			$hash = $_SESSION['issuem_lp_hash'];
			
			if ( false !== $email = get_issuem_leaky_paywall_email_from_hash( $hash ) ) {
			
				$_SESSION['issuem_lp_email'] = $email;
				kill_issuem_leaky_paywall_login_hash( $hash );
				
			} else {
			
				$results .= '<h1 class="error">' . sprintf( __( 'Sorry, this link is invalid or has expired. <a href="%s">Try again?</a>', 'issuem-leaky-paywall' ), get_page_link( $settings['page_for_login'] ) ) . '</h1>';	
				
			}
			
		}
		
		if ( !empty( $_SESSION['issuem_lp_email'] ) ) {
						
			if ( false !== $expires = issuem_leaky_paywall_has_user_paid( $_SESSION['issuem_lp_email'] ) ) {
												
				switch( $expires ) {
				
					case 'subscription':
						$results .= sprintf( __( 'Your subscription will automatically renew until you <a href="%s">cancel</a>.', 'issuem-leaky-paywall' ), '?cancel' );
						break;
						
					case 'unlimited':
						$results .= __( 'You are a lifetime subscriber!', 'issuem-leaky-paywall' );
						break;
						
					default:
						$results .= sprintf( __( 'You are subscribed via credit card until %s.', 'issuem-leaky-paywall' ), date_i18n( get_option('date_format'), strtotime( $expires ) ) );
						
				}
				
				$results .= '<h3>' . __( 'Thank you very much for subscribing.', 'issuem-leaky-paywall' ) . '</h3>';
				$results .= '<h1><a href="?logout">' . __( 'Log Out', 'issuem-leaky-paywall' ) . '</a></h1>';
				
			} else {
				
				if ( !empty( $_POST['stripeToken'] ) ) {
					
					try {
	
						$token = $_POST['stripeToken'];
						
						if ( $existing_customer = get_issuem_leaky_paywall_subscriber_by_email( $_SESSION['issuem_lp_email'] ) )
							$cu = Stripe_Customer::retrieve( $existing_customer->stripe_id );
							
						if ( !empty( $cu ) )
							if ( true === $cu->deleted )
								$cu = array();
											
						$customer_array = array(
								'email' => $_SESSION['issuem_lp_email'],
								'card'  => $token,
						);
					
						if ( 'on' === $settings['recurring'] && !empty( $plan_id ) ) {
							
							$customer_array['plan'] = $plan_id;	
							
							if ( !empty( $cu ) )
								$cu->updateSubscription( array( 'plan' => $plan_id ) );
							else
								$cu = Stripe_Customer::create( $customer_array );
							
						} else {
						
							if ( !empty( $cu ) ) {
								
								$cu->card = $token;
								$cu->save();
								
							} else {
								
								$cu = Stripe_Customer::create( $customer_array );
								
							}
							
							$charge = Stripe_Charge::create(array(
								'customer' 		=> $cu->id,
								'amount'   		=> $stripe_price,
								'currency' 		=> apply_filters( 'issuem_leaky_paywall_stripe_currencye', 'usd' ), //currently Stripe only supports USD and CAD
								'description'	=> $description,
							));
						
						}
						
						$unique_hash = issuem_leaky_paywall_hash( $_SESSION['issuem_lp_email'] );
							
						if ( !empty( $existing_customer ) )
							issuem_leaky_paywall_update_subscriber( $unique_hash, $_SESSION['issuem_lp_email'], $cu, $args ); //if the email already exists, we want to update the subscriber, not create a new one
						else
							issuem_leaky_paywall_new_subscriber( $unique_hash, $_SESSION['issuem_lp_email'], $cu, $args );
							
						$_SESSION['issuem_lp_subscriber'] = $unique_hash;
						
						$results .= '<h1>' . __( 'Successfully subscribed!' , 'issuem-leaky-paywall' ) . '</h1>';
						
					} catch ( Exception $e ) {
						
						$results = '<h1>' . sprintf( __( 'Error processing request: %s', 'issuem-leaky-paywall' ), $e->getMessage() ) . '</h1>';
						
					}
					
					
				} else {
					
					if ( 'on' === $settings['recurring'] && !empty( $plan_id ) ) {
						
						try {
								
							$stripe_plan = Stripe_Plan::retrieve( $settings['plan_id'] );
												
							$results .= '<h2>' . sprintf( __( 'Susbcribe for just $%s %s', 'issuem-leaky-paywall' ), number_format( (float)$stripe_plan->amount/100, 2 ), issuem_leaky_paywall::human_readable_interval( $stripe_plan->interval_count, $stripe_plan->interval ) ) . '</h2>';
							
							if ( $stripe_plan->trial_period_days ) {
								$results .= '<h3>' . sprintf( __( 'Free for the first %s day(s)', 'issuem-leaky-paywall' ), $stripe_plan->trial_period_days ) . '</h3>';
							}
							
							$results .= '<form action="" method="post">
										  <script src="https://checkout.stripe.com/v2/checkout.js" class="stripe-button"
												  data-key="' . ISSUEM_LP_PUBLISHABLE_KEY . '"
												  data-plan="' . $settings['plan_id'] . '" 
												  data-description="' . $description . '">
										  </script>
										</form>';
							
							$results .= '<h3>' . __( '(You can cancel anytime with just two clicks.)', 'issuem-leaky-paywall' ) . '</h3>';
							
						} catch ( Exception $e ) {
	
							$results = '<h1>' . sprintf( __( 'Error processing request: %s', 'issuem-leaky-paywall' ), $e->getMessage() ) . '</h1>';

						}
						
					} else {
					
						$results .= '<h2>' . sprintf( __( 'Susbcribe for just $%s %s', 'issuem-leaky-paywall' ), $price, issuem_leaky_paywall::human_readable_interval( $interval_count, $interval ) ) . '</h2>';
							
						$results .= '<form action="" method="post">
									  <script src="https://checkout.stripe.com/v2/checkout.js" class="stripe-button"
											  data-key="' . ISSUEM_LP_PUBLISHABLE_KEY . '"
											  data-amount="' . $stripe_price . '" 
											  data-description="' . $description . '">
									  </script>
									</form>';
					
					}
				
				}
				
			}
			
		}
				
		return $results;
		
	}
	add_shortcode( 'leaky_paywall_subscription', 'do_issuem_leaky_paywall_subscription' );
	
}