<?php
/**
 * Plugin Name: Paid Memberships Pro - Membership Maps
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-membership-maps/
 * Description: Adds a map to your member directory and profile page.
 * Version: .1
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-membership-maps
 * Domain Path: /languages
 */

function pmpro_memmaps_shortcode( $atts ){	

	extract(shortcode_atts(array(
		'height' 		=> '400', //Uses px
		'width'			=> '100', //Uses %
		'zoom'			=> '8',
		'notice'		=> apply_filters( 'pmpro_memmaps_default_map_notice', __( "This map could not be loaded. Please ensure that you've entered in your Google Maps API Key, and that there are no JavaScript errors on the page.", "pmpro-membership-maps" ) ),
		'ID'			=> '1',
		'infowindow_width' 	=> '300', //We'll always use px for this
		'levels'		=> false,
		//Using same fields as member directory
		'link' 			=> true,
		'avatar_size' 	=> '64',
		'show_avatar'	=> true,
		'show_email' 	=> true,
		'show_level' 	=> true,
		'show_startdate' => true,
		'avatar_align' 	=> NULL,
		'fields' 		=> NULL
	), $atts));

	$marker_attributes = apply_filters( 'pmpro_memmaps_marker_attributes', array(
		'link' 				=> $link,
		'avatar_size'		=> $avatar_size,
		'show_avatar'		=> $show_avatar,
		'show_email'		=> $show_email,
		'show_level'		=> $show_level,
		'show_startdate'	=> $show_startdate,
		'avatar_align'		=> $avatar_align,
		'fields'			=> $fields
	) );
	//Get the marker data
	$marker_data = pmpro_memmaps_load_marker_data( $levels, $marker_attributes);	

	$api_key = pmpro_getOption( 'pmpro_memmaps_api_key' );
	
	$libraries = apply_filters( 'pmpro_memmaps_google_maps_libraries', array() );

	wp_enqueue_script( 'jquery' );
	
	wp_enqueue_script( 'pmpro-membership-maps-google-maps', 'https://maps.googleapis.com/maps/api/js?key='.$api_key.'&libraries='.implode( ",", $libraries ) );

	wp_register_script( 'pmpro-membership-maps-javascript', plugins_url( 'js/user.js', __FILE__ ) );	

	wp_enqueue_style( 'pmpro-membership-maps-styling', plugins_url( 'css/user.css', __FILE__ ) );

	/**
	 * Setup defaults for the map. We're passing through the ID attribute
	 * to allow developers to differentiate maps. 
	 */	
	wp_localize_script( 'pmpro-membership-maps-javascript', 'default_start', apply_filters( 'pmpro_memmaps_default_map_start', array( 'lat' => -34.397, 'lng' => 150.644 ), $ID ) );
	wp_localize_script( 'pmpro-membership-maps-javascript', 'override_first_marker_location', apply_filters( 'pmpro_memmaps_override_first_marker', '__return_false', $ID ) );
	wp_localize_script( 'pmpro-membership-maps-javascript', 'infowindow_width', $infowindow_width );

	wp_localize_script( 'pmpro-membership-maps-javascript', 'marker_data', $marker_data );
	wp_localize_script( 'pmpro-membership-maps-javascript', 'zoom_level', $zoom );

	wp_enqueue_script( 'pmpro-membership-maps-javascript' );		

	return "<div id='pmpro_memmaps_map' class='pmpro_memmaps_map pmpro_map_id_".$ID."' style='height: ".$height."px; width: ".$width."%;'>".$notice."</div>";	

}
add_shortcode( 'membership_maps', 'pmpro_memmaps_shortcode' );

