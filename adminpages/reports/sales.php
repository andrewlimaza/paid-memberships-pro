<?php
/*
	PMPro Report
	Title: Sales
	Slug: sales

	For each report, add a line like:
	global $pmpro_reports;
	$pmpro_reports['slug'] = 'Title';

	For each report, also write two functions:
	* pmpro_report_{slug}_widget()   to show up on the report homepage.
	* pmpro_report_{slug}_page()     to show up when users click on the report page widget.
*/
global $pmpro_reports;
$gateway_environment = pmpro_getOption("gateway_environment");
if($gateway_environment == "sandbox")
	$pmpro_reports['sales'] = __('Sales and Revenue (Testing/Sandbox)', 'paid-memberships-pro' );
else
	$pmpro_reports['sales'] = __('Sales and Revenue', 'paid-memberships-pro' );

//queue Google Visualization JS on report page
function pmpro_report_sales_init()
{
	if ( is_admin() && isset( $_REQUEST['report'] ) && $_REQUEST[ 'report' ] == 'sales' && isset( $_REQUEST['page'] ) && $_REQUEST[ 'page' ] == 'pmpro-reports' ) {
		wp_enqueue_script( 'corechart', plugins_url( 'js/corechart.js',  plugin_dir_path( __DIR__ ) ) );
	}

}
add_action("init", "pmpro_report_sales_init");

//widget
function pmpro_report_sales_widget() {
	global $wpdb;
?>
<style>
	#pmpro_report_sales tbody td:last-child {text-align: right; }
</style>
<span id="pmpro_report_sales" class="pmpro_report-holder">
	<table class="wp-list-table widefat fixed">
	<thead>
		<tr>
			<th scope="col">&nbsp;</th>
			<th scope="col"><?php esc_html_e('Sales', 'paid-memberships-pro' ); ?></th>
			<th scope="col"><?php esc_html_e('Revenue', 'paid-memberships-pro' ); ?></th>
		</tr>
	</thead>
	<?php
		$reports = array(
			'today'      => __('Today', 'paid-memberships-pro' ),
			'this month' => __('This Month', 'paid-memberships-pro' ),
			'this year'  => __('This Year', 'paid-memberships-pro' ),
			'all time'   => __('All Time', 'paid-memberships-pro' ),
		);

	foreach ( $reports as $report_type => $report_name ) {
		//sale prices stats
		$count = 0;
		$max_prices_count = apply_filters( 'pmpro_admin_reports_max_sale_prices', 5 );
		$prices = pmpro_get_prices_paid( $report_type, $max_prices_count );
		?>
		<tbody>
			<tr class="pmpro_report_tr">
				<th scope="row">
					<?php if( ! empty( $prices ) ) { ?>
						<button class="pmpro_report_th pmpro_report_th_closed"><?php echo esc_html($report_name); ?></button>
					<?php } else { ?>
						<?php echo esc_html($report_name); ?>
					<?php } ?>
				</th>
				<td><?php echo esc_html( number_format_i18n( pmpro_getSales( $report_type, null, 'all' ) ) ); ?></td>
				<td><?php echo pmpro_escape_price( pmpro_formatPrice( pmpro_getRevenue( $report_type ) ) ); ?></td>
			</tr>
			<?php
				//sale prices stats
				$count = 0;
				$max_prices_count = apply_filters( 'pmpro_admin_reports_max_sale_prices', 5 );
				foreach ( $prices as $price => $quantity ) {
					if ( $count++ >= $max_prices_count ) {
						break;
					}
			?>
				<tr class="pmpro_report_tr_sub" style="display: none;">
					<th scope="row">- <?php echo pmpro_escape_price( pmpro_formatPrice( $price ) );?></th>
					<td><?php echo esc_html( number_format_i18n( $quantity['total'] ) ); ?></td>
					<td><?php echo pmpro_escape_price( pmpro_formatPrice( $price * $quantity['total'] ) ); ?></td>
				</tr>
			<?php
			}
			?>
		</tbody>
		<?php
	}
	?>
	</table>
	<?php if ( function_exists( 'pmpro_report_sales_page' ) ) { ?>
		<p class="pmpro_report-button">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=pmpro-reports&report=sales' ) ); ?>"><?php esc_html_e('Details', 'paid-memberships-pro' );?></a>
		</p>
	<?php } ?>
</span>

<?php
}

function pmpro_report_sales_data( $args ){

	global $wpdb;

	$type_function = ! empty( $args['type_function'] ) ? $args['type_function'] : '';
	$date_function = ! empty( $args['date_function'] ) ? $args['date_function'] : '';
	$discount_code = ! empty( $args['discount_code'] ) ? $args['discount_code'] : '';
	$startdate = ! empty( $args['startdate'] ) ? $args['startdate'] : '';
	$enddate = ! empty( $args['enddate'] ) ? $args['enddate'] : '';

	//testing or live data
	$gateway_environment = pmpro_getOption("gateway_environment");

	// Get the estimated second offset to convert from GMT time to local.This is not perfect as daylight
	// savings time can come and go in the middle of a month, but it's a tradeoff that we are making
	// for performance so that we don't need to go through each order manually to calculate the local time.
	$tz_offset = strtotime( $startdate ) - strtotime( get_gmt_from_date( $startdate . " 00:00:00" ) );

 	$sqlQuery = "SELECT date,
					MONTH( mo1timestamp ) as month, 
				 	$type_function(mo1total) as value,
				 	$type_function( IF( mo2id IS NOT NULL, mo1total, NULL ) ) as renewals
				 FROM ";
	$sqlQuery .= "(";	// Sub query.
	$sqlQuery .= "SELECT $date_function( DATE_ADD( mo1.timestamp, INTERVAL " . esc_sql( $tz_offset ) . " SECOND ) ) as date,
					    mo1.id as mo1id,
						mo1.total as mo1total,
						mo1.timestamp as mo1timestamp, 
						mo2.id as mo2id
				 FROM $wpdb->pmpro_membership_orders mo1
				 	LEFT JOIN $wpdb->pmpro_membership_orders mo2 ON mo1.user_id = mo2.user_id
                        AND mo2.total > 0
                        AND mo2.status NOT IN('refunded', 'review', 'token', 'error')                                            
                        AND mo2.timestamp < mo1.timestamp
                        AND mo2.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";

	if ( ! empty( $discount_code ) ) {
		$sqlQuery .= "LEFT JOIN $wpdb->pmpro_discount_codes_uses dc ON mo1.id = dc.order_id ";
	}

	$sqlQuery .= "WHERE mo1.total > 0
					AND mo1.timestamp >= DATE_ADD( '" . esc_sql( $startdate ) . "' , INTERVAL - " . esc_sql( $tz_offset ) . " SECOND )
					AND mo1.status NOT IN('refunded', 'review', 'token', 'error')
					AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";

	if(!empty($enddate))
		$sqlQuery .= "AND mo1.timestamp <= DATE_ADD( '" . esc_sql( $enddate ) . " 23:59:59' , INTERVAL - " . esc_sql( $tz_offset ) . " SECOND )";

	if(!empty($l))
		$sqlQuery .= "AND mo1.membership_id IN(" . esc_sql( $l ) . ") ";

	if ( ! empty( $discount_code ) ) {
		$sqlQuery .= "AND dc.code_id = '" . esc_sql( $discount_code ) . "' ";
	}

	$sqlQuery .= " GROUP BY mo1.id ";
	$sqlQuery .= ") t1";
	$sqlQuery .= " GROUP BY date ORDER by date";

	return $wpdb->get_results( $sqlQuery );

}

