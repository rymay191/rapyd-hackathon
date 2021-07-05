<div id="rapyd-category-buttons" class="payment-method-part">

  <? foreach($rapydOrder['data']['payment_method_types'] as $a_payment_category){  ?>
  <div class="row">
      <button style="width:100%" id="rapyd-button_<?=$a_payment_category['category']; ?>" class="rapyd-payment-category-button pay-button  freebirdSolidBackground">
        <? if($a_payment_category['category'] == 'ewallet'){ $a_payment_category['category'] = 'e-wallet'; } ?>
        <img class="payment_category_icon" src="https://iconslib.rapyd.net/assets/hosted-pages/images/<?=$a_payment_category['category']; ?>.svg" />
        Pay by <?=ucwords($a_payment_category['category']); ?></button>
  </div>
  <? } //end loop through eligibale payment categories ?>

</div>


<div id="rapyd-checkout" class="payment-method-part" style="display: none;"></div>

<div id="choose-back">
  <p> Change your mind on how you want to pay? <a target="_self" href="/order/<?=$payable_id ?>" >Select a new payment option.</a> </p>
</div>

<div class="freebirdFormviewerViewHeaderDescription" id="processing-container" >

  <span>Setting Up Payment Options<br><em>Hang tight just a second...</em></span>

</div>


<script>

// Declare some Global Variables.
var checkout; //use this as a placeholder for the rapyd checkout object
var button_color = 'blue';  //use this as a placeholder for dymanic button colors
var rapyd_payment_category = false;  //for what category button the user selected.
var category_label = '';  //For how we show it to the user.

window.addEventListener('onCheckoutPaymentSuccess', function (event) {
    console.log(event.detail)

      //Lets customize the loading label
      $('#processing-container span').text('Finishing '+category_label+' Payment');

      //lets make sure the we clear out any current html:
      $('#rapyd-checkout').empty();
      $('#choose-back').hide();
      //Show the loading screen.
      $('#processing-container').show();


      //We now need to reach out to our server to update the checkou with just this payment category.
      $.post( "/process/finish-rapyd-order.php",  
        {
          payable_id: "<?=$payable_id ?>",
          order_id: checkout.currentToken,
          payment_category: event.detail.payment_method_type_category,
          payment_id: event.detail.id,
          payment_method: event.detail.payment_method_type,      
        },
        function(data){
          console.log(data);
          if(data.success){ //looks like it saved and all is well to move on.


            if(data.redirect_url){ //we have one more step

              //Lets set the completed class on the procssing div to change the styles.
              //$('#processing-container').addClass('error');

              //Lets generate a nice personalized thakyou message.
              var new_message_html ="<span>CONFIRMATION REQUIRED";
              new_message_html += "<br><em>We will redirect you in 3 seconds. </em></span>";
              $('#processing-container span').html(new_message_html);
              
              //Lets automatcially move the user on to the receipt by reloading the page.
              setTimeout(function() {
                      window.location.replace(data.redirect_url);
                }, 4000);

            }else{ //sems like no redirect so lets just go to receipt

              //Lets set the completed class on the procssing div to change the styles.
              $('#processing-container').addClass('done');

              //Lets generate a nice personalized thakyou message.
              var new_message_html ="<span>THANKS!";
              new_message_html += "<br><em>We will take you to your receipt in 3 seconds. </em></span>";
              $('#processing-container span').html(new_message_html);
              
              //Lets automatcially move the user on to the receipt by reloading the page.
              setTimeout(function() {
                      document.location.reload();
                }, 4000);
            }

            ask_google_to_refresh();

          }else{
            alert('It looks like we had some trouble setting up the Rapyd Checkout.');
            $('#rapyd-category-buttons').show();
          }
        

      });



});



window.addEventListener('onCheckoutFailure', function (event) {
    console.log(event.detail.error)
});


$( document ).ready(function() {

  var button_text_color = $('.freebirdSolidBackground').css('color');
  var button_color = $('.freebirdSolidBackground').css('background-color');

  if(button_text_color == 'rgb(0, 0, 0)'){
    $('.payment_category_icon').addClass('payment_category_icon_dark');
    $('.payment_category_icon_dark').removeClass('payment_category_icon');
  }


  $('button.rapyd-payment-category-button').on( "click", function() {
    rapyd_payment_category = $(this).attr('id').split('_')[1];
    category_label = rapyd_payment_category.charAt(0).toUpperCase() + rapyd_payment_category.slice(1);

    console.log('Rapyd Button Clicked: '+rapyd_payment_category);
    button_color = $(this).css("background-color");
    text_color = $(this).css("color");
    console.log()

    //Lets customize the loading label
    $('#processing-container span').text('Setting Up '+category_label+' Payment Options');

    //lets make sure the we clear out any current html:
    $('#rapyd-checkout').empty();

    //Hide the buttons abd show the loading screen.
    $('#rapyd-category-buttons').hide();
    $('#payment-option-header').hide();
    $('#processing-container').show();


    //We now need to reach out to our server to update the checkou with just this payment category.
    $.post( "/process/update-rapyd-order.php",  
      {
        payable_id: "<?=$payable_id ?>",
        order_id: "<?=$rapyd_order_id; ?>",
        payment_category: rapyd_payment_category         
      },
      function(data){
        console.log(data);
        if(data.status.status == "SUCCESS"){
          console.log()
          checkout = new RapydCheckoutToolkit({
            pay_button_text: "Pay With "+category_label,
            pay_button_color: button_color,
            id: data.id,
             style: {
                global: {
                  fonts: ['helvetica', 'tahoma', 'calibri', 'sans-serif']
              },
              submit: {
                  base: {
                      color: button_text_color,
                      width: '100%',
                      height: '55px',
                      borderRadius: '3px',
                      boxShadow: '0 0 2px rgb(0 0 0 / 12%), 0 2px 2px rgb(0 0 0 / 20%)'
                  }
              },
              input: {
                base: {

                },
                focus: {

                },
                active:{
                  borderColor: button_color,
                  labelColor: '#202124'
                },
                error: {
                  labelColor:'#202124',
                  borderColor: '#d93025'
                }
              },
              dropdown: {
                base: {
                  width: '100%'
                },
                focus: {

                },
                active:{
                  borderColor: button_color
                }
              }
            }
          });

          checkout.displayCheckout();
          setTimeout(function() { 
            $('#rapyd-checkout').show(); 
            $('#choose-back').show();
          }, 1500);
          

        }else{
          alert('It looks like we had some trouble setting up the Rapyd Checkout.');
          $('#rapyd-category-buttons').show();
          $('#payment-option-header').show();
        }
        setTimeout(function() { $('#processing-container').hide(); }, 1500);
        

    });

  });

});


function ask_google_to_refresh(){

  var myHeaders = new Headers();
  myHeaders.append("Authorization", google_auth);
  myHeaders.append("Content-Type", "application/json");

  var raw = JSON.stringify({"function":"checkForOrderUpdates","parameters":[{"g_form_id": g_form_id}],"sessionState":"started","devMode":false});

  var requestOptions = {
    method: 'POST',
    headers: myHeaders,
    body: raw,
    redirect: 'follow'
  };

  fetch("https://script.googleapis.com/v1/scripts/1Zybb2b6GdegBIagT0qRPgg2tQIQwug7LFYG1kR74YFhN9Bo1c4_OCPCz:run", requestOptions);
}

rgb2Hex = s => s.match(/[0-9]+/g).reduce((a, b) => a+(b|256).toString(16).slice(1), '0x');


</script>