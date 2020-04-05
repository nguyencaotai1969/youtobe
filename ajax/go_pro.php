<?php 
if (IS_LOGGED == false) {
    $data = array(
        'status' => 400,
        'error' => 'Not logged in'
    );
    echo json_encode($data);
    exit();
}

elseif((!PT_IsAdmin() && ($pt->config->go_pro != 'on' || PT_IsUpgraded())) && $pt->config->sell_videos_system != 'on' && $pt->config->rent_videos_system != 'on'){
	$data = array(
        'status' => 400,
        'error' => 'Bad request'
    );
    echo json_encode($data);
    exit();
}
require './assets/import/PayPal/vendor/paypal/rest-api-sdk-php/sample/common.php';
use PayPal\Api\ChargeModel;
use PayPal\Api\Currency;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\Plan;
use PayPal\Api\Agreement;
use PayPal\Api\ShippingAddress;
use PayPal\Api\AgreementDetails;


use PayPal\Api\Payer;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Details;
use PayPal\Api\Amount;
use PayPal\Api\Transaction;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;


$payment_currency = $pt->config->payment_currency;
$paypal_currency = $pt->config->paypal_currency;
$payer        = new Payer();
$item         = new Item();
$itemList     = new ItemList();
$details      = new Details();
$amount       = new Amount();
$transaction  = new Transaction();
$redirectUrls = new RedirectUrls();
$payment      = new Payment();
$pkgs         = array('pro');
$payer->setPaymentMethod('paypal');
$sum          = intval($pt->config->pro_pkg_price);


if (!empty($_GET['first']) && $_GET['first'] == 'purchase') {

	if (!empty($_POST['type']) && $_POST['type'] == 'pro') {
		if ($pt->config->recurring_payment == 'off') {
			$redirectUrls->setReturnUrl(PT_Link('aj/go_pro/get_paid?status=success&pkg=pro'))->setCancelUrl(PT_Link(''));    
		    $item->setName('Purchase pro package')->setQuantity(1)->setPrice($sum)->setCurrency($paypal_currency);  
		    $itemList->setItems(array($item));    
		    $details->setSubtotal($sum);
		    $amount->setCurrency($paypal_currency)->setTotal($sum)->setDetails($details);
		    $transaction->setAmount($amount)->setItemList($itemList)->setDescription('Purchase pro package')->setInvoiceNumber(time());
		    $payment->setIntent('sale')->setPayer($payer)->setRedirectUrls($redirectUrls)->setTransactions(array(
		        $transaction
		    ));

		    try {
		        $payment->create($paypal);
		    }

		    catch (Exception $e) {
		        $data = array(
		            'type' => 'ERROR',
		            'details' => json_decode($e->getData())
		        );

		        if (empty($data['details'])) {
		            $data['details'] = json_decode($e->getCode());
		        }
		        echo json_encode($data);
		    	exit();
		    }

		    $data = array(
		        'status' => 200,
		        'type' => 'SUCCESS',
		        'url' => $payment->getApprovalLink()
		    );
		}
		else{
			$plan = new \PayPal\Api\Plan();
            $plan->setName('Purchase pro package user'.$pt->user->id)
              ->setDescription('Purchase pro package user'.$pt->user->id)
              ->setType('fixed');

                        // Set billing plan definitions
            $paymentDefinition = new \PayPal\Api\PaymentDefinition();
            $paymentDefinition->setName('Regular Payments user'.$pt->user->id)
              ->setType('REGULAR')
              ->setFrequency('Month')
              ->setFrequencyInterval('1')
              ->setCycles('48')
              ->setAmount(new \PayPal\Api\Currency(array('value' => $sum, 'currency' => $paypal_currency)));

            $merchantPreferences = new \PayPal\Api\MerchantPreferences();
            $merchantPreferences->setReturnUrl(PT_Link('aj/go_pro/get_paid?status=success&pkg=pro'))
                ->setCancelUrl(PT_Link(''))
                ->setAutoBillAmount('yes')
                ->setInitialFailAmountAction('CONTINUE')
                ->setMaxFailAttempts('0')
                ->setSetupFee(
                    new PayPal\Api\Currency(
                        array(
                            'currency' => $paypal_currency,
                            'value' => $sum,
                        )
                    )
                );

            $plan->setPaymentDefinitions(array($paymentDefinition));
            $plan->setMerchantPreferences($merchantPreferences);

            try {
                $output = $plan->create($paypal);
                
            } catch (Exception $ex) {
                //ResultPrinter::printError("Created Plan", "Plan", null, $request, $ex);
                
            }
            // ResultPrinter::printResult("Created Plan", "Plan", $output->getId(), $request, $output);
            // exit();	
            $p_currency = !empty($pt->config->currency_symbol_array[$pt->config->paypal_currency]) ? $pt->config->currency_symbol_array[$pt->config->paypal_currency] : '$';

			// if ($pt->config->payment_currency == 'EUR') {
			// 	$p_currency    = '€';
			// }

            $patch = new \PayPal\Api\Patch();
            $patch->setOp('replace')
                ->setPath('/')
                ->setValue(new \PayPal\Common\PayPalModel('{
                    "state": "ACTIVE"
                }'));

            $patchRequest = new \PayPal\Api\PatchRequest();
            $patchRequest->addPatch($patch);

            $resActivate = $plan->update($patchRequest, $paypal);


            // Create new agreement

            // Create new agreement
            $plan->setState('ACTIVE');
            $agreement = new Agreement();
            $agreement->setName('Purchase Pro package user'.$pt->user->id)
              ->setDescription('Upgrade to Pro Member - '.$p_currency.''.$sum.'/mo user'.$pt->user->id)
              ->setStartDate(gmdate("Y-m-d\TH:i:s\Z", time()+2629743));


            // Set plan id
            $cplan = new Plan();
            $cplan->setId($plan->getId());
            $agreement->setPlan($cplan);

            // Add payer type
            $payer = new Payer();
            $payer->setPaymentMethod('paypal');
            $agreement->setPayer($payer);

            // Adding shipping details
            // $shippingAddress = new ShippingAddress();
            // $shippingAddress->setLine1('111 First Street')
            //   ->setCity('Saratoga')
            //   ->setState('CA')
            //   ->setPostalCode('95070')
            //   ->setCountryCode('US');
            // $agreement->setShippingAddress($shippingAddress);

            //$request = clone $agreement;
            //*********************

            try {
              // Create agreement
              $agreement = $agreement->create($paypal);
              // Extract approval URL to redirect user
              $approvalUrl = $agreement->getApprovalLink();
            } catch (PayPal\Exception\PayPalConnectionException $ex) {
                // ResultPrinter::printError("Created Plan", "Plan", null, $request, $ex);
                // exit(1);
            } catch (Exception $ex) {
              die($ex);
            }

            $data['status'] = 200;
            $data['url'] = $agreement->getApprovalLink();
		}
	}

	//Other packages
}

if (!empty($_GET['first']) && $_GET['first'] == 'get_paid') {
	$data['status'] = 500;
	$token = !empty($_GET['token']) && isset($_GET['token']) ? $_GET['token'] : '';
	$pkg            = (!empty($_GET['pkg']) && in_array($_GET['pkg'], $pkgs));
	if ($pt->config->recurring_payment == 'off') {
		$request        = (!empty($_GET['paymentId']) && !empty($_GET['PayerID']) && !empty($_GET['status']) && $_GET['status'] == 'success');
		if ($request && $pkg) {
			$paymentId = PT_Secure($_GET['paymentId']);
			$PayerID   = PT_Secure($_GET['PayerID']);
			$payment   = Payment::get($paymentId, $paypal);
		    $execute   = new PaymentExecution();
		    $execute->setPayerId($PayerID);

		    try{
		        $result = $payment->execute($execute, $paypal);
		    }

		    catch (Exception $e) {
		        $data = array(
		            'type' => 'ERROR',
		            'details' => json_decode($e->getData())
		        );

		        if (empty($data['details'])) {
		            $data['details'] = json_decode($e->getCode());
		        }

		        echo json_encode($data);
		    	exit();
		    }
		}
	}
	else{
		$agreement = new \PayPal\Api\Agreement();
        try {
            // Execute agreement
            $agreement->execute($token, $paypal);
        } catch (PayPal\Exception\PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die($ex);
        } catch (Exception $ex) {
            die($ex);
        }
	}
		
	    
    $update = array('is_pro' => 1,'verified' => 1);
    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
    if ($go_pro === true) {
    	$pkg_type             = PT_Secure($_GET['pkg']);
    	$payment_data         = array(
    		'user_id' => $pt->user->id,
    		'type'    => $pkg_type,
    		'amount'  => $sum,
    		'date'    => date('n') . '/' . date('Y'),
    		'expire'  => strtotime("+30 days")
    	);

    	$db->insert(T_PAYMENTS,$payment_data);
    	$db->where('user_id',$pt->user->id)->update(T_VIDEOS,array('featured' => 1));
    	$_SESSION['upgraded'] = true;
    	header('Location: ' . PT_Link('go_pro'));
    	exit();
    }
}


//Manage pro system from admin side

if (PT_IsAdmin() && !empty($_GET['first'])) {
	
	
	if ($_GET['first'] == 'remove_expired') {
		$data['status'] = 400;
		$expired_subs   = $db->where('expire',time(),'<')->get(T_PAYMENTS);
		$update         = array('is_pro' => 0,'verified' => 0);
		foreach ($expired_subs as $subscriber){
			$db->where('id',$subscriber->user_id)->update(T_USERS,$update);
			$db->where('user_id',$subscriber->user_id)->update(T_VIDEOS,array('featured' => 0));
		}

		$data['status'] = 200;

	}

}





if (!empty($_GET['first']) && $_GET['first'] == 'pay_to_see') {
	if (!empty($_POST['video_id']) && is_numeric($_POST['video_id'])) {
		$video = PT_GetVideoByID($_POST['video_id'], 0,0,2);
		if (!empty($video)) {
			$text = "";
			if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent' && !empty($video->rent_price)) {
				$total = $video->rent_price;
				$text = "&pay_type=rent";
			}
			else{
				$total = $video->sell_video;
			}
			
			
			$redirectUrls->setReturnUrl(PT_Link('aj/go_pro/paid_to_see?status=success&video_id='.$video->id.$text))->setCancelUrl(PT_Link(''));    
		    $item->setName('Pay to view video')->setQuantity(1)->setPrice($total)->setCurrency($paypal_currency);  
		    $itemList->setItems(array($item));    
		    $details->setSubtotal($total);
		    $amount->setCurrency($paypal_currency)->setTotal($total)->setDetails($details);
		    $transaction->setAmount($amount)->setItemList($itemList)->setDescription('Pay to view video')->setInvoiceNumber(time());
		    $payment->setIntent('sale')->setPayer($payer)->setRedirectUrls($redirectUrls)->setTransactions(array(
		        $transaction
		    ));

		    try {
		        $payment->create($paypal);
		    }

		    catch (Exception $e) {
		        $data = array(
		            'type' => 'ERROR',
		            'details' => json_decode($e->getData())
		        );

		        if (empty($data['details'])) {
		            $data['details'] = json_decode($e->getCode());
		        }
		        echo json_encode($data);
		    	exit();
		    }

		    $data = array(
		        'status' => 200,
		        'type' => 'SUCCESS',
		        'url' => $payment->getApprovalLink()
		    );
		}
	}
}