function pmpro_report_sales_page()
{
	global $wpdb, $pmpro_currency_symbol, $pmpro_currency, $pmpro_currencies;

	//get values from form
	if(isset($_REQUEST['type']))
		$type = sanitize_text_field($_REQUEST['type']);
	else
		$type = "revenue";

	if($type == "sales")
		$type_function = "COUNT";
	else
		$type_function = "SUM";

	if(isset($_REQUEST['period']))
		$period = sanitize_text_field($_REQUEST['period']);
	else
		$period = "daily";

	if(isset($_REQUEST['month']))
		$month = intval($_REQUEST['month']);
	else
		$month = date_i18n("n", current_time('timestamp'));

	$thisyear = date_i18n("Y", current_time('timestamp'));
	if(isset($_REQUEST['year']))
		$year = intval($_REQUEST['year']);
	else
		$year = $thisyear;

	if(isset($_REQUEST['level']))
		$l = intval($_REQUEST['level']);
	else
		$l = "";

	if ( isset( $_REQUEST[ 'discount_code' ] ) ) {
		$discount_code = intval( $_REQUEST[ 'discount_code' ] );
	} else {
		$discount_code = '';
	}

	if ( isset( $_REQUEST[ 'show_parts' ] ) ) {
		$new_renewals = sanitize_text_field( $_REQUEST[ 'show_parts' ] );
	} else {
		$new_renewals = 'new_renewals';
	}

	if ( isset( $_REQUEST['compare_period'] ) ) {
		$compare_period = 1;
		$previous_year = date( 'Y', strtotime( $year.'-'.$month.'-01'.' - 1 YEAR' ) );
	} else {
		$previous_startdate = $previous_enddate = $previous_year = $compare_period = 0;	
	}

	$currently_in_period = false;

	//calculate start date and how to group dates returned from DB
	if( $period == "daily" ) {		
		$startdate = $year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-01';
		$enddate = $year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-' . date_i18n('t', strtotime( $startdate ) );
		
		$date_function = 'DAY';
		$currently_in_period = ( intval( date( 'Y' ) ) == $year && intval( date( 'n' ) ) == $month );
	}
	elseif($period == "monthly")
	{
		$startdate = $year . '-01-01';
		$enddate = strval(intval($year)+1) . '-01-01';
		$date_function = 'MONTH';
		$currently_in_period = ( intval( date( 'Y' ) ) == $year );
	} else if ( $period === '7days' || $period === '30days' || $period === '12months' ) {

		$todays_date = current_time( 'mysql' );

		if( $period === '7days' || $period === '30days' ) {
			$timeframe_string = 'DAY';
			$date_function = 'DAY';
			if( $period == '7days' ) { $timeframe = 7; }
			if( $period == '30days' ) { $timeframe = 30; }
		} else {
			$timeframe_string = 'MONTH';
			$date_function = 'MONTH';
			$timeframe = 12;
		}

		$startdate = date( 'Y-m-d', strtotime( $todays_date .' -'.$timeframe.' '.$timeframe_string ) );
		$enddate = current_time( 'mysql' );
		$currently_in_period = ( intval( date( 'Y' ) ) == $year && intval( date( 'n' ) ) == $month );

	} else {
		$startdate = '1970-01-01';	//all time
		$date_function = 'YEAR';
		$currently_in_period = true;
	}		

	if( $compare_period ) {
		$previous_startdate = $previous_year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-01';
		$previous_enddate = $previous_year . '-' . substr("0" . $month, strlen($month) - 1, 2) . '-' . date_i18n('t', strtotime( $previous_startdate ) );

		//Remove - just for testing
		//
		$previous_startdate = 2022 . '-' . substr("0" . 8, strlen(8) - 1, 2) . '-01';
		$previous_enddate = 2022 . '-' . substr("0" . 8, strlen(8) - 1, 2) . '-' . date_i18n('t', strtotime( $previous_startdate ) );

	} 

	//get data
	$args = array(
		'type_function' => $type_function,
		'date_function' => $date_function,
		'discount_code' => $discount_code,
		'startdate' => $startdate,
		'enddate' => $enddate
	);

	$dates = pmpro_report_sales_data( $args );

	if ( $compare_period ) {
		$args['startdate'] = $previous_startdate;
		$args['enddate'] = $previous_enddate;

		$previous_period_dates = pmpro_report_sales_data( $args );
	}

	//fill in blanks in dates
	$cols = array();
	$csvdata = array();
	$total_in_period = 0;
	$units_in_period = 0; // Used for averages.
	
	$lastday = date_i18n("t", strtotime($startdate, current_time("timestamp")));
		$day_of_month = intval( date( 'j' ) );

	if( $period === '7days' || $period === '30days' || $period === '12months' ) {
		//Display the data how we need it for that specific timeframe
	
		$date_by_month = array();
		foreach( $dates as $date ) {

			if( ! empty( $date_by_month[$date->month] ) ) {
				$date_by_month[$date->month][] = $date;
			} else {
				$date_by_month[$date->month] = array( $date );
			}
			
		}

		ksort( $date_by_month);

		$todays_day = date( 'd', current_time( 'timestamp' ) );
		$todays_month = date( 'n', current_time( 'timestamp' ) );

		foreach( $date_by_month as $month_no => $date_data ) {

			$days_in_this_month = cal_days_in_month( CAL_GREGORIAN, intval( $month_no ), date('Y',current_time('timestamp')) );

			if( $month_no == $todays_month ) {
				//Only count up to todays day
				$days_in_this_month = $todays_day;
			}

			for( $i = 1; $i <= $days_in_this_month; $i++ ) {

				$cols[sprintf( "%d-%d", $month_no, $i )] = array(0, 0);
				$csvdata[sprintf( "%d-%d", $month_no, $i-1 )] = (object)array('date'=>$i, 'total'=>'', 'new'=> '', 'renewals'=>'');
				if ( ! $currently_in_period || $i < $day_of_month ) {
					$units_in_period++;
				}
				
				foreach($date_data as $date){
					if($date->date == $i) {
						$cols[sprintf( "%d-%d", $month_no, $i )] = array( $date->value, $date->renewals );
						$csvdata[sprintf( "%d-%d", $month_no, $i-1 )] = (object)array('date'=>$i, 'total'=>$date->value, 'new'=> $date->value - $date->renewals, 'renewals'=> $date->renewals);
						if ( ! $currently_in_period || $i < $day_of_month ) {
							$total_in_period += $date->value;
						}
					}	
				}
			}

		}

		if( $period === '12months' ) {
			//Take the last 365 days
			$count_back = 365;
		} else {
			//Take the last x amount of days in the timeframe
			$count_back = $timeframe;
		}

		$cols = array_slice($cols, -$count_back, $count_back, true);

	} else if( $period == "daily" ) {

		for( $i = 1; $i <= $lastday; $i++ ) {

			$cols[$i] = array(0, 0, 0, 0);
			$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>'', 'new'=> '', 'renewals'=>'');
			if ( ! $currently_in_period || $i < $day_of_month ) {
				$units_in_period++;
			}
			
			foreach($dates as $date)
			{
				if($date->date == $i) {
					$cols[$i][0] = $date->value;
					$cols[$i][1] = $date->renewals;
					$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>$date->value, 'new'=> $date->value - $date->renewals, 'renewals'=> $date->renewals);
					if ( ! $currently_in_period || $i < $day_of_month ) {
						$total_in_period += $date->value;
					}
				}	
			}

			if( $compare_period ) {

				foreach($previous_period_dates as $prev_date) {
					
					if($prev_date->date == $i) {
						$cols[$i][2] = $prev_date->value;
						$cols[$i][3] = $prev_date->renewals;
					}	
				}

			}
			

		}

		
		
	}
	elseif($period == "monthly")
	{
		$month_of_year = intval( date( 'n' ) );
		for($i = 1; $i < 13; $i++)
		{
			$cols[$i] = array(0, 0, 0, 0);
			$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>'', 'new'=> '', 'renewals'=>'');
			if ( ! $currently_in_period || $i < $day_of_month ) {
				$units_in_period++;
			}
			
			foreach($dates as $date)
			{
				if($date->date == $i) {
					$cols[$i][0] = $date->value;
					$cols[$i][1] = $date->renewals;
					$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>$date->value, 'new'=> $date->value - $date->renewals, 'renewals'=> $date->renewals);
					if ( ! $currently_in_period || $i < $day_of_month ) {
						$total_in_period += $date->value;
					}
				}	
			}

			if( $compare_period ) {

				foreach($previous_period_dates as $prev_date) {
					
					if($prev_date->date == $i) {
						$cols[$i][2] = $prev_date->value;
						$cols[$i][3] = $prev_date->renewals;
					}	
				}

			}
		}
	}
	else //annual
	{
		//get min and max years
		$min = 9999;
		$max = 0;
		foreach($dates as $date)
		{
			$min = min($min, $date->date);
			$max = max($max, $date->date);
		}

		$current_year = intval( date( 'Y' ) );
		for($i = $min; $i <= $max; $i++)
		{
			$cols[$i] = array(0, 0, 0, 0);
			$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>'', 'new'=> '', 'renewals'=>'');
			if ( ! $currently_in_period || $i < $day_of_month ) {
				$units_in_period++;
			}
			
			foreach($dates as $date)
			{
				if($date->date == $i) {
					$cols[$i][0] = $date->value;
					$cols[$i][1] = $date->renewals;
					$csvdata[$i-1] = (object)array('date'=>$i, 'total'=>$date->value, 'new'=> $date->value - $date->renewals, 'renewals'=> $date->renewals);
					if ( ! $currently_in_period || $i < $day_of_month ) {
						$total_in_period += $date->value;
					}
				}	
			}

			if( $compare_period ) {

				foreach($previous_period_dates as $prev_date) {
					
					if($prev_date->date == $i) {
						$cols[$i][2] = $prev_date->value;
						$cols[$i][3] = $prev_date->renewals;
					}	
				}

			}
		}
	}

	$average = 0;
	if ( 0 !== $units_in_period ) {
		$average = $total_in_period / $units_in_period; // Not including this unit.
	}
	
	// Save a transient for each combo of params. Expires in 1 hour.
	$param_array = array( $period, $type, $month, $year, $l, $discount_code );
	$param_hash = md5( implode( ' ', $param_array ) . PMPRO_VERSION );
	set_transient( 'pmpro_sales_data_' . $param_hash, $csvdata, HOUR_IN_SECONDS );

	// Build CSV export link.
	$args = array(
		'action' => 'sales_report_csv',
		'period' => $period,
		'type' => $type,
		'year' => $year,
		'month' => $month,
		'level' => $l,
		'discount_code' => $discount_code
	);
	$csv_export_link = add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	?>
	<form id="posts-filter" method="get" action="">
	<h1 class="wp-heading-inline">
		<?php _e('Sales and Revenue', 'paid-memberships-pro' );?>
	</h1>
	<a target="_blank" href="<?php echo esc_url( $csv_export_link ); ?>" class="page-title-action pmpro-has-icon pmpro-has-icon-download"><?php esc_html_e( 'Export to CSV', 'paid-memberships-pro' ); ?></a>
	<div class="pmpro_report-filters">
		<h3><?php esc_html_e( 'Customize Report', 'paid-memberships-pro'); ?></h3>
		<div class="tablenav top">
			<label for="period"><?php echo esc_html_x( 'Show', 'Dropdown label, e.g. Show Period', 'paid-memberships-pro' ); ?></label>
			<select id="period" name="period">
				<option value="daily" <?php selected($period, "daily");?>><?php esc_html_e('Daily', 'paid-memberships-pro' );?></option>
				<option value="monthly" <?php selected($period, "monthly");?>><?php esc_html_e('Monthly', 'paid-memberships-pro' );?></option>
				<option value="annual" <?php selected($period, "annual");?>><?php esc_html_e('Annual', 'paid-memberships-pro' );?></option>
				<option value='7days' <?php selected( $period, '7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'paid-memberships-pro' ); ?></option>
				<option value='30days' <?php selected( $period, '30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'paid-memberships-pro' ); ?></option>
				<option value='12months' <?php selected( $period, '12months' ); ?>><?php esc_html_e( 'Last 12 Months', 'paid-memberships-pro' ); ?></option>
			</select>
			<select name="type">
				<option value="revenue" <?php selected($type, "revenue");?>><?php esc_html_e('Revenue', 'paid-memberships-pro' );?></option>
				<option value="sales" <?php selected($type, "sales");?>><?php esc_html_e('Sales', 'paid-memberships-pro' );?></option>
			</select>
			<span id="for"><?php esc_html_e('for', 'paid-memberships-pro' )?></span>		
			<select id="month" name="month">
				<?php for($i = 1; $i < 13; $i++) { ?>
					<option value="<?php echo esc_attr( $i );?>" <?php selected($month, $i);?>><?php echo esc_html(date_i18n("F", mktime(0, 0, 0, $i, 2)));?></option>
				<?php } ?>
			</select>
			<select id="year" name="year">
				<?php for($i = $thisyear; $i > 2007; $i--) { ?>
					<option value="<?php echo esc_attr( $i );?>" <?php selected($year, $i);?>><?php echo esc_html( $i );?></option>
				<?php } ?>
			</select>
			<span id="for"><?php esc_html_e('for', 'paid-memberships-pro' )?></span>
			<select id="level" name="level">
				<option value="" <?php if(!$l) { ?>selected="selected"<?php } ?>><?php esc_html_e('All Levels', 'paid-memberships-pro' );?></option>
				<?php
					$levels = $wpdb->get_results("SELECT id, name FROM $wpdb->pmpro_membership_levels ORDER BY name");
					$levels = pmpro_sort_levels_by_order( $levels );
					foreach($levels as $level)
					{
				?>
					<option value="<?php echo esc_attr( $level->id ); ?>" <?php if($l == $level->id) { ?>selected="selected"<?php } ?>><?php echo esc_html( $level->name); ?></option>
				<?php
					}
				?>
			</select>		
			<?php
			$sqlQuery = "SELECT SQL_CALC_FOUND_ROWS * FROM $wpdb->pmpro_discount_codes ";
			$sqlQuery .= "ORDER BY id DESC ";
			$codes = $wpdb->get_results($sqlQuery, OBJECT);
			if ( ! empty( $codes ) ) { ?>
			<select id="discount_code" name="discount_code">
				<option value="" <?php if ( empty( $discount_code ) ) { ?>selected="selected"<?php } ?>><?php esc_html_e('All Codes', 'paid-memberships-pro' );?></option>
				<?php foreach ( $codes as $code ) { ?>
					<option value="<?php echo esc_attr( $code->id ); ?>" <?php selected( $discount_code, $code->id ); ?>><?php echo esc_html( $code->code ); ?></option>
				<?php } ?>
			</select>
			<?php } ?>
			<select id="show_parts" name="show_parts">
				<option value='new_renewals' <?php selected( $new_renewals, 'new_renewals' ); ?> ><?php esc_html_e( 'Show New and Renewals', 'paid-memberships-pro' ); ?></option>
				<option value='only_new' <?php selected( $new_renewals, 'only_new' ); ?> ><?php esc_html_e( 'Show Only New', 'paid-memberships-pro' ); ?></option>
				<option value='only_renewals' <?php selected( $new_renewals, 'only_renewals' ); ?> ><?php esc_html_e( 'Show Only Renewals', 'paid-memberships-pro' ); ?></option>
			</select>
			<label for='compare_period'><input type='checkbox' id='compare_period' name='compare_period' <?php checked( 1, $compare_period ); ?> /><?php esc_html_e( 'Compare to Previous Period', 'paid-memberships-pro' ); ?></label>
			<input type="hidden" name="page" value="pmpro-reports" />
			<input type="hidden" name="report" value="sales" />
			<input type="submit" class="button button-primary action" value="<?php esc_attr_e('Generate Report', 'paid-memberships-pro' );?>" />
			<br class="clear" />
		</div> <!-- end tablenav -->
	</div> <!-- end pmpro_report-filters -->
	<div class="pmpro_chart_area">
		<div id="chart_div"></div>
		<div class="pmpro_chart_description"><p><center><em><?php esc_html_e( 'Average line calculated using data prior to current day, month, or year.', 'paid-memberships-pro' ); ?></em></center></p></div>
	</div>
	<script>
		//update month/year when period dropdown is changed
		jQuery(document).ready(function() {
			jQuery('#period').change(function() {
				pmpro_ShowMonthOrYear();
			});
		});

		function pmpro_ShowMonthOrYear()
		{
			var period = jQuery('#period').val();
			if(period == 'daily')
			{
				jQuery('#for').show();
				jQuery('#month').show();
				jQuery('#year').show();
			}
			else if(period == 'monthly')
			{
				jQuery('#for').show();
				jQuery('#month').hide();
				jQuery('#year').show();
			}
			else
			{
				jQuery('#for').hide();
				jQuery('#month').hide();
				jQuery('#year').hide();
			}
		}

		pmpro_ShowMonthOrYear();

		//draw the chart
		google.charts.load('current', {'packages':['corechart']});
		google.charts.setOnLoadCallback(drawVisualization);
		function drawVisualization() {
			var dataTable = new google.visualization.DataTable();
			
			// Date
			dataTable.addColumn('string', <?php echo wp_json_encode( esc_html( $date_function ) ); ?>);

			// Tooltip
			dataTable.addColumn({type: 'string', role: 'tooltip', 'p': {'html': true}});

			<?php if ( $new_renewals === 'only_renewals' ) { ?>
				// Data for renewal sales or revenue (bar chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html__( 'Renewals', 'paid-memberships-pro' ) ); ?>);
			<?php } elseif ( $new_renewals === 'only_new' ) { ?>
				// Data for new sales or revenue (bar chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html( sprintf( __( 'New %s', 'paid-memberships-pro' ), ucwords( $type ) ) ) ); ?>);
			<?php } else { ?>
				// Data for renewal sales or revenue (bar chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html__( 'Renewals', 'paid-memberships-pro' ) ); ?>);

				// Data for new sales or revenue (bar chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html( sprintf( __( 'New %s', 'paid-memberships-pro' ), ucwords( $type ) ) ) ); ?>);
			<?php } ?>

			<?php if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_new' ) ) ) { ?>
				// Data for comparison period new sales or revenue (line chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html( sprintf( __( 'Previous Period: New %s', 'paid-memberships-pro' ), ucwords( $type ) ) ) ); ?>);
			<?php } ?>
			<?php if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_renewals' ) ) ) { ?>
				// Data for comparison period renewal sales or revenue (line chart).
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html( sprintf( __( 'Previous Period: Renewal %s', 'paid-memberships-pro' ), ucwords( $type ) ) ) ); ?>);
			<?php } ?>

			// Average sales or revenue data (line chart).
			<?php if ( $type === 'sales' ) { ?>
				dataTable.addColumn('number', <?php echo wp_json_encode( esc_html( sprintf( __( 'Average: %s', 'paid-memberships-pro' ), number_format_i18n( $average, 2 ) ) ) ); ?>);
			<?php } else { ?>
				dataTable.addColumn('number', <?php echo wp_json_encode( sprintf( esc_html__( 'Average: %s', 'paid-memberships-pro' ), pmpro_escape_price( html_entity_decode( pmpro_formatPrice( $average ) ) ) ) ); ?>);
			<?php } ?>

			dataTable.addRows([
				<?php foreach($cols as $date => $value) { ?>
					[
						<?php
							$date_value = $date;

							if ( $period === 'monthly' ) {
								$date_value = date_i18n( 'M', mktime( 0, 0, 0, $date, 2 ) );
							}

							echo wp_json_encode( esc_html( $date_value ) );
						?>,
						createCustomHTMLContent(
							<?php
								$date_value = $date;

								if ( $period === 'monthly' ) {
									$date_value = date_i18n( 'F', mktime( 0, 0, 0, $date, 2 ) );
								} elseif ( $period === 'daily' ) {
									$date_value = date_i18n( get_option( 'date_format' ), strtotime( $year . '-' . $month . '-' . $date ) );
								}
								// period
								echo wp_json_encode( esc_html( $date_value ) );
							?>,
							<?php if ( $type === 'sales' ) { ?>
								<?php
									// Current period renewal sales (renewals).
									echo wp_json_encode( (int) $value[1] );
								?>,
								<?php
									// Current period new sales (notRenewals).
									echo wp_json_encode( (int) $value[0] - $value[1] );
								?>,
								<?php
									// Current period total sales for view (total).
									echo wp_json_encode( (int) $value[0] );
								?>,
							<?php } else {
								if ( in_array( $new_renewals, array( 'new_renewals', 'only_renewals' ) ) ) {
									// Current period renewal revenue (renewals).
									echo wp_json_encode( pmpro_escape_price( pmpro_formatPrice( $value[1] ) ) );
								} else {
									echo 'false';
								} ?>,
								<?php
								if ( in_array( $new_renewals, array( 'new_renewals', 'only_new' ) ) ) {
									// Current period new revenue (notRenewals).
									echo wp_json_encode( pmpro_escape_price( pmpro_formatPrice( $value[0] - $value[1] ) ) );
								} else {
									echo 'false';
								} ?>,
								<?php
								if ( $new_renewals == 'new_renewals' ) {
									// Current period total revenue for view (total).
									// Only shown if showing new and renewal revenue.
									echo wp_json_encode( pmpro_escape_price( pmpro_formatPrice( $value[0] ) ) );
								} else {
									echo 'false';
								} ?>,
								<?php
									// Are we showing a compare period? (compare)
									echo wp_json_encode( $compare_period );
								?>,
								<?php
								if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_new' ) ) ) {
									// Previous period new revenue (compare_new).
									echo wp_json_encode( pmpro_escape_price( pmpro_formatPrice( $value[2] - $value[3] ) ) );
								} else {
									echo 'false';
								} ?>,
								<?php
								if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_renewals' ) ) ) {
									// Previous period renewal revenue (compare_renewal).
									echo wp_json_encode( pmpro_escape_price( pmpro_formatPrice( $value[3] ) ) );
								} else {
									echo 'false';
								} ?>
							<?php } ?>
						),
						<?php
						if ( $type === 'sales' ) {
							// Current period renewal sales.
							echo wp_json_encode( (int) $value[1] ).',';

							// Current period new sales.
							echo wp_json_encode( (int) $value[0] - $value[1] ).',';

							// Previous period renewal sales.
							echo wp_json_encode( (int) $value[3] ).',';

							// Previous period new sales.
							//echo wp_json_encode( (int) $value[2] - $value[3] ).',';
							echo wp_json_encode( (int) '0' ).',';
						} else {
							if ( $new_renewals == 'only_renewals' ) {
								// Current period renewal revenue.
								echo wp_json_encode( pmpro_round_price( $value[1] ) ).',';
							} elseif ( $new_renewals == 'only_new' ) {
								// Current period new revenue.
								echo wp_json_encode( pmpro_round_price( $value[0] - $value[1] ) ).',';
							} else {
								// Current period renewal revenue.
								echo wp_json_encode( pmpro_round_price( $value[1] ) ).',';

								// Current period new revenue.
								echo wp_json_encode( pmpro_round_price( $value[0] - $value[1] ) ).',';
							}

							if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_renewals' ) ) ) {
								// Previous period renewal revenue.
								echo wp_json_encode( pmpro_round_price( $value[3] ) ).',';
							}

							if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_new' ) ) ) {
								// Previous period new revenue.
								echo wp_json_encode( pmpro_round_price( $value[2] - $value[3] ) ).',';
							}

						}
						// Average sales or revenue data (line chart).
						echo wp_json_encode( pmpro_round_price( $average ) ).','; ?>
					],
				<?php } ?>
			]);

			<?php
			// Set the series data. There are 5 plotted data points: renewal, new, prev. renewal, prev. new, average.
			// We hide data points later based on the report settings using the hideColumns function.
			$series = array();

			// Data formatting for showing Renewal and New Sales/Revenue for current period.
			if ( $new_renewals === 'only_renewals' ) {
				// Renewal only
				$series[] = array(
					'color' => ( $type === 'sales' ) ? '#006699' : '#31825D'
				);
			} elseif ( $new_renewals === 'only_new' ) {
				// New only
				$series[] = array(
					'color' => ( $type === 'sales' ) ? '#0099C6' : '#5EC16C',
				);
			} else {
				// Renewal
				$series[] = array(
					'color' => ( $type === 'sales' ) ? '#006699' : '#31825D'
				);

				// New
				$series[] = array(
					'color' => ( $type === 'sales' ) ? '#0099C6' : '#5EC16C',
				);
			}

			// Previous Period New Sales/Revenue.
			if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_new' ) ) ) {
				$series[] = array(
					'color' => '#999999',
					'pointsVisible' => true,
					'type' => 'line'
				);
			}

			// Previous Period Renewal Sales/Revenue.
			if ( $compare_period && in_array( $new_renewals, array( 'new_renewals', 'only_renewals' ) ) ) {
				$series[] = array(
					'color' => '#666666',
					'pointsVisible' => true,
					'type' => 'line'
				);
			}

			// Last: Average series is a line chart.
			$series[] = array(
				'type' => 'line',
				'color' => '#B00000',
				'enableInteractivity' => false,
				'lineDashStyle' => [4,1]
			);
			?>
			var options = {
				title: pmpro_report_title_sales(),
				titlePosition: 'top',
				titleTextStyle: {
					color: '#555555',
				},
				legend: {position: 'bottom'},
				chartArea: {
					width: '90%',
				},
				focusTarget: 'category',
				tooltip: {
					isHtml: true
				},
				hAxis: {
					textStyle: {
						color: '#555555',
						fontSize: '12',
						italic: false,
					},
				},
				vAxis: {
					<?php if ( $type === 'sales') { ?>
						format: '0',
					<?php } ?>
					textStyle: {
						color: '#555555',
						fontSize: '12',
						italic: false,
					},
				},
				seriesType: 'bars',
				series: <?php echo wp_json_encode( $series ); ?>,
				<?php if ( $new_renewals === 'new_renewals' ) { ?>
					isStacked: true,
				<?php } ?>
			};

			var chart = new google.visualization.ColumnChart(document.getElementById('chart_div'));
			view = new google.visualization.DataView(dataTable);
			chart.draw(view, options);
		}

		function createCustomHTMLContent(period, renewals = false, notRenewals = false, total = false, compare = false, compare_new = false, compare_renewal = false) {

			// Our return var for the Tooltip HTML.
			var content_string;

			// Start building the Tooltip HTML.
			content_string = '<div style="padding:15px; font-size: 14px; line-height: 20px; color: #000000;">' +
				'<strong>' + period + '</strong><br/>';
			content_string += '<ul style="margin-bottom: 0px;">';

			// New Sales/Revenue.
			if ( notRenewals ) {
				content_string += '<li><span style="margin-right: 3px;">' + <?php echo wp_json_encode( esc_html__( 'New:', 'paid-memberships-pro' ) ); ?> + '</span>' + notRenewals + '</li>';
			}

			// Renewal Sales/Revenue.
			if ( renewals ) {
				content_string += '<li><span style="margin-right: 3px;">' + <?php echo wp_json_encode( esc_html__( 'Renewals:', 'paid-memberships-pro' ) ); ?> + '</span>' + renewals + '</li>';
			}

			// Total Sales/Revenue.
			if ( total ) {
				content_string += '<li style="border-top: 1px solid #CCC; margin-bottom: 0px; margin-top: 8px; padding-top: 8px;"><span style="margin-right: 3px;">' + <?php echo wp_json_encode( esc_html__( 'Total:', 'paid-memberships-pro' ) ); ?> + '</span>' + total + '</li>';
			}

			// Comparison Sales/Revenue.
			if ( compare ) {
				// Comparison Period New Sales/Revenue
				if ( compare_new ) {
					content_string += '<li style="border-top: 1px solid #CCC; margin-bottom: 0px; margin-top: 8px; padding-top: 8px;"><span style="margin-right: 3px;">' + <?php echo wp_json_encode( esc_html__( 'Previous Period New:', 'paid-memberships-pro' ) ); ?> + '</span>' + compare_new + '</li>';
				}

				// Comparison Period Renewal Sales/Revenue
				if ( compare_renewal ) {
					content_string += '<li style="border-top: 1px solid #CCC; margin-bottom: 0px; margin-top: 8px; padding-top: 8px;"><span style="margin-right: 3px;">' + <?php echo wp_json_encode( esc_html__( 'Previous Period Renewals:', 'paid-memberships-pro' ) ); ?> + '</span>' + compare_renewal + '</li>';
				}
			}

			// Finish the Tooltip HTML.
			content_string += '</ul>' + '</div>';

			// Return Tooltip HTML.
			return content_string;
		}
		function pmpro_report_title_sales() {
			<?php
				if ( ! empty( $month ) && $period === 'daily' ) {
					$date = date_i18n( 'F', mktime(0, 0, 0, $month, 2) ) . ' ' . $year;
				} elseif( ! empty( $year ) && $period === 'monthly'  ) {
					$date = $year;
				} else {
					$date = __( 'All Time', 'paid-memberships-pro' );
				}
			?>
			return <?php echo wp_json_encode( esc_html( sprintf( __( '%s %s for %s', 'paid-memberships-pro' ), ucwords( $period ), ucwords( $type ), ucwords( $date ) ) ) ); ?>;
		}
	</script>

	</form>
	<?php
}

