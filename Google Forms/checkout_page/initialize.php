<?php 

//**************************************************************************************//
//**********         PHP Scripts To Initialize Rapyd Checkout                 *********//
//**************************************************************************************//


if($order_details['order_payable']){

  //
  // We create an rapyd order using orders api
  // Docs: https://docs.razorpay.com/docs/orders
  //

  $checkout_amount = $order_details['order']['total'] * 100; //razorpay wants this in cents
  $internal_order_id = $order_details['order']['id'];
  $payable_id = $order_details['order']['payable_id'];
  
  //Let's set a string we can use to specify the credentials.
  if($order_details['order']['test_mode']){
    $rapyd_js = 'https://sandboxcheckouttoolkit.rapyd.net';
  }else{
    $rapyd_js = 'https://checkouttoolkit.rapyd.net';
  }



  //lets first check if there is already a Rapyd Checkout orderID thats not in a progressed status
  if(!empty($order_details['order']['payment_provider_order_id']) && !in_array($order_details['order']['status'], array('created','checkout-started')) ){

    $rapyd_order_id = $order_details['order']['payment_provider_order_id'];

    $rapydOrder  = get_latest_from_rapyd($order_details['order']['payable_id']);

    // if($razorpayOrder['status'] == 'paid'){
      
    //   //We should also have a payments array if its paid
    //   //Lets look at the last payment
    //   $last_payment = $razorpayOrder['payments']['items'][0];

    //   if($last_payment['status'] == 'captured'){  
    //     //Looks like its paid and we did not know about it.
    //     mark_order_as_paid($order_details, $last_payment);
    //     header('Location: '.$this_page_url);
    //     die;
    //   }else{
    //     //TODO we might need to handle some pending payment status
    //   }

    //   //Redirect to receipt
    // }
  
  }else{ //we will need to make one.

    $rapydCustomer = create_or_get_rapyd_customer($order_details['order']['payable_id']);

    $rapydOrder = create_order_from_payable_id($order_details['order']['payable_id']);

    if($rapydOrder['created']){  //Nice all is well

      $rapyd_order_id = $rapydOrder['id'];

      //We have to do this because the create response is missing the payment methods accepted (Kind of annoying)
      $rapydOrder = get_latest_from_rapyd($order_details['order']['payable_id']);
      
    }else{ //Looks we had an issue.  - Lets tell the checkout page how to react.

      $title = "Whoops  :(";
      $description = 'We had trouble connecting with the payment provider (Rapyd) to setup this transaction.';

      if(isset($razorpayOrder['message'])){  //we have more error information.
        $description .= '<br/><br/>Rapyd error message: <strong>'.$razorpayOrder['message'].'</strong>';
      }

      $description .= '<br/><br/><strong>Refresh the page to give it another try.</strong>';

      $order_details['order_payable'] = false;

    }

  }//end if we need to make one.


 
}else{ //its not payable meaning its maybe in a different status - Lets still get the details.

  $rapydOrder  = get_latest_from_rapyd($order_details['order']['payable_id']);
  $internal_order_id = $order_details['order']['id'];
  

  //lets check if an active order now has a paid status
  if($order_details['order']['status'] == 'active'  && $rapydOrder['data']['payment']['status'] == 'CLO'){
    //Looks like the order has been paid since we last new about it.
    $payment_time = date('Y-m-d H:i:s', $rapydOrder['data']['payment']['paid_at']);
    $upq = "UPDATE `orders` SET `status` = 'paid', `payment_date` = '$payment_time', `updated` = now() WHERE `orders`.`id` = $internal_order_id;";
    mysqli_query($DB_LINK, $upq);

    //update these items.
    $order_details['order']['status'] = 'paid';
  }else if($order_details['order']['status'] == 'active'  && isset($_GET['restart'])){
    //It looks like the user wants to abort the payment option they previously choose and start over.
    $upq = "UPDATE `orders` SET `status` = 'created', `payment_provider_transaction_number` = null, `payment_provider_order_id` = null,  `payment_date` = null, `payment_method` = null, `updated` = now() WHERE `orders`.`id` = $internal_order_id;";
    mysqli_query($DB_LINK, $upq);
    header("location: /order/".$payable_id);
    die;
  }

  //It looks like this Rapyd order is paid, but we have not set it up for a recurring subscription yet
  if($order_details['order']['status'] == 'paid'  && isset($order_details['order']['recurring_details'])){

    $rapydProduct = create_rapyd_product($order_details['order']['payable_id']);
    //print_r($rapydProduct);

    $rapydPlan = create_rapyd_plan($order_details['order']['payable_id']);
    //print_r($rapydPlan);

    $rapydSubscription = create_rapyd_subscription($order_details['order']['payable_id']);
    //print_r($rapydSubscription);

    if($rapydSubscription['status']['status'] == "SUCCESS" && isset($rapydSubscription['data']['id'])){

      $subscription_id = $rapydSubscription['data']['id'];
      $upq = "UPDATE `orders` SET `status` = 'subscribed', `payment_provider_transaction_number` = '$subscription_id', `updated` = now() WHERE `orders`.`id` = $internal_order_id;";
      mysqli_query($DB_LINK, $upq);
      header("location: /order/".$payable_id);

    }
    
  }


  //Shorten the Payment ID for Rapyd if there is one.
  if($order_details['order']['payment_provider_transaction_number']){
    $payment_id_parts = explode('_', $order_details['order']['payment_provider_transaction_number']);
    $order_details['order']['payment_provider_transaction_number'] = $payment_id_parts[1];
  }

}


if($debug){ //dump the order if we are debugging
    echo '<pre>';
    print_r($rapydOrder);
    echo '</pre>';
}


?>