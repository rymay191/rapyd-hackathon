<?php
// **************************************************************** //
//      These are Rapyd Platform Functions Created by Ryan May      //
// **************************************************************** //

function get_rapyd_api_credentials($connection_id, $environment = 'test'){

    global $DB_LINK, $RAPYD_CONFIG;

    $credentials = array();
    $credentials['secret_key'] = '';
    $credentials['access_key'] = '';

    //Lets lookup the connection ID
    if($connection_id){
        $connection_result = mysqli_query($DB_LINK, "SELECT * FROM `payment_provider_connections` WHERE `id` = $connection_id;");
        if(mysqli_num_rows($connection_result) > 0){
            $connection_array = mysqli_fetch_assoc($connection_result);
            //looks like we got something. 
            $credentials_array = json_decode(base64_decode($connection_array['configuration_vars']), true);
            $credentials['merchant_id'] = $credentials_array['merchant_email'];
            $credentials['secret_key'] = $credentials_array[$environment.'_api_secret_key'];
            $credentials['access_key'] = $credentials_array[$environment.'_api_access_key'];
        }else{
            if($environment == 'test'){
                $credentials['secret_key'] = $RAPYD_CONFIG['testing']['secret_key'];
                $credentials['access_key'] = $RAPYD_CONFIG['testing']['access_key'];
            }
        }
    }else{ //looks like there is no connection yet.. 
        if($environment == 'test'){
            $credentials['secret_key'] = $RAPYD_CONFIG['testing']['secret_key'];
            $credentials['access_key'] = $RAPYD_CONFIG['testing']['access_key'];
        }
    }

    return $credentials;

}

function generate_string($length=12) {
    $permitted_chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    return substr(str_shuffle($permitted_chars), 0, $length);
}


// make_request method - Includes the logic to communicate with the Rapyd sandbox server.
function make_request($method, $path, $body = null) {
    $base_url = 'https://sandboxapi.rapyd.net';
    $access_key = 'E7A90DD4B0CE79700754';     // The access key received from Rapyd.
    $secret_key = 'deeee83a256633e6667645c498546003999fd34b493b43c2e5e30bd8203e709cba21c0ae5b9c1a3d';     // Never transmit the secret key by itself.

    $idempotency = generate_string();      // Unique for each request.
    $http_method = $method;                // Lower case.
    $salt = generate_string();             // Randomly generated for each request.
    $date = new DateTime();
    $timestamp = $date->getTimestamp();    // Current Unix time.

    $body_string = !is_null($body) ? json_encode($body,JSON_UNESCAPED_SLASHES) : '';
    $sig_string = "$http_method$path$salt$timestamp$access_key$secret_key$body_string";

    $hash_sig_string = hash_hmac("sha256", $sig_string, $secret_key);
    $signature = base64_encode($hash_sig_string);

    $request_data = NULL;

    if ($method === 'post') {
        $request_data = array(
            CURLOPT_URL => "$base_url$path",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body_string
            
        );
    } else {
        $request_data = array(
            CURLOPT_URL => "$base_url$path",
            CURLOPT_RETURNTRANSFER => true,
        );
    }

    $curl = curl_init();
    curl_setopt_array($curl, $request_data);

    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        "Content-Type: application/json",
        "access_key: $access_key",
        "salt: $salt",
        "timestamp: $timestamp",
        "signature: $signature",
        "idempotency: $idempotency"
    ));

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        throw new Exception("cURL Error #:".$err);
    } else {
        return json_decode($response, true); 
    }
}