/*
	Other code required for your reports. This file is loaded every time WP loads with PMPro enabled.
*/

//get sales
function pmpro_getSales( $period = 'all time', $levels = 'all', $type = 'all' ) {	
	//check for a transient
	$cache = get_transient( 'pmpro_report_sales' );
	$param_hash = md5( $period . ' ' . $type . PMPRO_VERSION );
	if(!empty($cache) && isset($cache[$param_hash]) && isset($cache[$param_hash][$levels]))
		return $cache[$param_hash][$levels];

	//a sale is an order with status NOT IN('refunded', 'review', 'token', 'error') with a total > 0
	if($period == "today")
		$startdate = date_i18n("Y-m-d", current_time('timestamp'));
	elseif($period == "this month")
		$startdate = date_i18n("Y-m", current_time('timestamp')) . "-01";
	elseif($period == "this year")
		$startdate = date_i18n("Y", current_time('timestamp')) . "-01-01";
	else
		$startdate = date_i18n("Y-m-d", 0);

	$gateway_environment = pmpro_getOption("gateway_environment");

	// Convert from local to UTC.
	$startdate = get_gmt_from_date( $startdate );

	// Build the query.
	global $wpdb;
	$sqlQuery = "SELECT mo1.id FROM $wpdb->pmpro_membership_orders mo1 ";
	
	// Need to join on older orders if we're looking for renewals or new sales.
	if ( $type !== 'all' ) {
		$sqlQuery .= "LEFT JOIN $wpdb->pmpro_membership_orders mo2 ON mo1.user_id = mo2.user_id
                        AND mo2.total > 0
                        AND mo2.status NOT IN('refunded', 'review', 'token', 'error')                                            
                        AND mo2.timestamp < mo1.timestamp
                        AND mo2.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";
	}
	
	// Get valid orders within the time frame.
	$sqlQuery .= "WHERE mo1.total > 0
				 	AND mo1.status NOT IN('refunded', 'review', 'token', 'error')
					AND mo1.timestamp >= '" . esc_sql( $startdate ) . "'
					AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";									

	// Restrict by level.
	if( ! empty( $levels ) && $levels != 'all' ) {
		$sqlQuery .= "AND mo1.membership_id IN(" . esc_sql( $levels ) . ") ";
	}		
	
	// Filter to renewals or new orders only. 	
	if ( $type == 'renewals' ) {
		$sqlQuery .= "AND mo2.id IS NOT NULL ";
	} elseif ( $type == 'new' ) {
		$sqlQuery .= "AND mo2.id IS NULL ";
	}

	// Group so we get one mo1 order per row.
	$sqlQuery .= "GROUP BY mo1.id ";

	// We want the count of rows produced, so update the query.
	$sqlQuery = "SELECT COUNT(*) FROM (" . $sqlQuery  . ") as t1";

	$sales = $wpdb->get_var($sqlQuery);

	//save in cache
	if(!empty($cache) && isset($cache[$param_hash])) {
		$cache[$param_hash][$levels] = (int)$sales;
	} elseif(!empty($cache))
		$cache[$param_hash] = array($levels => $sales);
	else
		$cache = array($param_hash => array($levels => $sales));

	set_transient( 'pmpro_report_sales', $cache, 3600*24 );

	return $sales;
}