function pmpro_memmaps_load_marker_data( $levels = false, $marker_attributes = array(), $start = 0, $limit = 100, $s = "", $pn = false, $order_by = false, $order = false, $end = false ){
	/**
	 * We're adding in support for $pn, $order_by, $order and $end to allow the pmpro_membership_maps_sql_parts
	 * to be used in the same function as one would filter the Member Directory filter pmpro_member_directory_sql
	 * Some of these variables are ignored in the query
	 */
	
	global $wpdb;

	$sql_parts = array();

	$sql_parts['SELECT'] = "SELECT SQL_CALC_FOUND_ROWS u.ID, u.user_login, u.user_email, u.user_nicename, u.display_name, UNIX_TIMESTAMP(u.user_registered) as joindate, mu.membership_id, mu.initial_payment, mu.billing_amount, mu.cycle_period, mu.cycle_number, mu.billing_limit, mu.trial_amount, mu.trial_limit, UNIX_TIMESTAMP(mu.startdate) as startdate, UNIX_TIMESTAMP(mu.enddate) as enddate, m.name as membership, umf.meta_value as first_name, uml.meta_value as last_name, umlat.meta_value as lat, umlng.meta_value as lng FROM $wpdb->users u ";	

	$sql_parts['JOIN'] = "
	LEFT JOIN $wpdb->usermeta umh ON umh.meta_key = 'pmpromd_hide_directory' AND u.ID = umh.user_id 
	LEFT JOIN $wpdb->usermeta umf ON umf.meta_key = 'first_name' AND u.ID = umf.user_id 
	LEFT JOIN $wpdb->usermeta uml ON uml.meta_key = 'last_name' AND u.ID = uml.user_id 
	LEFT JOIN $wpdb->usermeta umlat ON umlat.meta_key = 'pmpro_lat' AND u.ID = umlat.user_id 
	LEFT JOIN $wpdb->usermeta umlng ON umlng.meta_key = 'pmpro_lng' AND u.ID = umlng.user_id 
	LEFT JOIN $wpdb->usermeta um ON u.ID = um.user_id LEFT JOIN $wpdb->pmpro_memberships_users mu ON u.ID = mu.user_id LEFT JOIN $wpdb->pmpro_membership_levels m ON mu.membership_id = m.id ";

	$sql_parts['WHERE'] = "WHERE mu.status = 'active' AND (umh.meta_value IS NULL OR umh.meta_value <> '1') AND mu.membership_id > 0 ";

	$sql_parts['GROUP'] = "GROUP BY u.ID ";

	//Wouldn't need this for the map
	// $sql_parts['ORDER'] = "ORDER BY ". esc_sql($order_by) . " " . $order . " ";

	$sql_parts['LIMIT'] = "LIMIT $start, $limit";

	if( $s ) {
		$sql_parts['WHERE'] .= "AND (u.user_login LIKE '%" . esc_sql($s) . "%' OR u.user_email LIKE '%" . esc_sql($s) . "%' OR u.display_name LIKE '%" . esc_sql($s) . "%' OR um.meta_value LIKE '%" . esc_sql($s) . "%') ";
	}

	// If levels are passed in.
	if ( $levels ) {
		$sql_parts['WHERE'] .= "AND mu.membership_id IN(" . esc_sql($levels) . ") ";
	}

	// Allow filters for SQL parts.
	$sql_parts = apply_filters( 'pmpro_membership_maps_sql_parts', $sql_parts, $levels, $s, $pn, $limit, $start, $end );

	$sqlQuery = $sql_parts['SELECT'] . $sql_parts['JOIN'] . $sql_parts['WHERE'] . $sql_parts['GROUP'] . 
	// $sql_parts['ORDER'] . 
	$sql_parts['LIMIT'];


	$sqlQuery = apply_filters("pmpro_membership_maps_sql", $sqlQuery, $levels, $s, $pn, $limit, $start, $end, $order_by, $order );

	$members = $wpdb->get_results( $sqlQuery, ARRAY_A );

	$marker_array = pmpro_memmaps_build_markers( $members, $marker_attributes );

	return apply_filters( 'pmpro_memmaps_return_markers_array', $marker_array );

}