//This function will create a Rapyd Checkout Object and return it for consumption by the script.
function create_order_from_payable_id($payable_id, $payment_category = false){

    global $RAPYD_CONFIG, $DB_LINK, $environment;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment_label = 'test';  
    }else{
        $environment_label = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment_label);


    //Figure out the total
    $checkout_amount = $order_details['order']['total'];

    //We are also need to make the URLs
    $payable_order_url = $environment['checkout']['url'].$payable_id;

    //Form Language
    $form_language = substr($order_details['source']['locale'], 0, 2);

    $orderData = [
        'amount'          => $checkout_amount,
        'country'         => $order_details['source']['payment_provider_merchant_countrycode'],
        'currency'        => $order_details['order']['order_currency'],
        'merchant_reference_id' => $payable_id,
        'language' => $form_language,
        'complete_payment_url' => $payable_order_url."?return=complete",
        'error_payment_url' => $payable_order_url."?return=error",
        'description'     => $order_details['source']['g_form_title'],
        "metadata" => [
            'payable_order_id' => $payable_id,
            'payable_source_id' => $order_details['source']['payable_id'],
            'google_form_url' => 'https://docs.google.com/forms/d/'.$order_details['source']['g_form_id'].'/viewform',
            'google_form_submission_id' => $order_details['order']['external_reference_id'],
        ]
    ];

    //If there is a Rapyd customer saved on this order lets link it now.
    if(!empty($order_details['order']['payer'])){
        $orderData['customer'] = $order_details['order']['payer'];
    }

    if($payment_category){ //this is for one specific payment category.

        if( $payment_category == 'bank'){  //Rapyd bank label seems to not matchup so split it into 2
            $orderData['payment_method_type_categories'] = ['bank_redirect', 'bank_transfer']; 
        }else{ //otherwise map, card, ewallet, cash as is
            $orderData['payment_method_type_categories'] = [$payment_category];
        }
    }

    //This is how we need to add line items as they must match the total.
    //However in the iframe they are shown, and we already show them so lets not inlcude.
    // $line_items_array = json_decode($order_details['order']['line_items'], true);
    // $item_count = 0;
    // foreach($line_items_array as $key => $a_line_item){
    //     $orderData['cart_items'][$item_count]['name'] = $a_line_item['item_name'];
    //     $orderData['cart_items'][$item_count]['amount'] = floatval($a_line_item['amount']);
    //     $orderData['cart_items'][$item_count]['quantity'] = 1;
    //     $item_count ++;
    // }

    // if($order_details['order']['handling_total']){
    //     $orderData['cart_items'][$item_count]['name'] = 'Handling';
    //     $orderData['cart_items'][$item_count]['amount'] = floatval($order_details['order']['handling_total']);
    //     $orderData['cart_items'][$item_count]['quantity'] = 1;
    //     $item_count ++;
    // }

    // if($order_details['order']['tax_total']){
    //     $orderData['cart_items'][$item_count]['name'] = 'Taxes';
    //     $orderData['cart_items'][$item_count]['amount'] = floatval($order_details['order']['tax_total']);
    //     $orderData['cart_items'][$item_count]['quantity'] = 1;
    //     $item_count ++;
    // }


    $time_pre = microtime(true);
    $rapydOrder = make_request('post', '/v1/checkout', $orderData);

    //If we make it here, it worked with no exception
    if($rapydOrder['status']['status'] == 'SUCCESS'){
        $rapydOrder['message'] = 'Created new Rapyd Checkout ID';
        $rapydOrder['http_status'] = 201;
        $rapydOrder['created'] = true;
    }else{
        $rapydOrder['message'] = $rapydOrder['status']['error_code'];
        $rapydOrder['http_status'] = 500;
        $rapydOrder['created'] = false;     
    }



    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_checkout_create', json_encode($orderData), json_encode((array)$rapydOrder), $rapydOrder['http_status'], $exec_time);

    //So lets check to make sure it worked ok.
    if($rapydOrder['status']['status'] == 'SUCCESS'){

      $new_rapyd_order_id = $rapydOrder['data']['id'];
      $rapydOrder['id'] = $rapydOrder['data']['id'];
      $rapydOrder['created'] = true;

      //We should update the database with this. 
      $order_update_q = "UPDATE `orders` SET `status` = 'checkout-started', `payment_provider` = 'rapyd', `payment_provider_order_id` = '$new_rapyd_order_id', `updated` = NOW() WHERE `orders`.`id` = $internal_order_id;";

      if(!mysqli_query($DB_LINK, $order_update_q)){
        //Something went wrong saving it in the DB...
        save_log('order:'.$payable_id, 'rapyd_order_create', json_encode($orderData), 'Failed to save rapyd order in database with query: '.$order_update_q, 500, 0);
      };

    }

    return $rapydOrder;

}