if (!empty($_GET['first']) && $_GET['first'] == 'paid_to_see') {
	$data['status'] = 500;
	$request        = (!empty($_GET['paymentId']) && !empty($_GET['PayerID']) && !empty($_GET['status']) && $_GET['status'] == 'success');
	$video_id       = (!empty($_GET['video_id']) && is_numeric($_GET['video_id'])) ? PT_Secure($_GET['video_id']) : 0;

	if ($request && $video_id) {
		$paymentId = PT_Secure($_GET['paymentId']);
		$PayerID   = PT_Secure($_GET['PayerID']);
		$payment   = Payment::get($paymentId, $paypal);
	    $execute   = new PaymentExecution();
	    $execute->setPayerId($PayerID);

	    try{
	        $result = $payment->execute($execute, $paypal);
	    }

	    catch (Exception $e) {
	        $data = array(
	            'type' => 'ERROR',
	            'details' => json_decode($e->getData())
	        );

	        if (empty($data['details'])) {
	            $data['details'] = json_decode($e->getCode());
	        }
            header('Location: ' . PT_Link(''));
	        echo json_encode($data);
	    	exit();
	    }
	    
	    if (!empty($video_id)) {
	    	$video = PT_GetVideoByID($video_id, 0,0,2);
	    	if (!empty($video)) {

	    		$notify_sent = false;
	    		if (!empty($video->is_movie)) {

	    			$payment_data         = array(
			    		'user_id' => $video->user_id,
			    		'video_id'    => $video->id,
			    		'paid_id'  => $pt->user->id,
			    		'admin_com'    => 0,
			    		'currency'    => $paypal_currency,
			    		'time'  => time()
			    	);
			    	if (!empty($_GET['pay_type']) && $_GET['pay_type'] == 'rent') {
		    			$payment_data['type'] = 'rent';
		    			$total = $video->rent_price;
		    		}
		    		else{
		    			$total = $video->sell_video;
		    		}
		    		$payment_data['amount'] = $total;
		    		$db->insert(T_VIDEOS_TRSNS,$payment_data);
	    		}
	    		else{

		    		if (!empty($_GET['pay_type']) && $_GET['pay_type'] == 'rent') {
		    			$admin__com = $pt->config->admin_com_rent_videos;
			    		if ($pt->config->com_type == 1) {
			    			$admin__com = ($pt->config->admin_com_rent_videos * $video->rent_price)/100;
			    			$paypal_currency = $paypal_currency.'_PERCENT';
			    		}
			    		$payment_data         = array(
				    		'user_id' => $video->user_id,
				    		'video_id'    => $video->id,
				    		'paid_id'  => $pt->user->id,
				    		'amount'    => $video->rent_price,
				    		'admin_com'    => $pt->config->admin_com_rent_videos,
				    		'currency'    => $paypal_currency,
				    		'time'  => time(),
				    		'type' => 'rent'
				    	);
				    	$balance = $video->rent_price - $admin__com;
		    		}
		    		else{
		    			$admin__com = $pt->config->admin_com_sell_videos;
			    		if ($pt->config->com_type == 1) {
			    			$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
			    			$paypal_currency = $paypal_currency.'_PERCENT';
			    		}

			    		$payment_data         = array(
				    		'user_id' => $video->user_id,
				    		'video_id'    => $video->id,
				    		'paid_id'  => $pt->user->id,
				    		'amount'    => $video->sell_video,
				    		'admin_com'    => $pt->config->admin_com_sell_videos,
				    		'currency'    => $paypal_currency,
				    		'time'  => time()
				    	);
				    	$balance = $video->sell_video - $admin__com;

		    		}
			    		
			    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
			    	
			    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' , `verified` = 1 WHERE `id` = '".$video->user_id."'");
			    }
			    if ($notify_sent == false) {
			    	$uniq_id = $video->video_id;
	                $notif_data = array(
	                    'notifier_id' => $pt->user->id,
	                    'recipient_id' => $video->user_id,
	                    'type' => 'paid_to_see',
	                    'url' => "watch/$uniq_id",
	                    'video_id' => $video->id,
	                    'time' => time()
	                );
	                
	                pt_notify($notif_data);
			    }

		    	header('Location: ' . $video->url);
		    	exit();
	    	}
	    	
	    }
	}
	header('Location: ' . PT_Link(''));
	exit();
}