function pmpro_memmaps_build_markers( $members, $marker_attributes ){

	global $wpdb, $post, $pmpro_pages, $pmprorh_registration_fields;

	if( !empty( $marker_attributes['show_avatar'] ) && ( 
		$marker_attributes['show_avatar'] === "0" || 
		$marker_attributes['show_avatar'] === "false" || 
		$marker_attributes['show_avatar'] === "no" || 
		$marker_attributes['show_avatar'] === false ) 
	){
		$show_avatar = false;
	} else {
		$show_avatar = true;
	}

	if( $marker_attributes['link'] === "0" || 
		$marker_attributes['link'] === "false" || 
		$marker_attributes['link'] === "no" || 
		$marker_attributes['link'] === false
	){
		$link = false;
	} else {
		$link = true;
	}

	if( $marker_attributes['show_email'] === "0" || 
		$marker_attributes['show_email'] === "false" || 
		$marker_attributes['show_email'] === "no" || 
		$marker_attributes['show_email'] === false 
	){
		$show_email = false;
	} else {
		$show_email = true;
	}

	if( $marker_attributes['show_level'] === "0" || 
		$marker_attributes['show_level'] === "false" || 
		$marker_attributes['show_level'] === "no" || 
		$marker_attributes['show_level'] === false
	){
		$show_level = false;
	} else {
		$show_level = true;
	}

	if( $marker_attributes['show_startdate'] === "0" || 
		$marker_attributes['show_startdate'] === "false" || 
		$marker_attributes['show_startdate'] === "no" || 
		$marker_attributes['show_startdate'] === false 
	){
		$show_startdate = false;
	} else {
		$show_startdate = true;
	}

	if( !empty( $marker_attributes['fields'] ) ) {
		// Check to see if the Block Editor is used or the shortcode.
		if ( strpos( $marker_attributes['fields'], "\n" ) !== FALSE ) {
			$fields = rtrim( $marker_attributes['fields'], "\n" ); // clear up a stray \n
			$fields_array = explode("\n", $marker_attributes['fields']); // For new block editor.
		} else {
			$fields = rtrim( $marker_attributes['fields'], ';' ); // clear up a stray ;
			$fields_array = explode(";",$marker_attributes['fields']);
		}
		if( !empty( $fields_array ) ){
			for($i = 0; $i < count($fields_array); $i++ ){
				$fields_array[$i] = explode(",", trim($fields_array[$i]));
			}
		}
	} else {
		$fields_array = false;
	}

	// Get Register Helper field options
	$rh_fields = array();

	if(!empty($pmprorh_registration_fields)) {
		foreach($pmprorh_registration_fields as $location) {
			// var_dump($location);
			foreach($location as $field) {
				if(!empty($field->options))
					$rh_fields[$field->name] = $field->options;
			}
		}
	}

	$marker_array = array();

	if( !empty( $members ) ){
		foreach( $members as $member ){
			$member_array = array();

			$member_array['ID'] = $member['ID'];
			$member_array['marker_meta'] = $member;

			if( !empty( $pmpro_pages['profile'] ) ) {
				$profile_url = apply_filters( 'pmpromd_profile_url', get_permalink( $pmpro_pages['profile'] ) );
			}

			$name_content = "";
			$name_content .= '<h3 class="pmpro_member_directory_display-name">';
				if( !empty( $link ) && !empty( $profile_url ) ) {
					$name_content .= '<a href="'.add_query_arg( 'pu', $member['user_nicename'], $profile_url ).'">'.$member['display_name'].'</a>';
				} else {
					$name_content .= $member['display_name'];
				}
			$name_content .= '</h3>';

			//This will allow us to hook into the content and add custom fields from RH
			$avatar_content = "";
			if( $show_avatar ){
				$avatar_align = ( !empty( $marker_attributes['avatar_align'] ) ) ? $marker_attributes['avatar_align'] : "";
				$avatar_content .= '<div class="pmpro_member_directory_avatar">';
					if( !empty( $marker_attributes['link'] ) && !empty( $profile_url ) ) {
						$avatar_content .= '<a class="'.$avatar_align.'" href="'.add_query_arg('pu', $member['user_nicename'], $profile_url).'">'.get_avatar( $member['ID'], $marker_attributes['avatar_size'], NULL, $member['display_name'] ).'</a>';
					} else {
						$avatar_content .= '<span class="'.$avatar_align.'">'.get_avatar( $member['ID'], $marker_attributes['avatar_size'], NULL, $member['display_name'] ).'</span>';
					}
				$avatar_content .= '</div>';
			}

			$email_content = "";
			if( $show_email ){
				$email_content .= '<p class="pmpro_member_directory_email">';
					$email_content .= '<strong>'.__( 'Email Address', 'pmpro-membership-maps' ).'</strong>&nbsp;';
					$email_content .= $member['user_email'];
				$email_content .= '</p>';						
			}

			$level_content = "";
			if( $show_level ){
				$level_content .= '<p class="pmpro_member_directory_level">';
				$level_content .= '<strong>'.__('Level', 'pmpro-membership-maps').'</strong>&nbsp;';
				$level_content .= $member['membership'];
				$level_content .= '</p>';
			}

			$startdate_content = "";
			if( $show_startdate ){
				$startdate_content .= '<p class="pmpro_member_directory_date">';
				$startdate_content .= '<strong>'.__('Start Date', 'pmpro-membership-maps').'</strong>&nbsp;';
				$startdate_content .= date( get_option("date_format"), $member['joindate'] );
				$startdate_content .= '</p>';
						
			}

			$profile_content = "";
			if( !empty( $link ) && !empty( $profile_url ) ) {
				$profile_content .= '<p class="pmpro_member_directory_profile"><a href="'.add_query_arg( 'pu', $member['user_nicename'], $profile_url ).'">'.apply_filters( 'pmpro_memmaps_view_profile_text', __( 'View Profile', 'pmpro-membership-maps' ) ).'</a></p>';
			}

			$rhfield_content = "";

			if( !empty( $fields_array ) ){
				foreach( $fields_array as $field ){
						
					if ( WP_DEBUG ) {
						error_log("Content of field data: " . print_r( $field, true));
					}

					// Fix for a trailing space in the 'fields' shortcode attribute.
					if ( $field[0] === '' || empty( $field[1] ) ) {
						break;
					}

					if( !empty( $member[$field[1]] ) ){
						
						$rhfield_content .= '<p class="pmpro_member_directory_'.$field[1].'">';
								
						if( is_array( $meta_field ) && !empty( $meta_field['filename'] ) ){
							//this is a file field
							$rhfield_content .= '<strong>'.$field[0].'</strong>';
							$rhfield_content .= pmpro_memmaps_display_file_field($meta_field);
						} elseif ( is_array( $meta_field ) ){
							//this is a general array, check for Register Helper options first
							if(!empty($rh_fields[$field[1]])) {
								foreach($meta_field as $key => $value)
									$meta_field[$key] = $rh_fields[$field[1]][$value];
							}
							$rhfield_content .= '<strong>'.$field[0].'</strong>';
							$rhfield_content .= implode(", ",$meta_field);
						} elseif ( !empty( $rh_fields[$field[1]] ) && is_array( $rh_fields[$field[1]] ) ) {
							$rhfield_content .= '<strong>'.$field[0].'</strong>';
							$rhfield_content .= $rh_fields[$field[1]][$meta_field];
						} elseif ( $field[1] == 'user_url' ){
							$rhfield_content .= '<a href="'.$member[$field[1]].'" target="_blank">'.$field[0].'</a>';
						} else {
							$rhfield_content .= '<strong>'.$field[0].':</strong>';
							$rhfield_content .= make_clickable($member[$field[1]]);
						}

						$rhfield_content .= '</p>';

					}
				}
			}

			$marker_content_order = apply_filters( 'pmpro_memmaps_marker_content_order', array(
				'name' 		=> $name_content,
				'avatar' 	=> $avatar_content,
				'email' 	=> $email_content,
				'level'		=> $level_content,
				'startdate' => $startdate_content,				
				'rh_fields'	=> $rhfield_content,
				'profile'	=> $profile_content,
			) );

			$member_array['marker_content'] = implode( " ", $marker_content_order );

			$marker_array[] = $member_array;
		}
	}

	return $marker_array;

}

