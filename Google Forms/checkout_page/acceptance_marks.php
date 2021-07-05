<?php 

if($order_details['order_payable'] && $order_details['order']['status'] !== 'active' ){ 

//We need to make an array of unique icons since sometimes the card ones are duplicated

$unique_acceptance_icons = array();

foreach($rapydOrder['data']['payment_method_types'] as $a_payment_category){  
  foreach($a_payment_category['types'] as $a_payment_type){  

    if(!isset($unique_acceptance_icons[$a_payment_type['image']])){
      if(!(strpos($a_payment_type['name'], 'Debit') !== false)){
        $unique_acceptance_icons[$a_payment_type['image']] = $a_payment_type['name'];
      }
    }

  }
}

  ?>
<div id="acceptance-marks" >
<ul class="list--inline payment-icons">
  <? foreach($unique_acceptance_icons as $img_url => $img_label){  ?>
    <li><img src="<?=$img_url ?>" alt="<?=$img_label ?>" /></li>
  <? } //end looping through categories ?>
</ul>
</div>
<?php  } //end if it is not paid ?>


<? if($order_details['order']['status'] == 'active'){ // that means the order is not fully complete 

  $payment_instructions = $rapydOrder['data']['payment']['instructions'];

  if(sizeof($payment_instructions) > 0){  //we have some instructions

?>

<h3> Finish your Payment:</h3>
<p> Your order has been placed <strong>BUT</strong> is <strong> NOT COMPLETE</strong> complete.

<? if (!empty($rapydOrder['data']['payment']['textual_codes'])){ ?>


    <? foreach($rapydOrder['data']['payment']['textual_codes'] as $key => $value){ ?>
  <div class="text-code">
    <span class="label"><?=$key; ?>:</span>
    <?=$value; ?>
  </div>
<? } // end loop thorugh textual values ?>

<? } //end if there was a text code to show ?>

<? if (!empty($rapydOrder['data']['payment']['visual_codes'])){ ?>

    <? foreach($rapydOrder['data']['payment']['visual_codes'] as $key => $value){ ?>
  <div class="text-code">
    <span class="label"><?=$key; ?>:</span>
    <img src="<?=$value; ?>" alt="<?=$key; ?>" />
  </div>
<? } // end loop thorugh textual values ?>

<? } //end if there was a visual code to show ?>

<p> You must complete the following steps by <?=date('F jS \a\t g:i a',$rapydOrder['data']['payment']['expiration']); ?> or your order will be cancelled.</p>
<ol>
<? foreach($payment_instructions[0]['steps'][0] as $key => $value){
  echo '<li>'.$value.'</li>';
} ?>
</ol>


<?    }//end if we have payment instructions  ?>
<div id="choose-back" style="display:block;padding-left: 0;padding-right: 0;">
  <p> Change your mind on how you want to pay? <a target="_self" href="/order/<?=$payable_id ?>/restart" >Select a new payment option.</a> </p>
</div>

<? } //emd id the payment is still active ?>