/**
 * Gets an array of all prices paid in a time period
 *
 * @param  string $period Time period to query (today, this month, this year, all time)
 * @param  int    $count  Number of prices to query and return.
 */
function pmpro_get_prices_paid( $period, $count = NULL ) {
	// Check for a transient.
	$cache = get_transient( 'pmpro_report_prices_paid' );
	$param_hash = md5( $period . $count . PMPRO_VERSION );
	if ( ! empty( $cache ) && ! empty( $cache[$param_hash] ) ) {
		return $cache[$param_hash];
	}

	// A sale is an order with status NOT IN('refunded', 'review', 'token', 'error') with a total > 0.
	if ( 'today' === $period ) {
		$startdate = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
	} elseif ( 'this month' === $period ) {
		$startdate = date_i18n( 'Y-m', current_time( 'timestamp' ) ) . '-01';
	} elseif ( 'this year' === $period ) {
		$startdate = date_i18n( 'Y', current_time( 'timestamp' ) ) . '-01-01';
	} else {
		$startdate = '1970-01-01';
	}

	// Convert from local to UTC.
	$startdate = get_gmt_from_date( $startdate );

	$gateway_environment = pmpro_getOption( 'gateway_environment' );

	// Build query.
	global $wpdb;
	$sql_query = "SELECT ROUND(total,8) as rtotal, COUNT(*) as num FROM $wpdb->pmpro_membership_orders WHERE total > 0 AND status NOT IN('refunded', 'review', 'token', 'error') AND timestamp >= '" . esc_sql( $startdate ) . "' AND gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";

	// Restrict by level.
	if ( ! empty( $levels ) ) {
		$sql_query .= 'AND membership_id IN(' . esc_sql( $levels ) . ') ';
	}

	$sql_query .= ' GROUP BY rtotal ORDER BY num DESC ';

	$prices           = $wpdb->get_results( $sql_query );
	
	if( !empty( $count) ) {
		$prices = array_slice( $prices, 0, $count, true );
	}
	
	$prices_formatted = array();
	foreach ( $prices as $price ) {
		if ( isset( $price->rtotal ) ) {
			// Total sales.
			$sql_query = "SELECT COUNT(*)
						  FROM $wpdb->pmpro_membership_orders
						  WHERE ROUND(total, 8) = '" . esc_sql( $price->rtotal ) . "'
						  	AND status NOT IN('refunded', 'review', 'token', 'error')
							AND timestamp >= '" . esc_sql( $startdate ) . "'
							AND gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";
			$total = $wpdb->get_var( $sql_query );
			
			/* skipping this until we figure out how to make it performant
			// New sales.
			$sql_query = "SELECT mo1.id
						  FROM $wpdb->pmpro_membership_orders mo1
						  	LEFT JOIN $wpdb->pmpro_membership_orders mo2
								ON mo1.user_id = mo2.user_id
								AND mo2.total > 0
								AND mo2.status NOT IN('refunded', 'review', 'token', 'error')
								AND mo2.timestamp < mo1.timestamp
								AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "'
						   WHERE ROUND(mo1.total, 8) = '" . esc_sql( $price->rtotal ) . "'
						  	AND mo1.status NOT IN('refunded', 'review', 'token', 'error')
							AND mo1.timestamp >= '" . esc_sql( $startdate ) . "'
							AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "'
							AND mo2.id IS NULL
						  GROUP BY mo1.id ";
			$sql_query = "SELECT COUNT(*) FROM (" . $sql_query . ") as t1";			
			$new = $wpdb->get_var( $sql_query );
			
			// Renewals.			
			$sql_query = "SELECT mo1.id
						  FROM $wpdb->pmpro_membership_orders mo1
						  	LEFT JOIN $wpdb->pmpro_membership_orders mo2
								ON mo1.user_id = mo2.user_id
								AND mo2.total > 0
								AND mo2.status NOT IN('refunded', 'review', 'token', 'error')
								AND mo2.timestamp < mo1.timestamp
								AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "'
						   WHERE ROUND(mo1.total, 8) = '" . esc_sql( $price->rtotal ) . "'
						  	AND mo1.status NOT IN('refunded', 'review', 'token', 'error')
							AND mo1.timestamp >= '" . esc_sql( $startdate ) . "'
							AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "'
							AND mo2.id IS NOT NULL
						  GROUP BY mo1.id ";
			$sql_query = "SELECT COUNT(*) FROM (" . $sql_query . ") as t1";			
			$renewals = $wpdb->get_var( $sql_query );
			
			$prices_formatted[ $price->rtotal ] = array( 'total' => $total, 'new' => $new, 'renewals' => $renewals );
			*/
			$prices_formatted[ $price->rtotal ] = array( 'total' => $total );
		}
	}

	krsort( $prices_formatted );

	// Save in cache.
	if ( ! empty( $cache ) ) {
		$cache[$param_hash] = $prices_formatted;
	} else {
		$cache = array($param_hash => $prices_formatted );
	}

	set_transient( 'pmpro_report_prices_paid', $cache, 3600 * 24 );

	return $prices_formatted;
}