if (!empty($_GET['first']) && $_GET['first'] == 'checkout_pay_to_see' && $pt->config->checkout_payment == 'yes') {
	if (empty($_POST['card_number']) || empty($_POST['card_cvc']) || empty($_POST['card_month']) || empty($_POST['card_year']) || empty($_POST['token']) || empty($_POST['card_name']) || empty($_POST['card_address']) || empty($_POST['card_city']) || empty($_POST['card_state']) || empty($_POST['card_zip']) || empty($_POST['card_country']) || empty($_POST['card_email']) || empty($_POST['card_phone']) || empty($_POST['video_id']) || empty($_POST['price'])) {
        $data = array(
            'status' => 400,
            'error' => $lang->please_check_details
        );
    }
    else {
    	require_once 'assets/import/2checkout/Twocheckout.php';
        Twocheckout::privateKey($pt->config->checkout_private_key);
        Twocheckout::sellerId($pt->config->checkout_seller_id);
        if ($pt->config->checkout_mode == 'sandbox') {
            Twocheckout::sandbox(true);
        } else {
            Twocheckout::sandbox(false);
        }
        try {
        	$amount = 0;
        	if (!empty($_POST['price'])) {
        		$amount = PT_Secure($_POST['price']);
        	}


        	$charge  = Twocheckout_Charge::auth(array(
                "merchantOrderId" => "123",
                "token" => $_POST['token'],
                "currency" => $pt->config->checkout_currency,
                "total" => $amount,
                "billingAddr" => array(
                    "name" => $_POST['card_name'],
                    "addrLine1" => $_POST['card_address'],
                    "city" => $_POST['card_city'],
                    "state" => $_POST['card_state'],
                    "zipCode" => $_POST['card_zip'],
                    "country" => $countries_name[$_POST['card_country']],
                    "email" => $_POST['card_email'],
                    "phoneNumber" => $_POST['card_phone']
                )
            ));
            if ($charge['response']['responseCode'] == 'APPROVED') {
            	$video_id = PT_Secure($_POST['video_id']);
            	if (!empty($video_id)) {
			    	$video = PT_GetVideoByID($video_id, 0,0,2);
			    	if (!empty($video)) {
			    		$notify_sent = false;
			    		if (!empty($video->is_movie)) {

			    			$payment_data         = array(
					    		'user_id' => $video->user_id,
					    		'video_id'    => $video->id,
					    		'paid_id'  => $pt->user->id,
					    		'admin_com'    => 0,
					    		'currency'    => $pt->config->checkout_currency,
					    		'time'  => time()
					    	);

					    	if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
				    			$payment_data['type'] = 'rent';
				    			$total = $video->rent_price;
				    		}
				    		else{
				    			$total = $video->sell_video;
				    		}
				    		$payment_data['amount'] = $total;
				    		$db->insert(T_VIDEOS_TRSNS,$payment_data);
			    		}
			    		else{
			    			$payment_currency = $pt->config->checkout_currency;

			    			if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
				    			$admin__com = $pt->config->admin_com_rent_videos;
					    		if ($pt->config->com_type == 1) {
					    			$admin__com = ($pt->config->admin_com_rent_videos * $video->rent_price)/100;
					    			$payment_currency = $pt->config->checkout_currency.'_PERCENT';
					    		}
					    		$payment_data         = array(
						    		'user_id' => $video->user_id,
						    		'video_id'    => $video->id,
						    		'paid_id'  => $pt->user->id,
						    		'amount'    => $video->rent_price,
						    		'admin_com'    => $pt->config->admin_com_rent_videos,
						    		'currency'    => $payment_currency,
						    		'time'  => time(),
						    		'type' => 'rent'
						    	);
						    	$balance = $video->rent_price - $admin__com;
				    		}
				    		else{
				    			$admin__com = $pt->config->admin_com_sell_videos;
					    		if ($pt->config->com_type == 1) {
					    			$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
					    			$payment_currency = $pt->config->checkout_currency.'_PERCENT';
					    		}

					    		$payment_data         = array(
						    		'user_id' => $video->user_id,
						    		'video_id'    => $video->id,
						    		'paid_id'  => $pt->user->id,
						    		'amount'    => $video->sell_video,
						    		'admin_com'    => $pt->config->admin_com_sell_videos,
						    		'currency'    => $payment_currency,
						    		'time'  => time()
						    	);
						    	$balance = $video->sell_video - $admin__com;

				    		}

					    	$db->insert(T_VIDEOS_TRSNS,$payment_data);

					    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' , `verified` = 1 WHERE `id` = '".$video->user_id."'");
					    }
					    if ($notify_sent == false) {
					    	$uniq_id = $video->video_id;
			                $notif_data = array(
			                    'notifier_id' => $pt->user->id,
			                    'recipient_id' => $video->user_id,
			                    'type' => 'paid_to_see',
			                    'url' => "watch/$uniq_id",
			                    'video_id' => $video->id,
			                    'time' => time()
			                );
			                
			                pt_notify($notif_data);
					    }
					    if ($pt->user->address != $_POST['card_address'] || $pt->user->city != $_POST['card_city'] || $pt->user->state != $_POST['card_state'] || $pt->user->zip != $_POST['card_zip'] || $pt->user->country_id != $_POST['card_country'] || $pt->user->phone_number != $_POST['card_phone']) {
					    	$update_data = array('address' => PT_Secure($_POST['card_address']),'city' => PT_Secure($_POST['card_city']),'state' => PT_Secure($_POST['card_state']),'zip' => PT_Secure($_POST['card_zip']),'country_id' => PT_Secure($_POST['card_country']),'phone_number' => PT_Secure($_POST['card_phone']));
					    	$db->where('id', $pt->user->id)->update(T_USERS, $update_data);
					    }
					    
					    $data['status'] = 200;
				    	$data['url'] = $video->url;
			    	}
			    }
            }
            else{
            	$data = array(
                    'status' => 400,
                    'error' => $lang->checkout_declined
                );
            }
        }
	    catch (Twocheckout_Error $e) {
            $data = array(
                'status' => 400,
                'error' => $e->getMessage()
            );
        }
    }
}




