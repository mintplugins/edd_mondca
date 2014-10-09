<?php
/*
Plugin Name: Moneris Canada Direct Recurring for Easy Digital Downloads
Plugin URL: http://www.pastatech.com
Description: Easy Digital Downloads Plugin for accepting payment through Moneris Direct Canada Gateway.
Version: 1.0.2
Author: PatSaTECH
Author URI: http://www.pastatech.com
*/

// registers the gateway
function mondca_register_gateway($gateways) {
	$gateways['mondca'] = array('admin_label' => 'Moneris Direct Credit Card', 'checkout_label' => __('Moneris Credit Card', 'mondca_patsatech'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'mondca_register_gateway');

// processes the payment
function mondca_process_payment($purchase_data) {
	
    global $edd_options;
    
    // check there is a gateway name
    if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) )
    return;
    
    // collect payment data
    $payment_data = array( 
        'price'         => $purchase_data['price'], 
        'date'          => $purchase_data['date'], 
        'user_email'    => $purchase_data['user_email'], 
        'purchase_key'  => $purchase_data['purchase_key'], 
        'currency'      => edd_get_currency(),
        'downloads'     => $purchase_data['downloads'],
        'user_info'     => $purchase_data['user_info'],
        'cart_details'  => $purchase_data['cart_details'],
        'gateway'       => 'mondca',
        'status'        => 'pending'
     );
    
    if (!mondca_is_credit_card_number($purchase_data['post_data']['card_number']))
		edd_set_error( 'invalid_card_number', __('Credit Card Number is not valid.', 'mondca_patsatech') );
		
    if (!mondca_is_correct_expire_date(date("y", strtotime($purchase_data['post_data']['card_exp_month'])), $purchase_data['post_data']['card_exp_year']))
		edd_set_error( 'invalid_card_expiry', __('Card Expire Date is not valid.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['card_cvc'])
		edd_set_error( 'invalid_card_cvc', __('Card CVV is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['card_name'])
		edd_set_error( 'invalid_card_name', __('CardHolder Name is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['card_address'])
		edd_set_error( 'invalid_card_address', __('Billing Address is not entered.', 'mondca_patsatech') );
				
    if (!$purchase_data['post_data']['card_zip'])
		edd_set_error( 'invalid_card_zip', __('Post Code is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['card_state'])
		edd_set_error( 'invalid_card_state', __('State is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['card_city'])
		edd_set_error( 'invalid_card_city', __('City is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['edd_first'])
		edd_set_error( 'invalid_edd_first', __('First Name is not entered.', 'mondca_patsatech') );
		
    if (!$purchase_data['post_data']['edd_last'])
		edd_set_error( 'invalid_edd_last', __('Last Name is not entered.', 'mondca_patsatech') );
    
	$errors = edd_get_errors();
	
	
	if ( $errors ) {
        // problems? send back
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }else{
	
	    // record the pending payment
    	$payment = edd_insert_payment( $payment_data );
		
	    // check payment
	    if ( !$payment ) {
	        // problems? send back
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	    } else {
	        
			$store_id = $edd_options['mondca_storeid'];
			$api_token = $edd_options['mondca_apitoken'];
			
			//If this is a recurring payment
			if ( eddmondca_is_recurring_purchase( $purchase_data ) ) {
				
				//$cust_id = $order->order_key;
				$amount = number_format($trialAmount, 2, '.', '');
				$pan = $purchase_data['post_data']['card_number'];
				$cavv = $purchase_data['post_data']['card_cvc'];
				$expiry_date = substr($purchase_data['post_data']['card_exp_year'], -2).sprintf("%02s", $purchase_data['post_data']['card_exp_month']);
				$crypt = '7';
				$stamp = date("YdmHisB");
				$orderid = $stamp.'|'.$payment;

				/********************************* Recur Variables ****************************/
				
				$startNow = 'true';
				
				foreach( $purchase_data['downloads'] as $download ) {
					if( isset( $download['options'] ) && isset( $download['options']['recurring'] ) ) {

						// Set signup fee, if any
						if( ! empty( $download['options']['recurring']['signup_fee'] ) ) {
							$purchase_data['price'] -= $download['options']['recurring']['signup_fee'];
							$trialAmount = $download['options']['recurring']['signup_fee'] + $purchase_data['price'];
						}

						// Set the recurring amount
						$recurAmount  = $purchase_data['price'];
						
						// Set the recurring period
						switch( $download['options']['recurring']['period'] ) {
							case 'day' :
								$recurUnit = 'day';
							break;
							case 'week' :
								$recurUnit = 'week';
							break;
							case 'month' :
								$recurUnit = 'month';
							break;
							case 'year' :
								$recurUnit = 'year';
							break;
						}
						
						// One period unit (every week, every month, etc)
						$recurInterval = '1';
						
						// How many times should the payment recur?
						$times = intval( $download['options']['recurring']['times'] );
						
						switch( $times ) {
							// Unlimited
							case '0' :
								$numRecurs = '1';
								break;
							// Recur the number of times specified
							default :
								$numRecurs = $times;
								break;
						}
						
					}
					
				}
				
				$startDate = date('Y/m/d');
				
				if($trialAmount <= 0){
					$startDate = date('Y/m/d', strtotime($startDate. ' + 1 '.$recurUnit));
					$amount = number_format($recurAmount, 2, '.', '');
					--$numRecurs;
				}
				
				/*********************** Recur Associative Array **********************/
				
				$recurArray = array(
					'recur_unit'=>$recurUnit, // (day | week | month)
					'start_date'=>$startDate, //yyyy/mm/dd
					'num_recurs'=>$numRecurs,
					'start_now'=>$startNow,
					'period' => $recurInterval,
					'recur_amount'=> number_format($recurAmount, 2, '.', '')
				);
				
				$mpgRecur = new mpgRecur($recurArray);
				
				/*********************** Transactional Associative Array **********************/
				
				$txnArray = array(
					'type'=>$type,
					'order_id'=>$orderid,
					'cust_id'=>'',
					'amount'=>$amount,
					'pan'=>$pan,
					'expdate'=>$expiry_date,
					'crypt_type'=>$crypt,
					'cavv' => $cavv
				);
				
				/**************************** Transaction Object *****************************/
				
				$mpgTxn = new mpgTransaction($txnArray);
				
				/****************************** Recur Object *********************************/
				
				$mpgTxn->setRecur($mpgRecur);
				
				/****************************** Request Object *******************************/
				
				$mpgRequest = new mpgRequest($mpgTxn);
				
				/***************************** HTTPS Post Object *****************************/
				
				$mpgHttpPost  =new mpgHttpsPost($store_id,$api_token,$mpgRequest);
				
				/******************************* Response ************************************/
				
				$mpgResponse=$mpgHttpPost->getMpgResponse();
				
				$txnno = $mpgResponse->getTxnNumber();
				$receipt = explode("|",$mpgResponse->getReceiptId());
				$respcode = $mpgResponse->getResponseCode();
				$refnum = $mpgResponse->getReferenceNum();
				$auth = $mpgResponse->getAuthCode();
				$mess = $mpgResponse->getMessage();
				
				if($respcode < '50' && $respcode > '0'){
					
					edd_update_payment_status($payment, 'publish');
					
					edd_insert_payment_note( $order, sprintf( __('Moneris CA Payment %s. The SecurePay Transaction Id is %s', 'spxml_patsatech'), $mess, $txnno ) );
					
					edd_empty_cart();
					
					edd_send_to_success_page();
					
				}else{
					
					if ( strpos( $mess, 'DECLINED' ) === true ){
						$error_message = __('Transaction Error. ','mondca_patsatech')  . $mess . ' - ' . __( 'Sometimes this can occur if you don’t normally make large purchase online. You may need to confirm with your bank.','mondca_patsatech') . '<pre>' . print_r( $mpgTxn, TRUE ) . '</pre>';
					}
					else{
						$error_message = __('Transaction Error. ','mondca_patsatech')  . $mess . '<pre>' . print_r( $mpgTxn, TRUE ) . '</pre>';
					}
					
					edd_set_error( 'error_tranasction_failed', $error_message);
					
					edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
					
				}
				
			}else{
				
				$type = 'cavv_purchase';
				//$cust_id = $order->order_key;
				$amount = number_format($purchase_data['price'], 2, '.', '');
				$pan = $purchase_data['post_data']['card_number'];
				$cavv = $purchase_data['post_data']['card_cvc'];
				$expiry_date = substr($purchase_data['post_data']['card_exp_year'], -2).sprintf("%02s", $purchase_data['post_data']['card_exp_month']);
				$crypt = '7';
				$status_check = 'false';	
				$stamp = date("YdmHisB");
				$orderid = $stamp.'|'.$payment;
				
				/***************** Transactional Associative Array ********************/

				//$arr=explode("|",$teststring);
				$txnArray = array(
								'type' => $type,
				       			'order_id' => $orderid,
				       			'cust_id' => '',
				       			'amount' => $amount,
				       			'pan' => $pan,
				       			'expdate' => $expiry_date,
								'cavv' => $cavv
				          		);
				
				/********************** Transaction Object ****************************/
				
				$mpgTxn = new mpgTransaction($txnArray);
				
				/************************ Request Object ******************************/
				
				$mpgRequest = new mpgRequest($mpgTxn);
				
				/*********************** HTTPSPost Object ****************************/

				$mpgHttpPost = new mpgHttpsPost($store_id,$api_token,$mpgRequest);
				
				/*************************** Response *********************************/
				
				$mpgResponse = $mpgHttpPost->getMpgResponse();
				
				$txnno = $mpgResponse->getTxnNumber();
				$receipt = explode("|",$mpgResponse->getReceiptId());
				$respcode = $mpgResponse->getResponseCode();
				$refnum = $mpgResponse->getReferenceNum();
				$auth = $mpgResponse->getAuthCode();
				$mess = $mpgResponse->getMessage();
				
				
				if($respcode < '50' && $respcode > '0'){
					
					edd_update_payment_status($payment, 'publish');
					
					edd_insert_payment_note( $order, sprintf( __('Moneris CA Payment %s. The SecurePay Transaction Id is %s', 'spxml_patsatech'), $mess, $txnno ) );
					edd_empty_cart();
					
					edd_send_to_success_page();
					
				}else{
					
					edd_set_error( 'error_tranasction_failed', __('Transaction Error. '.$mess.'<pre>'.print_r($mpgTxn,TRUE).'</pre>', 'mondca_patsatech'));
					
					edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
					
				}
				
			}
								
	    }
		
	}
	
}
add_action('edd_gateway_mondca', 'mondca_process_payment');
 
function mondca_is_correct_expire_date($month, $year)
{
	$now       = time();
    $result    = false;
    $thisYear  = (int)date('y', $now);
    $thisMonth = (int)date('m', $now);

    if (is_numeric($year) && is_numeric($month))
    {
    	if($thisYear == (int)$year)
	    {
	    	$result = (int)$month >= $thisMonth;
		}			
		else if($thisYear < (int)$year)
		{
			$result = true;
		}
    }

	return $result;
}	

function mondca_is_credit_card_number($toCheck){
	if (!is_numeric($toCheck))
    	return false;

	$number = preg_replace('/[^0-9]+/', '', $toCheck);
    $strlen = strlen($number);
    $sum    = 0;

    if ($strlen < 13)
    	return false;

	for ($i=0; $i < $strlen; $i++)
    {
    	$digit = substr($number, $strlen - $i - 1, 1);
        if($i % 2 == 1)
        {
        	$sub_total = $digit * 2;
            if($sub_total > 9)
            {
            	$sub_total = 1 + ($sub_total - 10);
			}
		}
        else
        {
        	$sub_total = $digit;
		}
        $sum += $sub_total;
	}

    if ($sum > 0 AND $sum % 10 == 0)
    	return true;

	return false;
}
 
function mondca_add_settings($settings) {
 
	$mondca_settings = array(
		array(
			'id' => 'mondca_settings',
			'name' => '<strong>' . __('eWAY AU Direct Settings', 'mondca_patsatech') . '</strong>',
			'desc' => __('Configure the gateway settings', 'mondca_patsatech'),
			'type' => 'header'
		),
		array(
			'id' => 'mondca_storeid',
			'name' => __('Moneris Store ID', 'mondca_patsatech'),
			'desc' => __('Please enter your Moneris Store ID; this is needed in order to take payment.', 'mondca_patsatech'),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'mondca_apitoken',
			'name' => __('Moneris Token Key', 'mondca_patsatech'),
			'desc' => __('Please enter your Moneris Token Key; this is needed in order to take payment.', 'mondca_patsatech'),
			'type' => 'text',
			'size' => 'regular'
		)
	);
 
	return array_merge($settings, $mondca_settings);	
}
add_filter('edd_settings_gateways', 'mondca_add_settings');

// Process Moneris subscription sign ups
add_action( 'edd_mondca_subscr_signup', array( 'EDD_Recurring_Moneris_IPN', 'process_mondca_subscr_signup' ) );

// Process Moneris subscription payments
add_action( 'edd_mondca_subscr_payment', array( 'EDD_Recurring_Moneris_IPN', 'process_mondca_subscr_payment' ) );

// Process Moneris subscription cancellations
add_action( 'edd_mondca_subscr_cancel', array( 'EDD_Recurring_Moneris_IPN', 'process_mondca_subscr_cancel' ) );

// Process Moneris subscription end of term notices
add_action( 'edd_mondca_subscr_eot', array( 'EDD_Recurring_Moneris_IPN', 'process_mondca_subscr_eot' ) );


class EDD_Recurring_Moneris_IPN {

	/**
	 * Processes the "signup" IPN notice
	 *
	 * @since  1.0
	 * @return void
	 */

	static public function process_mondca_subscr_signup( $ipn_data ) {

		$parent_payment_id = absint( $ipn_data['custom'] );

		edd_update_payment_status( $parent_payment_id, 'publish' );

		// Record transaction ID
		edd_insert_payment_note( $parent_payment_id, sprintf( __( 'Moneris Subscription ID: %s', 'edd' ) , $ipn_data['subscr_id'] ) );

		// Store the IPN track ID
		update_post_meta( $parent_payment_id, '_edd_recurring_ipn_track_id', $ipn_data['ipn_track_id'] );

		$user_id   = edd_get_payment_user_id( $ipn_data['custom'] );

		// Set user as subscriber
		EDD_Recurring_Customer::set_as_subscriber( $user_id );

		// store the customer recurring ID
		EDD_Recurring_Customer::set_customer_id( $user_id, $ipn_data['payer_id'] );

		// Store the original payment ID in the customer meta
		EDD_Recurring_Customer::set_customer_payment_id( $user_id, $ipn_data['custom'] );

		// Set the customer's status to active
		EDD_Recurring_Customer::set_customer_status( $user_id, 'active' );

		// Calculate the customer's new expiration date
		$new_expiration = EDD_Recurring_Customer::calc_user_expiration( $user_id, $parent_payment_id );

		// Set the customer's new expiration date
		EDD_Recurring_Customer::set_customer_expiration( $user_id, $new_expiration );

	}

	/**
	 * Processes the recurring payments as they come in
	 *
	 * @since  1.0
	 * @return void
	 */

	static public function process_mondca_subscr_payment( $ipn_data ) {

		global $edd_options;

		$parent_payment_id = absint( $ipn_data['custom'] );
		$parent_payment    = get_post( $parent_payment_id );

		if( empty( $parent_payment_id ) || ! $parent_payment ) {
			return; // No parent payment
		}

		$signup_date = get_post_field( 'post_date', $parent_payment_id );

		if( empty( $signup_date ) ) {
			return;
		}

		$signup_date  = new DateTime( $signup_date );
		$payment_date = new DateTime( $ipn_data['payment_date'] );

		if( $signup_date->format( 'Y-m-d' ) == $payment_date->format( 'Y-m-d' ) ) {
			return; // This is the initial payment
		}

		$payment_amount    = $ipn_data['mc_gross'];
		$currency_code     = strtolower( $ipn_data['mc_currency'] );
		$user_id           = edd_get_payment_user_id( $parent_payment_id );
		// verify details
		if( $currency_code != strtolower( $edd_options['currency'] ) ) {
			// the currency code is invalid
			edd_record_gateway_error( __( 'IPN Error', 'edd' ), sprintf( __( 'Invalid currency in IPN response. IPN data: ', 'edd' ), json_encode( $ipn_data ) ) );
			return;
		}

		$key = md5( serialize( $ipn_data ) );

		// Store the payment
		EDD_Recurring()->record_subscription_payment( $parent_payment_id, $payment_amount, $ipn_data['txn_id'], $key );

		// Set the customer's status to active
		EDD_Recurring_Customer::set_customer_status( $user_id, 'active' );

		// Calculate the customer's new expiration date
		$new_expiration = EDD_Recurring_Customer::calc_user_expiration( $user_id, $parent_payment_id );

		// Set the customer's new expiration date
		EDD_Recurring_Customer::set_customer_expiration( $user_id, $new_expiration );

	}

	/**
	 * Processes the "cancel" IPN notice
	 *
	 * @since  1.0
	 * @return void
	 */

	static public function process_mondca_subscr_cancel( $ipn_data ) {

		$user_id = edd_get_payment_user_id( $ipn_data['custom'] );

		// set the customer status
		//EDD_Recurring_Customer::set_customer_status( $user_id, 'cancelled' );

		// Set the payment status to cancelled
		edd_update_payment_status( $ipn_data['custom'], 'cancelled' );

	}

	/**
	 * Processes the "end of term (eot)" IPN notice
	 *
	 * @since  1.0
	 * @return void
	 */

	static public function process_mondca_subscr_eot( $ipn_data ) {

		$user_id   = edd_get_payment_user_id( $ipn_data['custom'] );

		// set the customer status
		EDD_Recurring_Customer::set_customer_status( $user_id, 'expired' );

	}

}

#################### mpgGlobals ###########################################


class mpgGlobals{

 function getGlobals()
 {
    global $edd_options;
    
	if ( edd_is_test_mode() ):
		$monurl = 'esqa.moneris.com';
	else :
		$monurl = 'www3.moneris.com';
	endif;

 	$Globals=array(
                  'MONERIS_PROTOCOL' => 'https',
                  'MONERIS_HOST' => $monurl,
                  'MONERIS_PORT' =>'443',
                  'MONERIS_FILE' => '/gateway2/servlet/MpgRequest',
                  'API_VERSION'  =>'PHP - 2.5.0',
                  'CLIENT_TIMEOUT' => '60'
                 );;
  return($Globals);
 }

}//end class mpgGlobals



###################### mpgHttpsPost #########################################

class mpgHttpsPost{

 var $api_token;
 var $store_id;
 var $mpgRequest;
 var $mpgResponse;

 function mpgHttpsPost($store_id,$api_token, $mpgRequestOBJ)
 {

  $this->store_id=$store_id;
  $this->api_token= $api_token;
  $this->mpgRequest=$mpgRequestOBJ;

  $dataToSend=$this->toXML();
  //echo "DATA TO SEND = $dataToSend\n\n";

  //do post

  $g=new mpgGlobals();
  $gArray=$g->getGlobals();

  $url=$gArray['MONERIS_PROTOCOL']."://".
       $gArray['MONERIS_HOST'].":".
       $gArray['MONERIS_PORT'].
       $gArray['MONERIS_FILE'];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL,$url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt ($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS,$dataToSend);
  curl_setopt($ch,CURLOPT_TIMEOUT,$gArray['CLIENT_TIMEOUT']);
  curl_setopt($ch,CURLOPT_USERAGENT,$gArray['API_VERSION']);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);


  $response=curl_exec ($ch);

  curl_close ($ch);

  //echo "RESPONSE = $response\n\n";

  if(!$response)
   {

     $response="<?xml version=\"1.0\"?><response><receipt>".
          "<ReceiptId>Global Error Receipt</ReceiptId>".
          "<ReferenceNum>null</ReferenceNum><ResponseCode>null</ResponseCode>".
          "<ISO>null</ISO> <AuthCode>null</AuthCode><TransTime>null</TransTime>".
          "<TransDate>null</TransDate><TransType>null</TransType><Complete>false</Complete>".
          "<Message>null</Message><TransAmount>null</TransAmount>".
          "<CardType>null</CardType>".
          "<TransID>null</TransID><TimedOut>null</TimedOut>".
          "</receipt></response>";
   }

  $this->mpgResponse=new mpgResponse($response);

 }



  function getMpgResponse()
 {
  return $this->mpgResponse;

 }

 function toXML( )
 {

  $req=$this->mpgRequest ;
  $reqXMLString=$req->toXML();

  $xmlString='';
  $xmlString .="<?xml version=\"1.0\"?>".
               "<request>".
               "<store_id>$this->store_id</store_id>".
               "<api_token>$this->api_token</api_token>".
                $reqXMLString.
                "</request>";

  return ($xmlString);

 }

}//end class mpgHttpsPost

###################### mpgHttpsPostStatus #########################################

class mpgHttpsPostStatus{

 var $api_token;
 var $store_id;
 var $status;
 var $mpgRequest;
 var $mpgResponse;

 function mpgHttpsPostStatus($store_id,$api_token,$status, $mpgRequestOBJ)
 {

  $this->store_id=$store_id;
  $this->api_token= $api_token;
  $this->status=$status;
  $this->mpgRequest=$mpgRequestOBJ;

  $dataToSend=$this->toXML();
  //echo "DATA TO SEND = $dataToSend\n\n";

  //do post

  $g=new mpgGlobals();
  $gArray=$g->getGlobals();

  $url=$gArray['MONERIS_PROTOCOL']."://".
       $gArray['MONERIS_HOST'].":".
       $gArray['MONERIS_PORT'].
       $gArray['MONERIS_FILE'];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL,$url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt ($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS,$dataToSend);
  curl_setopt($ch,CURLOPT_TIMEOUT,$gArray['CLIENT_TIMEOUT']);
  curl_setopt($ch,CURLOPT_USERAGENT,$gArray['API_VERSION']);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, TRUE);


  $response=curl_exec ($ch);

  curl_close ($ch);

  //echo "RESPONSE = $response\n\n";

  if(!$response)
   {

     $response="<?xml version=\"1.0\"?><response><receipt>".
          "<ReceiptId>Global Error Receipt</ReceiptId>".
          "<ReferenceNum>null</ReferenceNum><ResponseCode>null</ResponseCode>".
          "<ISO>null</ISO> <AuthCode>null</AuthCode><TransTime>null</TransTime>".
          "<TransDate>null</TransDate><TransType>null</TransType><Complete>false</Complete>".
          "<Message>null</Message><TransAmount>null</TransAmount>".
          "<CardType>null</CardType>".
          "<TransID>null</TransID><TimedOut>null</TimedOut>".
          "</receipt></response>";
   }

  $this->mpgResponse=new mpgResponse($response);

 }



  function getMpgResponse()
 {
  return $this->mpgResponse;

 }

 function toXML( )
 {

  $req=$this->mpgRequest ;
  $reqXMLString=$req->toXML();

  $xmlString='';
  $xmlString .="<?xml version=\"1.0\"?>".
               "<request>".
               "<store_id>$this->store_id</store_id>".
               "<api_token>$this->api_token</api_token>".
               "<status_check>$this->status</status_check>".
                $reqXMLString.
                "</request>";

  return ($xmlString);

 }

}//end class mpgHttpsPostStatus



############# mpgResponse #####################################################


class mpgResponse{

 var $responseData;

 var $p; //parser

 var $currentTag;
 var $purchaseHash = array();
 var $refundHash;
 var $correctionHash = array();
 var $isBatchTotals;
 var $term_id;
 var $receiptHash = array();
 var $ecrHash = array();
 var $CardType;
 var $currentTxnType;
 var $ecrs = array();
 var $cards = array();
 var $cardHash= array();

 var $ACSUrl;

 function mpgResponse($xmlString)
 {
  $this->p = xml_parser_create();
  xml_parser_set_option($this->p,XML_OPTION_CASE_FOLDING,0);
  xml_parser_set_option($this->p,XML_OPTION_TARGET_ENCODING,"UTF-8");
  xml_set_object($this->p,$this);
  xml_set_element_handler($this->p,"startHandler","endHandler");
  xml_set_character_data_handler($this->p,"characterHandler");
  xml_parse($this->p,$xmlString);
  xml_parser_free($this->p);

 }//end of constructor


 function getMpgResponseData(){
   return($this->responseData);
 }


function getAvsResultCode()	{
	return ($this->responseData['AvsResultCode']);
}

function getCvdResultCode()	{
	return ($this->responseData['CvdResultCode']);
}

function getCavvResultCode()	{
	return ($this->responseData['CavvResultCode']);
}

function getCardLevelResult()	{
	return ($this->responseData['CardLevelResult']);
}

function getITDResponse()	{
	return ($this->responseData['ITDResponse']);
}

function getStatusCode()	{
	return ($this->responseData['status_code']);
}

function getStatusMessage()	{
	return ($this->responseData['status_message']);
}


function getRecurSuccess(){
	return ($this->responseData['RecurSuccess']);
}

function getCardType(){
	return ($this->responseData['CardType']);
}

function getTransAmount(){
	return ($this->responseData['TransAmount']);
}

function getTxnNumber(){
	return ($this->responseData['TransID']);
}

function getReceiptId(){
	return ($this->responseData['ReceiptId']);
}

function getTransType(){
	return ($this->responseData['TransType']);
}

function getReferenceNum(){
	return ($this->responseData['ReferenceNum']);
}

function getResponseCode(){
	return ($this->responseData['ResponseCode']);
}

function getISO(){
	return ($this->responseData['ISO']);
}

function getBankTotals(){
	return ($this->responseData['BankTotals']);
}

function getMessage(){
	return ($this->responseData['Message']);
}

function getAuthCode(){
	return ($this->responseData['AuthCode']);
}

function getComplete(){
	return ($this->responseData['Complete']);
}

function getTransDate(){
	return ($this->responseData['TransDate']);
}

function getTransTime(){
	return ($this->responseData['TransTime']);
}

function getTicket(){
	return ($this->responseData['Ticket']);
}

function getTimedOut(){
	return ($this->responseData['TimedOut']);
}

function getRecurUpdateSuccess(){
	return ($this->responseData['RecurUpdateSuccess']);
}

function getNextRecurDate(){
	return ($this->responseData['NextRecurDate']);
}

function getRecurEndDate(){
	return ($this->responseData['RecurEndDate']);
}

function getTerminalStatus($ecr_no){
	return ($this->ecrHash[$ecr_no]);
}

function getPurchaseAmount($ecr_no,$card_type){

 return ($this->purchaseHash[$ecr_no][$card_type]['Amount']=="" ? 0:$this->purchaseHash[$ecr_no][$card_type]['Amount']);
}

function getPurchaseCount($ecr_no,$card_type){

 return ($this->purchaseHash[$ecr_no][$card_type]['Count']=="" ? 0:$this->purchaseHash[$ecr_no][$card_type]['Count']);
}

function getRefundAmount($ecr_no,$card_type){

 return ($this->refundHash[$ecr_no][$card_type]['Amount']=="" ? 0:$this->refundHash[$ecr_no][$card_type]['Amount']);
}

function getRefundCount($ecr_no,$card_type){

 return ($this->refundHash[$ecr_no][$card_type]['Count']=="" ? 0:$this->refundHash[$ecr_no][$card_type]['Count']);
}

function getCorrectionAmount($ecr_no,$card_type){

 return ($this->correctionHash[$ecr_no][$card_type]['Amount']=="" ? 0:$this->correctionHash[$ecr_no][$card_type]['Amount']);
}

function getCorrectionCount($ecr_no,$card_type){

 return ($this->correctionHash[$ecr_no][$card_type]['Count']=="" ? 0:$this->correctionHash[$ecr_no][$card_type]['Count']);
}

function getTerminalIDs(){
	return ($this->ecrs);
}

function getCreditCardsAll(){
	return (array_keys($this->cards));
}

function getCreditCards($ecr_no){
	return ($this->cardHash[$ecr_no]);
}

function characterHandler($parser,$data){


 if($this->isBatchTotals)
 {
   switch($this->currentTag)
    {
     case "term_id"    : {
     					  $this->term_id=$data;
                          array_push($this->ecrs,$this->term_id);
                          $this->cardHash[$data]=array();
                          break;
                         }

     case "closed"     : {
     					  $ecrHash=$this->ecrHash;
                          $ecrHash[$this->term_id]=$data;
                          $this->ecrHash = $ecrHash;
                          break;
                         }

     case "CardType"   : {
     					  $this->CardType=$data;
                          $this->cards[$data]=$data;
                          array_push($this->cardHash[$this->term_id],$data) ;
                          break;
                         }

     case "Amount"     : {
                          if($this->currentTxnType == "Purchase")
                            {
                             $this->purchaseHash[$this->term_id][$this->CardType]['Amount']=$data;
                            }
                           else if( $this->currentTxnType == "Refund")
                            {
                              $this->refundHash[$this->term_id][$this->CardType]['Amount']=$data;
                            }

                           else if( $this->currentTxnType == "Correction")
                            {
                              $this->correctionHash[$this->term_id][$this->CardType]['Amount']=$data;
                            }
                           break;
                         }

    case "Count"     : {
                          if($this->currentTxnType == "Purchase")
                            {
                             $this->purchaseHash[$this->term_id][$this->CardType]['Count']=$data;
                            }
                           else if( $this->currentTxnType == "Refund")
                            {
                              $this->refundHash[$this->term_id][$this->CardType]['Count']=$data;

                            }

                           else if( $this->currentTxnType == "Correction")
                            {
                              $this->correctionHash[$this->term_id][$this->CardType]['Count']=$data;
                            }
                          break;
                         }
    }
 }
 else
 {
    @$this->responseData[$this->currentTag] .=$data;
 }

}//end characterHandler



function startHandler($parser,$name,$attrs){

  $this->currentTag=$name;

  if($this->currentTag == "BankTotals")
   {
    $this->isBatchTotals=1;
   }
  else if($this->currentTag == "Purchase")
   {
    $this->purchaseHash[$this->term_id][$this->CardType]=array();
    $this->currentTxnType="Purchase";
   }
  else if($this->currentTag == "Refund")
   {
    $this->refundHash[$this->term_id][$this->CardType]=array();
    $this->currentTxnType="Refund";
   }
  else if($this->currentTag == "Correction")
   {
    $this->correctionHash[$this->term_id][$this->CardType]=array();
    $this->currentTxnType="Correction";
   }

}


function endHandler($parser,$name){

 $this->currentTag=$name;
 if($name == "BankTotals")
   {
    $this->isBatchTotals=0;
   }

 $this->currentTag="/dev/null";
}


}//end class mpgResponse


################## mpgRequest ###########################################################

class mpgRequest{

 var $txnTypes =array('purchase'=> array('order_id','cust_id', 'amount', 'pan', 'expdate', 'crypt_type', 'dynamic_descriptor'),
                      'refund' => array('order_id', 'amount', 'txn_number', 'crypt_type'),
					  'idebit_purchase'=>array('order_id', 'cust_id', 'amount','idebit_track2', 'dynamic_descriptor'),
					  'idebit_refund'=>array('order_id','amount','txn_number'),
					  'purchase_reversal'=>array('order_id','amount'),
					  'refund_reversal'=>array('order_id','amount'),
                      'ind_refund' => array('order_id','cust_id', 'amount','pan','expdate', 'crypt_type', 'dynamic_descriptor'),
                      'preauth' =>array('order_id','cust_id', 'amount', 'pan', 'expdate', 'crypt_type', 'dynamic_descriptor'),
                      'reauth' =>array('order_id','cust_id', 'amount', 'orig_order_id', 'txn_number', 'crypt_type'),
                      'completion' => array('order_id', 'comp_amount','txn_number', 'crypt_type'),
                      'purchasecorrection' => array('order_id', 'txn_number', 'crypt_type'),
                      'opentotals' => array('ecr_number'),
                      'batchclose' => array('ecr_number'),
                      'cavv_purchase'=> array('order_id','cust_id', 'amount', 'pan', 'expdate', 'cavv', 'dynamic_descriptor'),
                      'cavv_preauth' =>array('order_id','cust_id', 'amount', 'pan', 'expdate', 'cavv', 'dynamic_descriptor'),
					  'card_verification' =>array('order_id','cust_id','pan','expdate'),
                      'recur_update' => array('order_id', 'cust_id', 'pan', 'expdate', 'recur_amount',
                      					'add_num_recurs', 'total_num_recurs', 'hold', 'terminate')

                    );
var $txnArray;

function mpgRequest($txn){

 	if(is_array($txn))
 	{
 	   $txn=$txn[0];
 	}

 	$this->txnArray=$txn;

}

function toXML(){

 	$tmpTxnArray=$this->txnArray;

 	$txnArrayLen=count($tmpTxnArray); //total number of transactions

    $txnObj=$tmpTxnArray;

    $txn=$txnObj->getTransaction();	//call to a non-member function

    $txnType=array_shift($txn);
    $tmpTxnTypes=$this->txnTypes;
    $txnTypeArray=$tmpTxnTypes[$txnType];
    $txnTypeArrayLen=count($txnTypeArray); //length of a specific txn type

    $txnXMLString="";
    for($i=0;$i < $txnTypeArrayLen ;$i++)
	{
		 $txnXMLString  .="<$txnTypeArray[$i]>"   //begin tag
		                  .$txn[$txnTypeArray[$i]] // data
		                  . "</$txnTypeArray[$i]>"; //end tag
	}

	$txnXMLString = "<$txnType>$txnXMLString";

	$recur  = $txnObj->getRecur();
	if($recur != null)
	{
	     $txnXMLString .= $recur->toXML();
	}

	$avsInfo  = $txnObj->getAvsInfo();
	if($avsInfo != null)
	{
		 $txnXMLString .= $avsInfo->toXML();
	}

   	$cvdInfo  = $txnObj->getCvdInfo();
    if($cvdInfo != null)
   	{
   		 $txnXMLString .= $cvdInfo->toXML();
   	}


	$custInfo = $txnObj->getCustInfo();
	if($custInfo != null)
	{
         $txnXMLString .= $custInfo->toXML();
	}

    $txnXMLString .="</$txnType>";

    $xmlString .=$txnXMLString;

	return $xmlString;

 }//end toXML

}//end class


##################### mpgCustInfo #######################################################

class mpgCustInfo{


 var $level3template = array('cust_info'=>

           array('email','instructions',
                 'billing' => array ('first_name', 'last_name', 'company_name', 'address',
                                    'city', 'province', 'postal_code', 'country',
                                    'phone_number', 'fax','tax1', 'tax2','tax3',
                                    'shipping_cost'),
                 'shipping' => array('first_name', 'last_name', 'company_name', 'address',
                                   'city', 'province', 'postal_code', 'country',
                                   'phone_number', 'fax','tax1', 'tax2', 'tax3',
                                   'shipping_cost'),
                 'item'   => array ('name', 'quantity', 'product_code', 'extended_amount')
                )
           );

 var $level3data;
 var $email;
 var $instructions;

 function mpgCustInfo($custinfo=0,$billing=0,$shipping=0,$items=0)
 {
  if($custinfo)
   {
    $this->setCustInfo($custinfo);
   }
 }

 function setCustInfo($custinfo)
 {
  $this->level3data['cust_info']=array($custinfo);
 }


 function setEmail($email){

   $this->email=$email;
   $this->setCustInfo(array('email'=>$email,'instructions'=>$this->instructions));
 }

 function setInstructions($instructions){

   $this->instructions=$instructions;
   $this->setCustinfo(array('email'=>$this->email,'instructions'=>$instructions));
 }

 function setShipping($shipping)
 {
  $this->level3data['shipping']=array($shipping);
 }

 function setBilling($billing)
 {
  $this->level3data['billing']=array($billing);
 }

 function setItems($items)
 {
   if(!isset($this->level3data['item']))
    {
     $this->level3data['item']=array($items);
    }
   else
    {
     $index=count($this->level3data['item']);
     $this->level3data['item'][$index]=$items;
    }
 }

 function toXML()
 {
  $xmlString=$this->toXML_low($this->level3template,"cust_info");
  return $xmlString;
 }

 function toXML_low($template,$txnType)
 {

  for($x=0;$x<count($this->level3data[$txnType]);$x++)
   {
     if($x>0)
     {
      $xmlString .="<$txnType><$txnType>";
     }
     $keys=array_keys($template);
     for($i=0; $i < count($keys);$i++)
     {
        $tag=$keys[$i];

        if(is_array($template[$keys[$i]]))
        {
          $data=$template[$tag];

          if(! count($this->level3data[$tag]))
           {
            continue;
           }
          $beginTag="<$tag>";
          $endTag="</$tag>";

		  $xmlString .=$beginTag;
          if(is_array($data))
           {
            $returnString=$this->toXML_low($data,$tag);
            $xmlString .= $returnString;
           }
          $xmlString .=$endTag;
        }
        else
        {
         $tag=$template[$keys[$i]];
         $beginTag="<$tag>";
         $endTag="</$tag>";
         $data=$this->level3data[$txnType][$x][$tag];

         $xmlString .=$beginTag.$data.$endTag;
        }

     }//end inner for

    }//end outer for

    return $xmlString;
 }//end toXML_low

}//end class

#########################################mpgRecur################################################

class mpgRecur{

 var $params;
 var $recurTemplate = array('recur_unit','start_now','start_date','num_recurs','period','recur_amount');

 function mpgRecur($params)
 {
    $this->params = $params;

    if( (! $this->params['period']) )
    {
      $this->params['period'] = 1;
    }
 }

 function toXML()
 {
   foreach($this->recurTemplate as $tag)
   {
     $xmlString .= "<$tag>". $this->params[$tag] ."</$tag>";
   }

   return "<recur>$xmlString</recur>";
 }

}//end class

##################### mpgTransaction #######################################################

class mpgTransaction{

 var $txn;
 var $custInfo = null;
 var $avsInfo = null;
 var $cvdInfo = null;
 var $recur = null;

 function mpgTransaction($txn){

  $this->txn=$txn;

 }

function getCustInfo()
{
	return $this->custInfo;
}
function setCustInfo($custInfo){
	$this->custInfo = $custInfo;
 	array_push($this->txn,$custInfo);
}

function getCvdInfo()
{
	return $this->cvdInfo;
}
function setCvdInfo($cvdInfo)
{
	$this->cvdInfo = $cvdInfo;
}

function getAvsInfo()
{
	return $this->avsInfo;
}
function setAvsInfo($avsInfo)
{
	$this->avsInfo = $avsInfo;
}

function getRecur()
{
	return $this->recur;
}
function setRecur($recur)
{
	$this->recur = $recur;
}

function getTransaction(){

 return $this->txn;
}

}//end class

##################### mpgAvsInfo #######################################################

class mpgAvsInfo
{

	var $params;
	var $avsTemplate = array('avs_street_number','avs_street_name','avs_zipcode','avs_email','avs_hostname','avs_browser','avs_shiptocountry','avs_shipmethod','avs_merchprodsku','avs_custip','avs_custphone');

	function mpgAvsInfo($params)
	{
		$this->params = $params;
	}

	function toXML()
	{
		foreach($this->avsTemplate as $tag)
		{
			$xmlString .= "<$tag>". $this->params[$tag] ."</$tag>";
		}

		return "<avs_info>$xmlString</avs_info>";
	}

}//end class

##################### mpgCvdInfo #######################################################

class mpgCvdInfo
{

	var $params;
	var $cvdTemplate = array('cvd_indicator','cvd_value');

	function mpgCvdInfo($params)
	{
		$this->params = $params;
	}

	function toXML()
	{
		foreach($this->cvdTemplate as $tag)
		{
			$xmlString .= "<$tag>". $this->params[$tag] ."</$tag>";
		}

		return "<cvd_info>$xmlString</cvd_info>";
	}

}//end class