//get revenue
function pmpro_getRevenue( $period, $levels = NULL, $type = 'all' ) {
	//check for a transient
	$cache = get_transient("pmpro_report_revenue");
	$param_hash = md5( $period . ' ' . $type . PMPRO_VERSION );
	if(!empty($cache) && isset($cache[$param_hash]) && isset($cache[$param_hash][$levels]))
		return $cache[$param_hash][$levels];

	//a sale is an order with status NOT IN('refunded', 'review', 'token', 'error')
	if($period == "today")
		$startdate = date_i18n("Y-m-d", current_time('timestamp'));
	elseif($period == "this month")
		$startdate = date_i18n("Y-m", current_time('timestamp')) . "-01";
	elseif($period == "this year")
		$startdate = date_i18n("Y", current_time('timestamp')) . "-01-01";
	else
		$startdate = date_i18n("Y-m-d", 0);

	// Convert from local to UTC.
	$startdate = get_gmt_from_date( $startdate );

	$gateway_environment = pmpro_getOption("gateway_environment");

	// Build query.
	global $wpdb;
	$sqlQuery = "SELECT mo1.total as total
				 FROM $wpdb->pmpro_membership_orders mo1 ";

	// Need to join on older orders if we're looking for renewals or new sales.			
	if ( $type != 'all' ) {
		$sqlQuery .= "LEFT JOIN $wpdb->pmpro_membership_orders mo2
					 	ON mo1.user_id = mo2.user_id
						AND mo2.total > 0
						AND mo2.status NOT IN('refunded', 'review', 'token', 'error')
						AND mo2.timestamp < mo1.end_timestamp
						AND mo2.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";
	}
	
	// Get valid orders within the timeframe.		 
	$sqlQuery .= "WHERE mo1.status NOT IN('refunded', 'review', 'token', 'error')
				 	AND mo1.timestamp >= '" . esc_sql( $startdate ) . "'
					AND mo1.gateway_environment = '" . esc_sql( $gateway_environment ) . "' ";

	// Restrict by level.
	if(!empty($levels))
		$sqlQuery .= "AND mo1.membership_id IN(" . esc_sql( $levels ) . ") ";
		
	// Filter to renewals or new orders only. 	
	if ( $type == 'renewals' ) {
		$sqlQuery .= "AND mo2.id IS NOT NULL ";
	} elseif ( $type == 'new' ) {
		$sqlQuery .= "AND mo2.id IS NULL ";
	}

	// Group so we get one mo1 order per row.
	$sqlQuery .= "GROUP BY mo1.id ";
	
	// Want the total across the orders found.
	$sqlQuery = "SELECT SUM(total) FROM(" . $sqlQuery . ") as t1";
	
	$revenue = pmpro_round_price( $wpdb->get_var($sqlQuery) );

	//save in cache
	if(!empty($cache) && !empty($cache[$param_hash]))
		$cache[$param_hash][$levels] = $revenue;
	elseif(!empty($cache))
		$cache[$param_hash] = array($levels => $revenue);
	else
		$cache = array($param_hash => array($levels => $revenue));

	set_transient("pmpro_report_revenue", $cache, 3600*24);

	return $revenue;
}