if (!empty($_GET['first']) && $_GET['first'] == 'checkout' && $pt->config->checkout_payment == 'yes') {
	$types = array('pro');
    if (empty($_POST['card_number']) || empty($_POST['card_cvc']) || empty($_POST['card_month']) || empty($_POST['card_year']) || empty($_POST['token']) || empty($_POST['card_name']) || empty($_POST['card_address']) || empty($_POST['card_city']) || empty($_POST['card_state']) || empty($_POST['card_zip']) || empty($_POST['card_country']) || empty($_POST['card_email']) || empty($_POST['card_phone']) || empty($_POST['type']) || !in_array($_POST['type'], $types)) {
        $data = array(
            'status' => 400,
            'error' => $lang->please_check_details
        );
    }
    else {
        require_once 'assets/import/2checkout/Twocheckout.php';
        Twocheckout::privateKey($pt->config->checkout_private_key);
        Twocheckout::sellerId($pt->config->checkout_seller_id);
        if ($pt->config->checkout_mode == 'sandbox') {
            Twocheckout::sandbox(true);
        } else {
            Twocheckout::sandbox(false);
        }
        try {
        	$amount = 0;
        	if ($_POST['type'] == 'pro') {
        		$amount = intval($pt->config->pro_pkg_price);
        	}


        	$charge  = Twocheckout_Charge::auth(array(
                "merchantOrderId" => "123",
                "token" => $_POST['token'],
                "currency" => $pt->config->checkout_currency,
                "total" => $amount,
                "billingAddr" => array(
                    "name" => $_POST['card_name'],
                    "addrLine1" => $_POST['card_address'],
                    "city" => $_POST['card_city'],
                    "state" => $_POST['card_state'],
                    "zipCode" => $_POST['card_zip'],
                    "country" => $countries_name[$_POST['card_country']],
                    "email" => $_POST['card_email'],
                    "phoneNumber" => $_POST['card_phone']
                )
            ));
            if ($charge['response']['responseCode'] == 'APPROVED') {

            	if ($_POST['type'] == 'pro') {
            		$update = array('is_pro' => 1,'verified' => 1);
				    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
				    if ($go_pro === true) {
				    	$payment_data         = array(
				    		'user_id' => $pt->user->id,
				    		'type'    => 'pro',
				    		'amount'  => $amount,
				    		'date'    => date('n') . '/' . date('Y'),
				    		'expire'  => strtotime("+30 days")
				    	);

				    	$db->insert(T_PAYMENTS,$payment_data);
				    	$db->where('user_id',$pt->user->id)->update(T_VIDEOS,array('featured' => 1));
				    	$_SESSION['upgraded'] = true;
				    	$data['status'] = 200;
				    	$data['url'] = PT_Link('go_pro');
				    }
            	}
            	if ($pt->user->address != $_POST['card_address'] || $pt->user->city != $_POST['card_city'] || $pt->user->state != $_POST['card_state'] || $pt->user->zip != $_POST['card_zip'] || $pt->user->country_id != $_POST['card_country'] || $pt->user->phone_number != $_POST['card_phone']) {
			    	$update_data = array('address' => PT_Secure($_POST['card_address']),'city' => PT_Secure($_POST['card_city']),'state' => PT_Secure($_POST['card_state']),'zip' => PT_Secure($_POST['card_zip']),'country_id' => PT_Secure($_POST['card_country']),'phone_number' => PT_Secure($_POST['card_phone']));
			    	$db->where('id', $pt->user->id)->update(T_USERS, $update_data);
			    }

            }
            else{
            	$data = array(
                    'status' => 400,
                    'error' => $lang->checkout_declined
                );
            }
		}
		catch (Twocheckout_Error $e) {
            $data = array(
                'status' => 400,
                'error' => $e->getMessage()
            );
        }
	}
}

if (!empty($_GET['first']) && $_GET['first'] == 'stripe' && $pt->config->credit_card == 'yes') {
	if (!empty($_POST['stripeToken'])) {

		require_once('assets/import/stripe-php-3.20.0/vendor/autoload.php');
		$stripe = array(
		  "secret_key"      =>  $pt->config->stripe_secret,
		  "publishable_key" =>  $pt->config->stripe_id
		);

		\Stripe\Stripe::setApiKey($stripe['secret_key']);


	    $token = $_POST['stripeToken'];
	    try {
	        $customer = \Stripe\Customer::create(array(
	            'source' => $token
	        ));

	        $final_amount = $amount = intval($pt->config->pro_pkg_price);
	        $final_amount = $final_amount*100;
	        $charge   = \Stripe\Charge::create(array(
	            'customer' => $customer->id,
	            'amount' => $final_amount,
	            'currency' => $pt->config->stripe_currency
	        ));

	        if ($charge) {
	        	$update = array('is_pro' => 1,'verified' => 1);
			    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
			    if ($go_pro === true) {
			    	$payment_data         = array(
			    		'user_id' => $pt->user->id,
			    		'type'    => 'pro',
			    		'amount'  => $amount,
			    		'date'    => date('n') . '/' . date('Y'),
			    		'expire'  => strtotime("+30 days")
			    	);

			    	$db->insert(T_PAYMENTS,$payment_data);
			    	$db->where('user_id',$pt->user->id)->update(T_VIDEOS,array('featured' => 1));
			    	$_SESSION['upgraded'] = true;
			    	$data['status'] = 200;
			    	$data['url'] = PT_Link('go_pro');
			    }
	        }
	    }
	    catch (Exception $e) {
	        $data = array(
	            'status' => 400,
	            'error' => $e->getMessage()
	        );
	        header("Content-type: application/json");
	        echo json_encode($data);
	        exit();
	    }
	}
	else{
		$data = array(
            'status' => 400,
            'error' => $lang->please_check_details
        );
	}
}