//We will use this function to pull the latest checkout / payment status from Rapyd to check for updates ect.
function get_latest_from_rapyd($payable_id){

    global $RAPYD_CONFIG, $DB_LINK;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment = 'test';  
    }else{
        $environment = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment);


    //Figure out the total
    $rapyd_checkout_id = $order_details['order']['payment_provider_order_id'];

    $time_pre = microtime(true);
    $rapydOrder = make_request('get', '/v1/checkout/'.$rapyd_checkout_id);

    //If we make it here, it worked with no exception
    if($rapydOrder['status']['status'] == 'SUCCESS'){
        $rapydOrder['message'] = 'Created new Rapyd Checkout ID';
        $rapydOrder['http_status'] = 200;
        $rapydOrder['created'] = true;
    }else{
        $rapydOrder['message'] = $rapydOrder['status']['error_code'];
        $rapydOrder['http_status'] = 500;
        $rapydOrder['created'] = false;     
    }


    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_checkout_get', '/v1/checkout/'.$rapyd_checkout_id, json_encode((array)$rapydOrder), $rapydOrder['http_status'], $exec_time);

    //So lets check to make sure it worked ok.
    if($rapydOrder['status']['status'] == 'SUCCESS'){

      $rapydOrder['id'] = $rapydOrder['data']['id'];


    }

    return $rapydOrder;


}