function pmpro_memmaps_after_checkout( $user_id, $morder ){

	$member_address = array(
		'street' 	=> '',
		'city' 		=> '',
		'state' 	=> '',
		'zip' 		=> ''
	);

	if( !empty( $morder->billing->street ) ){
		//Billing details are active, we can geocode
		$member_address = array(
			'street' 	=> $morder->billing->street,
			'city' 		=> $morder->billing->city,
			'state' 	=> $morder->billing->state,
			'zip' 		=> $morder->billing->zip
		);
	}

	$member_address = apply_filters( 'pmpro_memmaps_member_address_after_checkout', $member_address, $user_id, $morder );

	$address_string = implode( ", ", array_filter( $member_address ) );	

	$remote_request = wp_remote_get( 'https://maps.googleapis.com/maps/api/geocode/json', 
		array( 'body' => array(
			'key' 		=> pmpro_getOption( 'pmpro_memmaps_api_key' ),
			'address' 	=> $address_string
		) ) 
	);

	if( !is_wp_error( $remote_request ) ){

		$request_body = wp_remote_retrieve_body( $remote_request );

		$request_body = json_decode( $request_body );

		if( !empty( $request_body->status ) && $request_body->status == 'OK' ){

			if( !empty( $request_body->results[0] ) ){

				$lat = $request_body->results[0]->geometry->location->lat;
				$lng = $request_body->results[0]->geometry->location->lng;

				update_user_meta( $user_id, 'pmpro_lat', $lat );
				update_user_meta( $user_id, 'pmpro_lng', $lng );

				do_action( 'pmpro_memmaps_geocode_response', $request_body, $user_id, $morder );

			}

		}

	}

}
add_action( 'pmpro_after_checkout', 'pmpro_memmaps_after_checkout', 10, 2 );