/**
 * Get revenue between dates.
 *
 * @param  string $start_date to track revenue from.
 * @param  string $end_date to track revenue until. Defaults to current date. YYYY-MM-DD format.
 * @param  array  $level_ids to include in report. Defaults to all.
 * @return float  revenue.
 */
function pmpro_get_revenue_between_dates( $start_date, $end_date = '', $level_ids = null ) {
	global $wpdb;
	$sql_query = "SELECT SUM(total) FROM $wpdb->pmpro_membership_orders WHERE status NOT IN('refunded', 'review', 'token', 'error') AND timestamp >= '" . esc_sql( $start_date ) . " 00:00:00'";
	if ( ! empty( $end_date ) ) {
		$sql_query .= " AND timestamp <= '" . esc_sql( $end_date ) . " 23:59:59'";
	}
	if ( ! empty( $level_ids ) ) {
		$sql_query .= ' AND membership_id IN(' . implode( ', ', array_map( 'esc_sql', $level_ids ) ) . ') '; 
	}
	return $wpdb->get_var($sql_query);
}

//delete transients when an order goes through
function pmpro_report_sales_delete_transients()
{
	delete_transient( 'pmpro_report_sales' );
	delete_transient( 'pmpro_report_revenue' );
	delete_transient( 'pmpro_report_prices_paid' );
}
add_action("pmpro_after_checkout", "pmpro_report_sales_delete_transients");
add_action("pmpro_updated_order", "pmpro_report_sales_delete_transients");
add_action("pmpro_added_order", "pmpro_report_sales_delete_transients");