//We will use this function to create and manage customer objects with Rapyd 
function create_or_get_rapyd_customer($payable_id){

    global $RAPYD_CONFIG, $DB_LINK;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];
    $internal_source_id = $order_details['order']['source_id'];

    if(!empty($order_details['order']['payer'])){ //looks like we already have one saved with this order.  

        $rapydCustomer['message'] = 'Using Existing Rapyd Customer ID saved with the order';
        $rapydCustomer['http_status'] = 200;
        $rapydCustomer['created'] = true;
        $rapydCustomer['id'] = $order_details['order']['payer'];
        return $rapydCustomer;

    }else if(!empty($order_details['order']['payer_email'])){ //we need an email to have a customer.

        $customer_email = $order_details['order']['payer_email'];
        $test_mode = $order_details['order']['test_mode'];

        // So the weird thing is Rapyd does not seem to let you search for a previous customer based on an identifier (like email) only list them all
        // Because of this we will need to check our own historical order data and see if we have a saved Rapyd checkout with this user before. 
        // This will be faster and easier, and help group checkouts & payments fron the same customer together.

        $payer_search_q = "SELECT *  FROM `orders` WHERE `id` != $internal_order_id AND `source_id` = $internal_source_id AND `test_mode` = $test_mode AND `payer` IS NOT Null AND `payer_email` LIKE '$customer_email'  ORDER BY `orders`.`id`  DESC LIMIT 0,1";
        $previous_customer_result = mysqli_query($DB_LINK, $payer_search_q);

        if(mysqli_num_rows($previous_customer_result) > 0){  //Looks we found them

            $previous_customer_order_details = mysqli_fetch_assoc($previous_customer_result);
            $rapydCustomer['message'] = 'Using previous Rapyd customer ID from order:'.$previous_customer_order_details['payable_id'];
            $rapydCustomer['http_status'] = 200;
            $rapydCustomer['created'] = true;
            $rapydCustomer['id'] = $previous_customer_order_details['payer'];
            

            //lets update this in the database associates with the order.
            $payer_id = $previous_customer_order_details['payer'];
            $order_update_q = "UPDATE `orders` SET `payer` = '$payer_id', `updated` = NOW() WHERE `orders`.`id` = $internal_order_id;";

            if(!mysqli_query($DB_LINK, $order_update_q)){  //Something went wrong saving it in the DB...

                save_log('order:'.$payable_id, 'rapyd_customer_create', json_encode($rapydCustomer), 'Failed to save rapyd customer in database with query: '.$order_update_q, 500, 0);
            };


            return $rapydCustomer;


        }

        //Looks like never used this form before so we will need to create them.
        //Let's set a string we can use to specify the credentials.
        if($order_details['order']['test_mode']){
            $environment = 'test';  
        }else{
            $environment = 'live';
        }

        $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment);
        
        //Since we dont explicitely have a name, lets magically make one from the first half of the email address.
        $customer_name = explode('@', $order_details['order']['payer_email'])[0];
        $customer_name = str_replace('.', ' ', $customer_name);
        $customer_name = str_replace('_', ' ', $customer_name);
        $customer_name = str_replace('-', ' ', $customer_name);
        $customer_name = ucwords($customer_name);

        //set the customer data based on the order
        $customerData = [
            "email" => $customer_email,
            "name" => $customer_name
        ];

        //Lets put the title of the source, so they know where this customer came from.
        if(!empty($order_details['source']['g_form_title'])){
            $customerData['description'] = $order_details['source']['g_form_title'];
        }

        $time_pre = microtime(true);
        $rapydCustomer = make_request('post', '/v1/customers/', $customerData);

        //If we make it here, it worked with no exception
        if($rapydCustomer['status']['status'] == 'SUCCESS'){
            $rapydCustomer['message'] = 'Created new Rapyd Customer ID';
            $rapydCustomer['http_status'] = 200;
            $rapydCustomer['created'] = true;

            //lets update this in the database associates with the order.
            $payer_id = $rapydCustomer['data']['id'];
            $order_update_q = "UPDATE `orders` SET `payer` = '$payer_id', `updated` = NOW() WHERE `orders`.`id` = $internal_order_id;";

            if(!mysqli_query($DB_LINK, $order_update_q)){  //Something went wrong saving it in the DB...

                save_log('order:'.$payable_id, 'rapyd_customer_create', json_encode($rapydCustomer), 'Failed to save rapyd customer in database with query: '.$order_update_q, 500, 0);
            };

            $rapydCustomer['id'] = $payer_id;

        }else{
            $rapydCustomer['message'] = $rapydCustomer['status']['error_code'];
            $rapydCustomer['http_status'] = 500;
            $rapydCustomer['created'] = false;     
        }


        //Let's stop the timer and log this interaction quickly
        $time_post = microtime(true);
        $exec_time = $time_post - $time_pre;
        save_log('order:'.$payable_id, 'rapyd_customer_create', json_encode($customerData), json_encode($rapydCustomer), $rapydCustomer['http_status'], $exec_time);


    }else{ //email was empty so no customer for this one.
            $rapydCustomer['message'] = 'No email was associated with this order';
            $rapydCustomer['http_status'] = 400;
            $rapydCustomer['created'] = false;   
    }

    return $rapydCustomer;


}


// The following functions are needed to support recurring product subscriptions. 
// It is a bit of beast, need a product, plan, and subscriptino all linked together (Buckle Up)