if (!empty($_GET['first']) && $_GET['first'] == 'stripe_pay_to_see' && $pt->config->credit_card == 'yes') {
	if (!empty($_POST['stripeToken']) && !empty($_POST['video_id'])) {
		$video_id = PT_Secure($_POST['video_id']);
		$video = PT_GetVideoByID($video_id, 0,0,2);
		if (!empty($video)) {
			require_once('assets/import/stripe-php-3.20.0/vendor/autoload.php');
			$stripe = array(
			  "secret_key"      =>  $pt->config->stripe_secret,
			  "publishable_key" =>  $pt->config->stripe_id
			);

			\Stripe\Stripe::setApiKey($stripe['secret_key']);


		    $token = $_POST['stripeToken'];
		    try {
		        $customer = \Stripe\Customer::create(array(
		            'source' => $token
		        ));

		        
		        if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent' && !empty($video->rent_price)) {
	    			$final_amount = $amount = $video->rent_price;
	    		}
	    		else{
	    			$final_amount = $amount = $video->sell_video;
	    		}
		        $final_amount = $final_amount*100;
		        $charge   = \Stripe\Charge::create(array(
		            'customer' => $customer->id,
		            'amount' => $final_amount,
		            'currency' => $pt->config->stripe_currency
		        ));

		        if ($charge) {
		    		$notify_sent = false;
		    		if (!empty($video->is_movie)) {

		    			$payment_data         = array(
				    		'user_id' => $video->user_id,
				    		'video_id'    => $video->id,
				    		'paid_id'  => $pt->user->id,
				    		'admin_com'    => 0,
				    		'currency'    => $pt->config->stripe_currency,
				    		'time'  => time()
				    	);
				    	if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
			    			$payment_data['type'] = 'rent';
			    			$total = $video->rent_price;
			    		}
			    		else{
			    			$total = $video->sell_video;
			    		}
				    	
			    		$payment_data['amount'] = $total;
			    		$db->insert(T_VIDEOS_TRSNS,$payment_data);
		    		}
		    		else{
		    			$payment_currency = $pt->config->stripe_currency;


		    			if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
			    			$admin__com = $pt->config->admin_com_rent_videos;
				    		if ($pt->config->com_type == 1) {
				    			$admin__com = ($pt->config->admin_com_rent_videos * $video->rent_price)/100;
				    			$payment_currency = $pt->config->stripe_currency.'_PERCENT';
				    		}
				    		$payment_data         = array(
					    		'user_id' => $video->user_id,
					    		'video_id'    => $video->id,
					    		'paid_id'  => $pt->user->id,
					    		'amount'    => $video->rent_price,
					    		'admin_com'    => $pt->config->admin_com_rent_videos,
					    		'currency'    => $payment_currency,
					    		'time'  => time(),
					    		'type' => 'rent'
					    	);
					    	$balance = $video->rent_price - $admin__com;
			    		}
			    		else{
			    			$admin__com = $pt->config->admin_com_sell_videos;
				    		if ($pt->config->com_type == 1) {
				    			$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
				    			$payment_currency = $pt->config->stripe_currency.'_PERCENT';
				    		}

				    		$payment_data         = array(
					    		'user_id' => $video->user_id,
					    		'video_id'    => $video->id,
					    		'paid_id'  => $pt->user->id,
					    		'amount'    => $video->sell_video,
					    		'admin_com'    => $pt->config->admin_com_sell_videos,
					    		'currency'    => $payment_currency,
					    		'time'  => time()
					    	);
					    	$balance = $video->sell_video - $admin__com;

			    		}



		    		
			    		// $admin__com = $pt->config->admin_com_sell_videos;
			    		
			    		// if ($pt->config->com_type == 1) {
			    		// 	$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
			    		// 	$payment_currency = $pt->config->stripe_currency.'_PERCENT';
			    		// }
			    		// $payment_data         = array(
				    	// 	'user_id' => $video->user_id,
				    	// 	'video_id'    => $video->id,
				    	// 	'paid_id'  => $pt->user->id,
				    	// 	'amount'    => $video->sell_video,
				    	// 	'admin_com'    => $pt->config->admin_com_sell_videos,
				    	// 	'currency'    => $payment_currency,
				    	// 	'time'  => time()
				    	// );
				    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
				    	//$balance = $video->sell_video - $admin__com;
				    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' , `verified` = 1 WHERE `id` = '".$video->user_id."'");
				    }
				    if ($notify_sent == false) {
				    	$uniq_id = $video->video_id;
		                $notif_data = array(
		                    'notifier_id' => $pt->user->id,
		                    'recipient_id' => $video->user_id,
		                    'type' => 'paid_to_see',
		                    'url' => "watch/$uniq_id",
		                    'video_id' => $video->id,
		                    'time' => time()
		                );
		                
		                pt_notify($notif_data);
				    }
				    
				    $data['status'] = 200;
			    	$data['url'] = $video->url;

		        }
		    }
		    catch (Exception $e) {
		        $data = array(
		            'status' => 400,
		            'error' => $e->getMessage()
		        );
		        header("Content-type: application/json");
		        echo json_encode($data);
		        exit();
		    }
		}
	}
	else{
		$data = array(
            'status' => 400,
            'error' => $lang->please_check_details
        );
	}
}

if (!empty($_GET['first']) && $_GET['first'] == 'bank' && $pt->config->bank_payment == 'yes') {

    if (empty($_FILES["thumbnail"])) {
        $error = $lang->please_check_details;
    }
    if (empty($error)) {
    	$amount = intval($pt->config->pro_pkg_price);
        $description = 'Pro Member';
        $fileInfo      = array(
            'file' => $_FILES["thumbnail"]["tmp_name"],
            'name' => $_FILES['thumbnail']['name'],
            'size' => $_FILES["thumbnail"]["size"],
            'type' => $_FILES["thumbnail"]["type"],
            'types' => 'jpeg,jpg,png,bmp,gif'
        );
        $media         = PT_ShareFile($fileInfo);
        $mediaFilename = $media['filename'];
        if (!empty($mediaFilename)) {
        	$insert_id = $db->insert(T_BANK_TRANSFER,array('user_id' => $pt->user->id,
                                                   'description' => $description,
                                                   'price'       => $amount,
                                                   'receipt_file' => $mediaFilename,
                                                   'mode'         => 'pro'));
            if (!empty($insert_id)) {
                $data = array(
                    'message' => $lang->bank_transfer_request,
                    'status' => 200
                );
            }
        }
        else{
            $error = $lang->please_check_details;
            $data = array(
                'status' => 500,
                'message' => $error
            );
        }
    } else {
        $data = array(
            'status' => 500,
            'message' => $error
        );
    }
}