//Adds API Key field to advanced settings page
function pmpro_memmaps_advanced_settings_field( $fields ) {
       
	$fields['pmpro_memmaps_api_key'] = array(
		'field_name' => 'pmpro_memmaps_api_key',
		'field_type' => 'text',
		'label' => __( 'Google Maps API Key', 'pmpro-membership-maps' ),
		'description' => __( 'Applies to Paid Memberships Pro - Membership Maps Add-on', 'pmpro-membership-maps')
	);

    return $fields;
}
add_filter('pmpro_custom_advanced_settings','pmpro_memmaps_advanced_settings_field', 20);

/*
Function to add links to the plugin row meta
*/
function pmpro_memmaps_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-membership-maps.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('#')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
			'<a href="' . esc_url('https://www.paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmpro_memmaps_plugin_row_meta', 10, 2);

//Load text domain
function pmpro_memmaps_load_textdomain() {

	$plugin_rel_path = basename( dirname( __FILE__ ) ) . '/languages';
	load_plugin_textdomain( 'pmpro-membership-maps', false, $plugin_rel_path );

}
add_action( 'plugins_loaded', 'pmpro_memmaps_load_textdomain' );

//Show map on directory page
function pmpro_memmaps_load_map_directory_page( $sqlQuery, $avatar_size, $fields, $layout, $level, $levels, $limit, $link, $order_by, $order, $show_avatar, $show_email, $show_level, $show_search, $show_startdate, $avatar_align ){

	$attributes = array(
		'link' => $link,
		'avatar_size' => $avatar_size,
		'show_avatar' => $show_avatar,
		'show_email' => $show_email,
		'show_level' => $show_level,
		'show_startdate' => $show_startdate,
		'avatar_align' => $avatar_align,
		'fields' => $fields,
	);

	echo pmpro_memmaps_shortcode( $attributes );

}
add_action( 'pmpro_member_directory_before', 'pmpro_memmaps_load_map_directory_page', 10, 16 );

//If we're on the profile page, only show that member's marker
function pmpro_memmaps_load_profile_map_marker( $sql_parts, $levels, $s, $pn, $limit, $start, $end ){

	if( isset( $_REQUEST['pu'] ) ){
		$member = sanitize_text_field( $_REQUEST['pu'] );
		// $sql_parts['WHERE'] .= "AND u.user_nicename = ".sanitize_text_field( $_REQUEST['pu'] );
		$sql_parts['WHERE'] .= "AND (u.user_login LIKE '%" . esc_sql($member) . "%' OR u.user_email LIKE '%" . esc_sql($member) . "%' OR u.display_name LIKE '%" . esc_sql($member) . "%' OR um.meta_value LIKE '%" . esc_sql($member) . "%') ";
	}

	return $sql_parts;

}
add_filter( 'pmpro_membership_maps_sql_parts', 'pmpro_memmaps_load_profile_map_marker', 10, 7 );

//Adds the map to the profile page
function pmpro_memmaps_show_single_map_profile( $pu ){

	echo do_shortcode( '[membership_maps]' );

}
add_action( 'pmpro_member_profile_before', 'pmpro_memmaps_show_single_map_profile', 10, 1 );

function pmpro_memmaps_display_file_field( $meta_field ) {
	$meta_field_file_type = wp_check_filetype($meta_field['fullurl']);
	switch ($meta_field_file_type['type']) {
		case 'image/jpeg':
		case 'image/png':
		case 'image/gif':
			return '<a href="' . $meta_field['fullurl'] . '" title="' . $meta_field['filename'] . '" target="_blank"><img class="subtype-' . $meta_field_file_type['ext'] . '" src="' . $meta_field['fullurl'] . '"><span class="pmpromd_filename">' . $meta_field['filename'] . '</span></a>'; break;
	case 'video/mpeg':
	case 'video/mp4':
		return do_shortcode('[video src="' . $meta_field['fullurl'] . '"]'); break;
	case 'audio/mpeg':
	case 'audio/wav':
		return do_shortcode('[audio src="' . $meta_field['fullurl'] . '"]'); break;
	default:
		return '<a href="' . $meta_field['fullurl'] . '" title="' . $meta_field['filename'] . '" target="_blank"><img class="subtype-' . $meta_field_file_type['ext'] . '" src="' . wp_mime_type_icon($meta_field_file_type['type']) . '"><span class="pmpromd_filename">' . $meta_field['filename'] . '</span></a>'; break;
	}
}