//This function will create a Rapyd Product for our recurring feature it is mapped 1:1 as the Google Form
function create_rapyd_product($payable_id){

    global $RAPYD_CONFIG, $DB_LINK, $environment;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment_label = 'test';  
    }else{
        $environment_label = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment_label);

    //  //Lets make the product to send to Rapyd based on the source in the details. 
    $request_body = array();
    $request_body['id'] = str_replace('-', '_', $order_details['order']['recurring_details']['product_id']); //No dashes allowd by Rapyd
    if(!empty($order_details['source']['g_form_title'])){
        $request_body['name'] = substr($order_details['source']['g_form_title'],0,127);
    }else{
        $request_body['name'] = 'g_form:'+$order_details['source']['payable_id'];
    }
    if(!empty($order_details['source']['g_form_description'])){
        $request_body['description'] = substr($order_details['source']['g_form_description'],0,256);
    }
    if($order_details['source']['shipping_address_needed']){
        $request_body['shippable'] = true;
    }else{
        $request_body['shippable'] = false;
    }
    $request_body['active'] = true;
    $request_body['type'] = 'service';
    $request_body['metadata'] = array("google_form_url" => 'https://docs.google.com/forms/d/'.$order_details['source']['g_form_id'].'/viewform');


    $time_pre = microtime(true);
    $rapydProduct = make_request('post', '/v1/products', $request_body);

    //If we make it here, it worked with no exception
    if($rapydProduct['status']['status'] == 'SUCCESS'){
        $rapydProduct['message'] = 'Created new Rapyd Product';
        $rapydProduct['http_status'] = 201;
        $rapydProduct['created'] = true;
    }else{
        $rapydProduct['message'] = $rapydProduct['status']['error_code'];
        $rapydProduct['http_status'] = 500;
        $rapydProduct['created'] = false;     
    }



    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_product_create', json_encode($request_body), json_encode((array)$rapydProduct), $rapydProduct['http_status'], $exec_time);


    return $rapydProduct;

}



//This function will create a Rapyd Plan it is Mapped based on the users Google Form Choices / Recurring Items selected.
function create_rapyd_plan($payable_id){

    global $RAPYD_CONFIG, $DB_LINK, $environment;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment_label = 'test';  
    }else{
        $environment_label = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment_label);

    //  //Lets make the product to send to Rapyd based on the source in the details. 
    $request_body = array();
    $request_body['id'] = $order_details['order']['recurring_details']['plan_id'];
    $request_body['product'] = str_replace('-', '_', $order_details['order']['recurring_details']['product_id']); //No dashes allowd by Rapyd
    $request_body['currency'] = $order_details['order']['order_currency'];
    $request_body['interval'] = strtolower($order_details['order']['recurring_details']['frequency']);
    $request_body['nickname'] = $order_details['order']['recurring_details']['plan_name'];

    //Taxes and Convenience Fee at Rapyd have no separate parameters it seems, so lets add it into the recurring total.
    $recurring_total = $order_details['order']['recurring_details']['total'];   
    if($order_details['source']['add_convenience_fee']){
        $recurring_convenience_fee = round($recurring_total*$order_details['source']['add_convenience_fee'],2);
        $recurring_total += $recurring_convenience_fee;
    }

    if($order_details['source']['add_taxes']){
        $recurring_taxes = round($recurring_total*$order_details['source']['add_taxes'],2);
        $recurring_total += $recurring_convenience_fee;
    }

    $request_body['amount'] = $recurring_total;

    $time_pre = microtime(true);
    $rapydPlan = make_request('post', '/v1/plans', $request_body);

    //If we make it here, it worked with no exception
    if($rapydPlan['status']['status'] == 'SUCCESS'){
        $rapydPlan['message'] = 'Created new Rapyd Product';
        $rapydPlan['http_status'] = 201;
        $rapydPlan['created'] = true;
    }else{
        $rapydPlan['message'] = $rapydPlan['status']['error_code'];
        $rapydPlan['http_status'] = 500;
        $rapydPlan['created'] = false;     
    }



    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_plan_create', json_encode($request_body), json_encode((array)$rapydPlan), $rapydPlan['http_status'], $exec_time);


    return $rapydPlan;

}