if (!empty($_GET['first']) && $_GET['first'] == 'bank_pay_to_see' && $pt->config->bank_payment == 'yes') {
	if (!empty($_FILES["thumbnail"]) && !empty($_POST['video_id'])) {
		$video_id = PT_Secure($_POST['video_id']);
		$video = PT_GetVideoByID($video_id, 0,0,2);
		if (!empty($video)) {

			
	        $description = 'Pay to see video';
	        $fileInfo      = array(
	            'file' => $_FILES["thumbnail"]["tmp_name"],
	            'name' => $_FILES['thumbnail']['name'],
	            'size' => $_FILES["thumbnail"]["size"],
	            'type' => $_FILES["thumbnail"]["type"],
	            'types' => 'jpeg,jpg,png,bmp,gif'
	        );
	        $media         = PT_ShareFile($fileInfo);
	        $mediaFilename = $media['filename'];
	        if (!empty($mediaFilename)) {
	        	if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
	    			$mode = 'rent';
	    			$amount = $video->rent_price;
	    		}
	    		else{
	    			$mode = 'pay';
	    			$amount = $video->sell_video;
	    		}
	        	$insert_id = $db->insert(T_BANK_TRANSFER,array('user_id' => $pt->user->id,
	                                                   'description' => $description,
	                                                   'price'       => $amount,
	                                                   'receipt_file' => $mediaFilename,
	                                                   'mode'         => $mode,
	                                                   'video_id' => $video_id));
	            if (!empty($insert_id)) {
	                $data = array(
	                    'message' => $lang->bank_transfer_request,
	                    'status' => 200
	                );
	            }
	        }
	        else{
	            $error = $lang->please_check_details;
	            $data = array(
	                'status' => 500,
	                'message' => $error
	            );
	        }
		}
	}
	else{
		$data = array(
            'status' => 400,
            'error' => $lang->please_check_details
        );
	}
}

if (!empty($_GET['first']) && $_GET['first'] == 'pro_wallet') {
	$user = $db->where('id',$pt->user->id)->getOne(T_USERS);
	$data['status'] = 400;

	if (!empty($user) && $user->wallet >= intval($pt->config->pro_pkg_price)) {
		$amount = intval($pt->config->pro_pkg_price);
		$wallet = $user->wallet - $amount;
		$update = array('is_pro' => 1,'verified' => 1,'wallet' => $wallet);
	    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
	    if ($go_pro === true) {
	    	$payment_data         = array(
	    		'user_id' => $pt->user->id,
	    		'type'    => 'pro',
	    		'amount'  => $amount,
	    		'date'    => date('n') . '/' . date('Y'),
	    		'expire'  => strtotime("+30 days")
	    	);

	    	$db->insert(T_PAYMENTS,$payment_data);
	    	$db->where('user_id',$pt->user->id)->update(T_VIDEOS,array('featured' => 1));
	    	$_SESSION['upgraded'] = true;
	    	$data['status'] = 200;
	    	$data['url'] = PT_Link('go_pro');
	    }
	}
}

if (!empty($_GET['first']) && $_GET['first'] == 'pay_to_see_wallet' && !empty($_POST['video_id'])) {
	$video_id = PT_Secure($_POST['video_id']);
	$user = $db->where('id',$pt->user->id)->getOne(T_USERS);
	$data['status'] = 400;


	if (!empty($video_id)) {
    	$video = PT_GetVideoByID($video_id, 0,0,2);
    	if (!empty($video) && !empty($user) && ($user->wallet >= $video->sell_video || $user->wallet >= $video->rent_price)) {
    		if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent' && !empty($video->rent_price)) {
    			$wallet = $user->wallet - $video->rent_price;

    		}
    		else{
    			$wallet = $user->wallet - $video->sell_video;
    		}
    		
			$update = array('wallet' => $wallet);
		    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);

    		$notify_sent = false;
    		if (!empty($video->is_movie)) {

    			$payment_data         = array(
		    		'user_id' => $video->user_id,
		    		'video_id'    => $video->id,
		    		'paid_id'  => $pt->user->id,
		    		'admin_com'    => 0,
		    		'currency'    => $payment_currency,
		    		'time'  => time()
		    	);
		    	
		    	if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
	    			$payment_data['type'] = 'rent';
	    			$total = $video->rent_price;
	    		}
	    		else{
	    			$total = $video->sell_video;
	    		}
	    		$payment_data['amount'] = $total;
	    		$db->insert(T_VIDEOS_TRSNS,$payment_data);
    		}
    		else{

    			if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
	    			$admin__com = $pt->config->admin_com_rent_videos;
		    		if ($pt->config->com_type == 1) {
		    			$admin__com = ($pt->config->admin_com_rent_videos * $video->rent_price)/100;
		    			$payment_currency = $payment_currency.'_PERCENT';
		    		}
		    		$payment_data         = array(
			    		'user_id' => $video->user_id,
			    		'video_id'    => $video->id,
			    		'paid_id'  => $pt->user->id,
			    		'amount'    => $video->rent_price,
			    		'admin_com'    => $pt->config->admin_com_rent_videos,
			    		'currency'    => $payment_currency,
			    		'time'  => time(),
			    		'type' => 'rent'
			    	);
			    	$balance = $video->rent_price - $admin__com;
	    		}
	    		else{
	    			$admin__com = $pt->config->admin_com_sell_videos;
		    		if ($pt->config->com_type == 1) {
		    			$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
		    			$payment_currency = $payment_currency.'_PERCENT';
		    		}

		    		$payment_data         = array(
			    		'user_id' => $video->user_id,
			    		'video_id'    => $video->id,
			    		'paid_id'  => $pt->user->id,
			    		'amount'    => $video->sell_video,
			    		'admin_com'    => $pt->config->admin_com_sell_videos,
			    		'currency'    => $payment_currency,
			    		'time'  => time()
			    	);
			    	$balance = $video->sell_video - $admin__com;

	    		}


    		
	    		// $admin__com = $pt->config->admin_com_sell_videos;
	    		// if ($pt->config->com_type == 1) {
	    		// 	$admin__com = ($pt->config->admin_com_sell_videos * $video->sell_video)/100;
	    		// 	$payment_currency = $payment_currency.'_PERCENT';
	    		// }
	    		// $payment_data         = array(
		    	// 	'user_id' => $video->user_id,
		    	// 	'video_id'    => $video->id,
		    	// 	'paid_id'  => $pt->user->id,
		    	// 	'amount'    => $video->sell_video,
		    	// 	'admin_com'    => $pt->config->admin_com_sell_videos,
		    	// 	'currency'    => $payment_currency,
		    	// 	'time'  => time()
		    	// );
		    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
		    	//$balance = $video->sell_video - $admin__com;
		    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' , `verified` = 1 WHERE `id` = '".$video->user_id."'");
		    }
		    if ($notify_sent == false) {
		    	$uniq_id = $video->video_id;
		    	if (!empty($_POST['pay_type']) && $_POST['pay_type'] == 'rent') {
		    		$notif_data = array(
	                    'notifier_id' => $pt->user->id,
	                    'recipient_id' => $video->user_id,
	                    'type' => 'rent_to_see',
	                    'url' => "watch/$uniq_id",
	                    'video_id' => $video->id,
	                    'time' => time()
	                );
		    	}
		    	else{
		    		$notif_data = array(
	                    'notifier_id' => $pt->user->id,
	                    'recipient_id' => $video->user_id,
	                    'type' => 'paid_to_see',
	                    'url' => "watch/$uniq_id",
	                    'video_id' => $video->id,
	                    'time' => time()
	                );
		    	}
                
                pt_notify($notif_data);
		    }

	    	$data['status'] = 200;
            $data['url'] = $video->url;
    	}
    	
    }
}


if (!empty($_GET['first']) && $_GET['first'] == 'subscribe' && !empty($_POST['user_id']) && is_numeric($_POST['user_id'])) {
	$data['status'] = 400;
	$user_id = PT_Secure($_POST['user_id']);
	$user = PT_UserData($user_id);
	if (!empty($_POST['type']) && $_POST['type'] == 'paypal') {
		
		if (!empty($user) && $user->subscriber_price > 0) {
			$total = $user->subscriber_price;
			$redirectUrls->setReturnUrl(PT_Link('aj/go_pro/check_subscribe?status=success&user_id='.$_POST['user_id']))->setCancelUrl(PT_Link(''));    
		    $item->setName('Pay to subscribe')->setQuantity(1)->setPrice($total)->setCurrency($paypal_currency);  
		    $itemList->setItems(array($item));    
		    $details->setSubtotal($total);
		    $amount->setCurrency($paypal_currency)->setTotal($total)->setDetails($details);
		    $transaction->setAmount($amount)->setItemList($itemList)->setDescription('Pay to subscribe')->setInvoiceNumber(time());
		    $payment->setIntent('sale')->setPayer($payer)->setRedirectUrls($redirectUrls)->setTransactions(array(
		        $transaction
		    ));

		    try {
		        $payment->create($paypal);
		    }

		    catch (Exception $e) {
		        $data = array(
		            'type' => 'ERROR',
		            'details' => json_decode($e->getData())
		        );

		        if (empty($data['details'])) {
		            $data['details'] = json_decode($e->getCode());
		        }
		        echo json_encode($data);
		    	exit();
		    }

		    $data = array(
		        'status' => 200,
		        'type' => 'SUCCESS',
		        'url' => $payment->getApprovalLink()
		    );
		}
	}
	elseif (!empty($_POST['type']) && $_POST['type'] == 'stripe' && !empty($_POST['stripeToken']) && $pt->config->credit_card == 'yes') {
		if (!empty($user) && $user->subscriber_price > 0) {
			require_once('assets/import/stripe-php-3.20.0/vendor/autoload.php');
			$stripe = array(
			  "secret_key"      =>  $pt->config->stripe_secret,
			  "publishable_key" =>  $pt->config->stripe_id
			);

			\Stripe\Stripe::setApiKey($stripe['secret_key']);


		    $token = $_POST['stripeToken'];
		    try {
		        $customer = \Stripe\Customer::create(array(
		            'source' => $token
		        ));

		        $final_amount = $amount = $user->subscriber_price;
		        $final_amount = $final_amount*100;
		        $charge   = \Stripe\Charge::create(array(
		            'customer' => $customer->id,
		            'amount' => $final_amount,
		            'currency' => $pt->config->stripe_currency
		        ));

		        if ($charge) {
		        	$admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
		    		$stripe_currency = $pt->config->stripe_currency.'_PERCENT';
		    		$payment_data         = array(
			    		'user_id' => $user_id,
			    		'video_id'    => 0,
			    		'paid_id'  => $pt->user->id,
			    		'amount'    => $user->subscriber_price,
			    		'admin_com'    => $pt->config->admin_com_subscribers,
			    		'currency'    => $stripe_currency,
			    		'time'  => time(),
			    		'type' => 'subscribe'
			    	);
			    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
			    	$balance = $user->subscriber_price - $admin__com;
			    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");
			    	$insert_data         = array(
			            'user_id' => $user_id,
			            'subscriber_id' => $pt->user->id,
			            'time' => time(),
			            'active' => 1
			        );
			        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
			        if ($create_subscription) {

			            $notif_data = array(
			                'notifier_id' => $pt->user->id,
			                'recipient_id' => $user_id,
			                'type' => 'subscribed_u',
			                'url' => ('@' . $pt->user->username),
			                'time' => time()
			            );

			            pt_notify($notif_data);
			        }

			    	$data['status'] = 200;
			    	$data['url'] = $user->url;
		    		
		        }
		    }
		    catch (Exception $e) {
		        $data = array(
		            'status' => 400,
		            'error' => $e->getMessage()
		        );
		        header("Content-type: application/json");
		        echo json_encode($data);
		        exit();
		    }
		}
	}
	elseif (!empty($_POST['type']) && $_POST['type'] == 'checkout' && $pt->config->checkout_payment == 'yes') {
		if (empty($_POST['card_number']) || empty($_POST['card_cvc']) || empty($_POST['card_month']) || empty($_POST['card_year']) || empty($_POST['token']) || empty($_POST['card_name']) || empty($_POST['card_address']) || empty($_POST['card_city']) || empty($_POST['card_state']) || empty($_POST['card_zip']) || empty($_POST['card_country']) || empty($_POST['card_email']) || empty($_POST['card_phone']) || empty($_POST['type'])) {
	        $data = array(
	            'status' => 400,
	            'error' => $lang->please_check_details
	        );
	    }
	    else {
	        require_once 'assets/import/2checkout/Twocheckout.php';
	        Twocheckout::privateKey($pt->config->checkout_private_key);
	        Twocheckout::sellerId($pt->config->checkout_seller_id);
	        if ($pt->config->checkout_mode == 'sandbox') {
	            Twocheckout::sandbox(true);
	        } else {
	            Twocheckout::sandbox(false);
	        }
	        try {
	        	$amount = $user->subscriber_price;


	        	$charge  = Twocheckout_Charge::auth(array(
	                "merchantOrderId" => "123",
	                "token" => $_POST['token'],
	                "currency" => $pt->config->checkout_currency,
	                "total" => $amount,
	                "billingAddr" => array(
	                    "name" => $_POST['card_name'],
	                    "addrLine1" => $_POST['card_address'],
	                    "city" => $_POST['card_city'],
	                    "state" => $_POST['card_state'],
	                    "zipCode" => $_POST['card_zip'],
	                    "country" => $countries_name[$_POST['card_country']],
	                    "email" => $_POST['card_email'],
	                    "phoneNumber" => $_POST['card_phone']
	                )
	            ));
	            if ($charge['response']['responseCode'] == 'APPROVED') {

	            	$admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
		    		$checkout_currency = $pt->config->checkout_currency.'_PERCENT';
		    		$payment_data         = array(
			    		'user_id' => $user_id,
			    		'video_id'    => 0,
			    		'paid_id'  => $pt->user->id,
			    		'amount'    => $user->subscriber_price,
			    		'admin_com'    => $pt->config->admin_com_subscribers,
			    		'currency'    => $checkout_currency,
			    		'time'  => time(),
			    		'type' => 'subscribe'
			    	);
			    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
			    	$balance = $user->subscriber_price - $admin__com;
			    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");
			    	$insert_data         = array(
			            'user_id' => $user_id,
			            'subscriber_id' => $pt->user->id,
			            'time' => time(),
			            'active' => 1
			        );
			        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
			        if ($create_subscription) {

			            $notif_data = array(
			                'notifier_id' => $pt->user->id,
			                'recipient_id' => $user_id,
			                'type' => 'subscribed_u',
			                'url' => ('@' . $pt->user->username),
			                'time' => time()
			            );

			            pt_notify($notif_data);
			        }

			    	$data['status'] = 200;
			    	$data['url'] = $user->url;
	            }
	            else{
	            	$data = array(
	                    'status' => 400,
	                    'error' => $lang->checkout_declined
	                );
	            }
			}
			catch (Twocheckout_Error $e) {
	            $data = array(
	                'status' => 400,
	                'error' => $e->getMessage()
	            );
	        }
		}
	}
	elseif (!empty($_POST['type']) && $_POST['type'] == 'bank' && $pt->config->bank_payment == 'yes') {
		if (empty($_FILES["thumbnail"])) {
	        $error = $lang->please_check_details;
	    }
	    if (empty($error)) {
	    	$amount = $user->subscriber_price;
	        $description = 'Subscribe';
	        $fileInfo      = array(
	            'file' => $_FILES["thumbnail"]["tmp_name"],
	            'name' => $_FILES['thumbnail']['name'],
	            'size' => $_FILES["thumbnail"]["size"],
	            'type' => $_FILES["thumbnail"]["type"],
	            'types' => 'jpeg,jpg,png,bmp,gif'
	        );
	        $media         = PT_ShareFile($fileInfo);
	        $mediaFilename = $media['filename'];
	        if (!empty($mediaFilename)) {
	        	$insert_id = $db->insert(T_BANK_TRANSFER,array('user_id' => $pt->user->id,
											        		   'profile_id' => $user_id,
		                                                   'description' => $description,
		                                                   'price'       => $amount,
		                                                   'receipt_file' => $mediaFilename,
		                                                   'mode'         => 'subscribe'));
	            if (!empty($insert_id)) {
	                $data = array(
	                    'message' => $lang->bank_transfer_request,
	                    'status' => 200
	                );
	            }
	        }
	        else{
	            $error = $lang->please_check_details;
	            $data = array(
	                'status' => 500,
	                'message' => $error
	            );
	        }
	    } else {
	        $data = array(
	            'status' => 500,
	            'message' => $error
	        );
	    }

	}
	elseif (!empty($_POST['type']) && $_POST['type'] == 'wallet') {
		$amount = $user->subscriber_price;
		$wallet = $pt->user->wallet - $amount;

		$admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
		$payment_currency = $pt->config->payment_currency.'_PERCENT';
		$payment_data         = array(
    		'user_id' => $user_id,
    		'video_id'    => 0,
    		'paid_id'  => $pt->user->id,
    		'amount'    => $user->subscriber_price,
    		'admin_com'    => $pt->config->admin_com_subscribers,
    		'currency'    => $payment_currency,
    		'time'  => time(),
    		'type' => 'subscribe'
    	);
    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
    	$balance = $user->subscriber_price - $admin__com;
    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");
    	$update = array('wallet' => $wallet);
	    $go_pro = $db->where('id',$pt->user->id)->update(T_USERS,$update);
    	$insert_data         = array(
            'user_id' => $user_id,
            'subscriber_id' => $pt->user->id,
            'time' => time(),
            'active' => 1
        );
        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
        if ($create_subscription) {

            $notif_data = array(
                'notifier_id' => $pt->user->id,
                'recipient_id' => $user_id,
                'type' => 'subscribed_u',
                'url' => ('@' . $pt->user->username),
                'time' => time()
            );

            pt_notify($notif_data);
        }

    	$data['status'] = 200;
    	$data['url'] = $user->url;
	}
}