//This function will create a Rapyd Subscription for an order that has made it to a status of paid.
function create_rapyd_subscription($payable_id){

    global $RAPYD_CONFIG, $DB_LINK, $environment;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //get the latest from Rapyd
    $rapyd_checkout = get_latest_from_rapyd($payable_id);

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment_label = 'test';  
    }else{
        $environment_label = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment_label);

    //  //Lets make the product to send to Rapyd based on the source in the details. 
    $request_body = array();
    $request_body['type'] = 'payment'; //We want to receive money
    $request_body['billing'] = 'pay_automatically';
    $request_body['cancel_at_period_end'] = false; //we want this to go on forever.
    $request_body['customer'] = $order_details['order']['payer'];  //Use the Rapyd customer ID saved with the order.
    $request_body['tax_percent'] = $order_details['source']['add_taxes']*100;  //Multiple to whole number compared to what we have.
    $request_body['subscription_items'] = array([
        'plan' => $order_details['order']['recurring_details']['plan_id'],
        'quantity' => 1
    ]);

    //Since we have already charged the user for the total initially we push the billing cycle anchor out one interval from right now.
    $subscription_start_timestamp = strtotime("+1 ".$order_details['order']['recurring_details']['frequency']);
    $request_body['billing_cycle_anchor'] = $subscription_start_timestamp;

    //Now we are going to load up the subscription information based on the result of the initial transaction.
    $request_body['payment_method'] = $rapyd_checkout['data']['payment']['payment_method'];

    $time_pre = microtime(true);
    $rapydSubscription = make_request('post', '/v1/payments/subscriptions', $request_body);

    //If we make it here, it worked with no exception
    if($rapydSubscription['status']['status'] == 'SUCCESS'){
        $rapydSubscription['message'] = 'Created new Rapyd Product';
        $rapydSubscription['http_status'] = 201;
        $rapydSubscription['created'] = true;
    }else{
        $rapydSubscription['message'] = $rapydSubscription['status']['error_code'];
        $rapydSubscription['http_status'] = 500;
        $rapydSubscription['created'] = false;     
    }



    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_subscription_create', json_encode($request_body), json_encode((array)$rapydSubscription), $rapydSubscription['http_status'], $exec_time);


    return $rapydSubscription;

}

//This function will create a Rapyd Subscription for an order that has made it to a status of paid.
function get_subscription_latest_from_rapyd($payable_id){

    global $RAPYD_CONFIG, $DB_LINK, $environment;

    // Step 1 is to load the order from the database
    $order_details = get_order($payable_id, true);
    $internal_order_id = $order_details['order']['id'];

    //get the latest from Rapyd
    $rapyd_checkout = get_latest_from_rapyd($payable_id);

    //Let's set a string we can use to specify the credentials.
    if($order_details['order']['test_mode']){
        $environment_label = 'test';  
    }else{
        $environment_label = 'live';
    }

    $rapyd_api_credentials = get_rapyd_api_credentials($order_details['source']['payment_provider_connection_id'], $environment_label);

    //Now we are going to load up the subscription information based on the result of the initial transaction.
    $request_body['payment_method'] = $rapyd_checkout['data']['payment']['payment_method'];

    $time_pre = microtime(true);
    $rapydSubscription = make_request('post', '/v1/payments/subscriptions/'.$order_details['order']['payment_provider_transaction_number'], $request_body);

    //If we make it here, it worked with no exception
    if($rapydSubscription['status']['status'] == 'SUCCESS'){
        $rapydSubscription = $rapydSubscription['data'];
        $rapydSubscription['plan_id'] = $order_details['order']['recurring_details']['plan_id'];
        $rapydSubscription['billing_info']['next_billing_time'] = date('Y-m-d H:i:s', $rapydSubscription['current_period_end']);
        $rapydSubscription['message'] = 'Created new Rapyd Product';
        $rapydSubscription['http_status'] = 201;
        $rapydSubscription['created'] = true;
    }else{
        $rapydSubscription['message'] = $rapydSubscription['status']['error_code'];
        $rapydSubscription['http_status'] = 500;
        $rapydSubscription['created'] = false;     
    }


    //Let's stop the timer and log this interaction quickly
    $time_post = microtime(true);
    $exec_time = $time_post - $time_pre;
    save_log('order:'.$payable_id, 'rapyd_subscription_get', '/v1/payments/subscriptions/'.$order_details['order']['payment_provider_transaction_number'], json_encode((array)$rapydSubscription), $rapydSubscription['http_status'], $exec_time);


    return $rapydSubscription;

}

?>