if (!empty($_GET['first']) && $_GET['first'] == 'check_subscribe') {
	$data['status'] = 500;
	$request        = (!empty($_GET['paymentId']) && !empty($_GET['PayerID']) && !empty($_GET['status']) && $_GET['status'] == 'success');
	$user_id       = (!empty($_GET['user_id']) && is_numeric($_GET['user_id'])) ? PT_Secure($_GET['user_id']) : 0;

	if ($request && $user_id) {
		$paymentId = PT_Secure($_GET['paymentId']);
		$PayerID   = PT_Secure($_GET['PayerID']);
		$payment   = Payment::get($paymentId, $paypal);
	    $execute   = new PaymentExecution();
	    $execute->setPayerId($PayerID);

	    try{
	        $result = $payment->execute($execute, $paypal);
	    }

	    catch (Exception $e) {
	        $data = array(
	            'type' => 'ERROR',
	            'details' => json_decode($e->getData())
	        );

	        if (empty($data['details'])) {
	            $data['details'] = json_decode($e->getCode());
	        }
            header('Location: ' . PT_Link(''));
	        echo json_encode($data);
	    	exit();
	    }
	    
	    if (!empty($user_id)) {
	    	$user = PT_UserData($user_id);
	    	if (!empty($user) && $user->subscriber_price > 0) {

	    		$admin__com = ($pt->config->admin_com_subscribers * $user->subscriber_price)/100;
	    		$paypal_currency = $paypal_currency.'_PERCENT';
	    		$payment_data         = array(
		    		'user_id' => $user_id,
		    		'video_id'    => 0,
		    		'paid_id'  => $pt->user->id,
		    		'amount'    => $user->subscriber_price,
		    		'admin_com'    => $pt->config->admin_com_subscribers,
		    		'currency'    => $paypal_currency,
		    		'time'  => time(),
		    		'type' => 'subscribe'
		    	);
		    	$db->insert(T_VIDEOS_TRSNS,$payment_data);
		    	$balance = $user->subscriber_price - $admin__com;
		    	$db->rawQuery("UPDATE ".T_USERS." SET `balance` = `balance`+ '".$balance."' WHERE `id` = '".$user_id."'");
		    	$insert_data         = array(
		            'user_id' => $user_id,
		            'subscriber_id' => $pt->user->id,
		            'time' => time(),
		            'active' => 1
		        );
		        $create_subscription = $db->insert(T_SUBSCRIPTIONS, $insert_data);
		        if ($create_subscription) {

		            $notif_data = array(
		                'notifier_id' => $pt->user->id,
		                'recipient_id' => $user_id,
		                'type' => 'subscribed_u',
		                'url' => ('@' . $pt->user->username),
		                'time' => time()
		            );

		            pt_notify($notif_data);
		        }

		    	header('Location: ' . $user->url);
		    	exit();
	    	}
	    	
	    }
	}
	header('Location: ' . PT_Link(''));
	exit();
}