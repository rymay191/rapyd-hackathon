//*******************************************************************//
//********************* Rapyd G-Force Invoicing *********************//
//*******************************************************************//

//*******************************************************************//
//*********** Formula 0001 Hackathon - June 2021 ********************//
//*******************************************************************//




//This is where you woud declare your global configuration variables
//Replace the below api keys with your own to see the invoices in your Client Portal
var RAPYD_CONFG = {
  'business_return_url': 'https://example.com/', //Where to send users after payment
  'default_country': 'SG', 
  'default_currency': 'SGD',
  'api_domain' : 'sandboxapi.rapyd.net', //production is: api.rapyd.net
  'api_secret_key': '', //Put your API Secret Key Here
  'api_access_key': '' //Put your API Access Key Here
}

/**
 * Creates a Rapyd Customer so you can link them to checkouts, payments, and save payment methods.
 *
 * @param {string} name - The name of the individual customer or the business name.
 * @param {string} email - [OPTIONAL] Identifier for the transaction. Defined by the merchant. Can be used for reconciliation..
 * @param {string} phone_number - [OPTIONAL] Customer's primary phone number in E.164 format.
 * @return A Rapyd checkout id
 * @customFunction
 */
function RAPYD_CUSTOMER_CREATE(name, email = false, phone_number = false) {

  if(name == ''){ //We need a name at minimum to get started.
  
    return '';
    
  }else{ //Looks like we have enough to get started. 
  
    var request_body = {
      "name": name,
      "email": email,
      "phone_number": phone_number
    }
    
    var cache = CacheService.getScriptCache();
    //cache.remove(JSON.stringify(request_body));
    var cachedRapydCustomer = JSON.parse(cache.get(JSON.stringify(request_body)));
    
    if (cachedRapydCustomer != null) {
      console.log('Returning Cashed Rapyd User');
      console.log(cachedRapydCustomer);
      return cachedRapydCustomer.data.id;
    }else{
      console.log('Creating New Rapyd Customer');
      var RapydCustomer = make_rapyd_api_call('POST', '/v1/customers',request_body);
      
      if(RapydCustomer.status.status == 'SUCCESS'){
        cache.put(JSON.stringify(request_body), JSON.stringify(RapydCustomer), 60*60*8); // cache for 8 Hours
        return RapydCustomer.data.id;
      }else{
        return RapydCustomer.status.error_code;
      }
      
    }//end if this customer is not in the cashe
  
  }//end if we have enough customer data

}


/**
 * Creates a Rapyd Checkout to send your customer to for payment submission.
 *
 * @param {number} total_amount - Grand total amount.
 * @param {string} merchant_refference_id -  Identifier for the transaction. Defined by the merchant. Required for tracking and reconcilation.
 * @param {string} rapyd_customer_id - [OPTIONAL] A Rapyd Customer Id to associate this checkout with. 
 * @param {string} currency - [OPTIONAL] Currency you are requesting.
 * @param {number} country - [OPTIONAL] Country the buyer is from.
 * @param {number} return_value - [OPTIONAL] Can override what value is returned to the sheet in the create request.
 * @return A Rapyd checkout id
 * @customFunction
 */
function RAPYD_CHECKOUT_CREATE(total_amount = 0.00, merchant_reference_id = false, rapyd_customer_id = false, currency = false, country = false, return_value = 'id') {

  // We are making merchant_reference_id a required field.  This is so we do not crete duplicate Rapyd checkouts by accident when the page reloads.
  // We will leverage this documents a property storage to save these on a document by document basis.
  var documentProperties = PropertiesService.getDocumentProperties();

  //Lets take a quick look if we already have this 
  var existing_checkout = documentProperties.getProperty(merchant_reference_id);
  
  if(!existing_checkout){ //we need to reach out to Rapyd and create a checkout
  
     console.log('Creating a new Rapyd Checkout ID for Merchant Reference: '+merchant_reference_id);
  
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var sheet = ss.getActiveSheet()
    var thisSheetName = sheet.getName();
  
    //lets define the optional variables if left out
    if(!currency){currency = RAPYD_CONFG.default_currency;}
    if(!country){country = RAPYD_CONFG.default_country;}
    
    var request_body = {
      "amount": total_amount,
      "customer": rapyd_customer_id,
      "country": country,
      "currency": currency,
      "merchant_reference_id": merchant_reference_id,
      "complete_payment_url": RAPYD_CONFG.business_return_url+'?payment=complete&merchant_reference_id='+merchant_reference_id,
      "error_payment_url": RAPYD_CONFG.business_return_url+'?payment=error&merchant_reference_id='+merchant_reference_id,
      "language": "en",
      "metadata": {
          "source": "Google Sheet",
          "source_name": thisSheetName
      }
    }
    
    
    var RapydCheckout = make_rapyd_api_call('POST', '/v1/checkout',request_body);
    
    if(RapydCheckout.status.status == 'SUCCESS'){
      
      //Lets save this in the document storage for later
      documentProperties.setProperty(merchant_reference_id, JSON.stringify(RapydCheckout));
      
      //lets make this into a link
      return RapydCheckout.data[return_value];
      
      
    }else{
      return RapydCheckout.status.error_code;
    }
    
  }else{ //It seems as though we do have this Rapyd Checkout already saved in document storage so lets just return that one.
  
    console.log('Found an existing Rapyd Checkout for Merchant Reference ('+merchant_reference_id+') so we will return that checkout ID instead of a new one.');
    var RapydCheckout = JSON.parse(existing_checkout);
    return RapydCheckout.data[return_value];
  
  }

}

/**
 * Creates a Rapyd Checkout to send your customer to for payment submission.
 *
 * @param {string} rapyd_checkout_id - An existing Rapyd Checkout Identifier you want to get additional information about.
 * @param {number} return_value - [OPTIONAL] Can override what value is returned to the sheet in the create request.
 * @return A Rapyd checkout id
 * @customFunction
 */
function RAPYD_CHECKOUT_GET(rapyd_checkout_id, return_value = 'status') {
  
  
 var cache = CacheService.getScriptCache();
 
  var cachedRapydCheckout = JSON.parse(cache.get(rapyd_checkout_id));
  
  if (cachedRapydCheckout != null) {
  
    console.log('Returning Cashed Rapyd Checkout');
    var RapydCheckout = cachedRapydCheckout;
    
  }else{  //we have to reach out and get it.
  
    var RapydCheckout = make_rapyd_api_call('GET', '/v1/checkout/'+rapyd_checkout_id);
      if(RapydCheckout.status.status == 'SUCCESS'){
        //lets make this into a link
        cache.put(rapyd_checkout_id, JSON.stringify(RapydCheckout), 60); // cache for 1 Minute
      }else{
        return RapydCheckout.status.error_code;
      }
  }
  
  //Now we need to figure out which attribute the user wanted and whether its related to the checkout or the payment object
  //So we can go three levels deep to find the data the user wants.
  var return_value_parts = return_value.split('.');
  if(return_value_parts.length == 3){
      return RapydCheckout.data[return_value_parts[0]][return_value_parts[1]][return_value_parts[2]];
  }else if(return_value_parts.length == 2){
      if(return_value == "payment.paid_at"){
        if(RapydCheckout.data['payment']['paid_at']){
            return new Date(RapydCheckout.data['payment']['paid_at'] * 1000);
          }else{
            return ''; //It hasnt been paid yet
          }
      }else{
        return RapydCheckout.data[return_value_parts[0]][return_value_parts[1]];
      }
      
  }else{
    if(return_value == 'status'){  //If they are looking for the status of the checkout
    
    //We need to just take a look because it seems as though Rapyd is not updating Checkout.status to CLO when it has checkout.payment.status of CLO.  
    //So to make this easy for people to understand, lets map that over.
    if(RapydCheckout.data['status'] == "ACT" && RapydCheckout.data['payment']['status'] == "CLO"){
      return RapydCheckout.data['payment']['status'];
    }else{
      return RapydCheckout.data['status'];
    }
    
    }else{
      return RapydCheckout.data[return_value];
    }
    
  }

}



/**
 * Creates an QR Code Image which contains a Rapyd Checkout URL to send your customer to for payment submission.
 *
 * @param {string} rapyd_checkout_id - An existing Rapyd Checkout Identifier you want to get additional information about.
 * @param {number} size - [OPTIONAL] Can override the default resolution of 400x400
 * @return An Image URL which will display a QR code
 * @customFunction
 */
function RAPYD_CHECKOUT_QR(rapyd_checkout_id, size = 400) {
  
  //We can do this one nice and fast without even using an API.
  //Lets Build the base URL
  if(RAPYD_CONFG.api_domain == 'sandboxcheckout.rapyd.net'){ //we are just testing
    var rapyd_checkout_url = 'https://sandboxcheckout.rapyd.net/?token='+rapyd_checkout_id;
  }else{  //looks like it is live
    var rapyd_checkout_url = 'https://checkout.rapyd.net/?token='+rapyd_checkout_id;
  }
  
  var QR_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size='+size+'x'+size+'&data='+encodeURI(rapyd_checkout_url);
  
  return QR_image_url;

}


function clear_all_saved_data(){
  // Delete all user properties in the current script.
  var documentProperties = PropertiesService.getDocumentProperties();
  documentProperties.deleteAllProperties();
}


function onOpen() {
  var ui = SpreadsheetApp.getUi();
  // Or DocumentApp or FormApp.
  ui.createMenu('Rapyd G Force')
      .addItem('First item', 'menuItem1')
      .addSeparator()
      .addSubMenu(ui.createMenu('Reset')
          .addItem('Clear Saved Data', 'clear_all_saved_data'))
      .addToUi();
}




//*******************************************************//
//*********** Helper Functions Below ********************//
//*******************************************************//



// -- Rapyd has a pretty complex REST API encrption & security so we will use this below function
// -- to standardize making API requests for the above services and actions.
// -- If you are reading this, it took me over 2 hours of debugging to get this simple function to work properly in Google Script.
// -- So few... For you this should be easy : ) - No need to edit anything down here.

function make_rapyd_api_call(method = 'GET', endpoint = '/v1/payments', request_data = false){

  // Make a POST request with a JSON payload.
  

  var body = '';
  if (!request_data){
    body = '';
  }else{
    body = JSON.stringify(request_data);
  }
  
  //Lets prepare all the important encrption stuff.
  var timestamp = (Math.floor(new Date().getTime() / 1000) - 10).toString();
  var signature_salt = String(CryptoJS.lib.WordArray.random(12));
  var secret = RAPYD_CONFG.api_secret_key;
  var access_key = RAPYD_CONFG.api_access_key;
  var to_sign = method.toLowerCase() + endpoint + signature_salt + timestamp + RAPYD_CONFG.api_access_key + secret + body;
  var hash_sig_string = CryptoJS.enc.Hex.stringify(CryptoJS.HmacSHA256(to_sign, secret));
  var rapyd_signature = Utilities.base64Encode(hash_sig_string);

  //Prep the Google App Script Request Options
  var options = {
    'followRedirects': true,
    'muteHttpExceptions': true,
    'method' : method.toLowerCase(),
    'headers' : {
      'access_key': access_key,
      'salt': signature_salt,
      'timestamp': timestamp,
      'signature': rapyd_signature,
      'Connection': 'keep-alive' 
    },
    'contentType': 'application/json',
    'payload' : body
  };
  
  console.log('Making Rapyd API Request to: '+'https://'+RAPYD_CONFG.api_domain+endpoint);
  console.log(options);
  var rapydResponse = UrlFetchApp.fetch('https://'+RAPYD_CONFG.api_domain+endpoint, options);

  console.log(rapydResponse);
  
  var response_object = JSON.parse(rapydResponse.getContentText());
  
  console.log(response_object);
  
  return response_object;


}

/*
CryptoJS v3.1.2
code.google.com/p/crypto-js
(c) 2009-2013 by Jeff Mott. All rights reserved.
code.google.com/p/crypto-js/wiki/License
*/
var CryptoJS=CryptoJS||function(h,s){var f={},g=f.lib={},q=function(){},m=g.Base={extend:function(a){q.prototype=this;var c=new q;a&&c.mixIn(a);c.hasOwnProperty("init")||(c.init=function(){c.$super.init.apply(this,arguments)});c.init.prototype=c;c.$super=this;return c},create:function(){var a=this.extend();a.init.apply(a,arguments);return a},init:function(){},mixIn:function(a){for(var c in a)a.hasOwnProperty(c)&&(this[c]=a[c]);a.hasOwnProperty("toString")&&(this.toString=a.toString)},clone:function(){return this.init.prototype.extend(this)}},
r=g.WordArray=m.extend({init:function(a,c){a=this.words=a||[];this.sigBytes=c!=s?c:4*a.length},toString:function(a){return(a||k).stringify(this)},concat:function(a){var c=this.words,d=a.words,b=this.sigBytes;a=a.sigBytes;this.clamp();if(b%4)for(var e=0;e<a;e++)c[b+e>>>2]|=(d[e>>>2]>>>24-8*(e%4)&255)<<24-8*((b+e)%4);else if(65535<d.length)for(e=0;e<a;e+=4)c[b+e>>>2]=d[e>>>2];else c.push.apply(c,d);this.sigBytes+=a;return this},clamp:function(){var a=this.words,c=this.sigBytes;a[c>>>2]&=4294967295<<
32-8*(c%4);a.length=h.ceil(c/4)},clone:function(){var a=m.clone.call(this);a.words=this.words.slice(0);return a},random:function(a){for(var c=[],d=0;d<a;d+=4)c.push(4294967296*h.random()|0);return new r.init(c,a)}}),l=f.enc={},k=l.Hex={stringify:function(a){var c=a.words;a=a.sigBytes;for(var d=[],b=0;b<a;b++){var e=c[b>>>2]>>>24-8*(b%4)&255;d.push((e>>>4).toString(16));d.push((e&15).toString(16))}return d.join("")},parse:function(a){for(var c=a.length,d=[],b=0;b<c;b+=2)d[b>>>3]|=parseInt(a.substr(b,
2),16)<<24-4*(b%8);return new r.init(d,c/2)}},n=l.Latin1={stringify:function(a){var c=a.words;a=a.sigBytes;for(var d=[],b=0;b<a;b++)d.push(String.fromCharCode(c[b>>>2]>>>24-8*(b%4)&255));return d.join("")},parse:function(a){for(var c=a.length,d=[],b=0;b<c;b++)d[b>>>2]|=(a.charCodeAt(b)&255)<<24-8*(b%4);return new r.init(d,c)}},j=l.Utf8={stringify:function(a){try{return decodeURIComponent(escape(n.stringify(a)))}catch(c){throw Error("Malformed UTF-8 data");}},parse:function(a){return n.parse(unescape(encodeURIComponent(a)))}},
u=g.BufferedBlockAlgorithm=m.extend({reset:function(){this._data=new r.init;this._nDataBytes=0},_append:function(a){"string"==typeof a&&(a=j.parse(a));this._data.concat(a);this._nDataBytes+=a.sigBytes},_process:function(a){var c=this._data,d=c.words,b=c.sigBytes,e=this.blockSize,f=b/(4*e),f=a?h.ceil(f):h.max((f|0)-this._minBufferSize,0);a=f*e;b=h.min(4*a,b);if(a){for(var g=0;g<a;g+=e)this._doProcessBlock(d,g);g=d.splice(0,a);c.sigBytes-=b}return new r.init(g,b)},clone:function(){var a=m.clone.call(this);
a._data=this._data.clone();return a},_minBufferSize:0});g.Hasher=u.extend({cfg:m.extend(),init:function(a){this.cfg=this.cfg.extend(a);this.reset()},reset:function(){u.reset.call(this);this._doReset()},update:function(a){this._append(a);this._process();return this},finalize:function(a){a&&this._append(a);return this._doFinalize()},blockSize:16,_createHelper:function(a){return function(c,d){return(new a.init(d)).finalize(c)}},_createHmacHelper:function(a){return function(c,d){return(new t.HMAC.init(a,
d)).finalize(c)}}});var t=f.algo={};return f}(Math);
(function(h){for(var s=CryptoJS,f=s.lib,g=f.WordArray,q=f.Hasher,f=s.algo,m=[],r=[],l=function(a){return 4294967296*(a-(a|0))|0},k=2,n=0;64>n;){var j;a:{j=k;for(var u=h.sqrt(j),t=2;t<=u;t++)if(!(j%t)){j=!1;break a}j=!0}j&&(8>n&&(m[n]=l(h.pow(k,0.5))),r[n]=l(h.pow(k,1/3)),n++);k++}var a=[],f=f.SHA256=q.extend({_doReset:function(){this._hash=new g.init(m.slice(0))},_doProcessBlock:function(c,d){for(var b=this._hash.words,e=b[0],f=b[1],g=b[2],j=b[3],h=b[4],m=b[5],n=b[6],q=b[7],p=0;64>p;p++){if(16>p)a[p]=
c[d+p]|0;else{var k=a[p-15],l=a[p-2];a[p]=((k<<25|k>>>7)^(k<<14|k>>>18)^k>>>3)+a[p-7]+((l<<15|l>>>17)^(l<<13|l>>>19)^l>>>10)+a[p-16]}k=q+((h<<26|h>>>6)^(h<<21|h>>>11)^(h<<7|h>>>25))+(h&m^~h&n)+r[p]+a[p];l=((e<<30|e>>>2)^(e<<19|e>>>13)^(e<<10|e>>>22))+(e&f^e&g^f&g);q=n;n=m;m=h;h=j+k|0;j=g;g=f;f=e;e=k+l|0}b[0]=b[0]+e|0;b[1]=b[1]+f|0;b[2]=b[2]+g|0;b[3]=b[3]+j|0;b[4]=b[4]+h|0;b[5]=b[5]+m|0;b[6]=b[6]+n|0;b[7]=b[7]+q|0},_doFinalize:function(){var a=this._data,d=a.words,b=8*this._nDataBytes,e=8*a.sigBytes;
d[e>>>5]|=128<<24-e%32;d[(e+64>>>9<<4)+14]=h.floor(b/4294967296);d[(e+64>>>9<<4)+15]=b;a.sigBytes=4*d.length;this._process();return this._hash},clone:function(){var a=q.clone.call(this);a._hash=this._hash.clone();return a}});s.SHA256=q._createHelper(f);s.HmacSHA256=q._createHmacHelper(f)})(Math);
(function(){var h=CryptoJS,s=h.enc.Utf8;h.algo.HMAC=h.lib.Base.extend({init:function(f,g){f=this._hasher=new f.init;"string"==typeof g&&(g=s.parse(g));var h=f.blockSize,m=4*h;g.sigBytes>m&&(g=f.finalize(g));g.clamp();for(var r=this._oKey=g.clone(),l=this._iKey=g.clone(),k=r.words,n=l.words,j=0;j<h;j++)k[j]^=1549556828,n[j]^=909522486;r.sigBytes=l.sigBytes=m;this.reset()},reset:function(){var f=this._hasher;f.reset();f.update(this._iKey)},update:function(f){this._hasher.update(f);return this},finalize:function(f){var g=
this._hasher;f=g.finalize(f);g.reset();return g.finalize(this._oKey.clone().concat(f))}})})();

/*
CryptoJS v3.1.2
code.google.com/p/crypto-js
(c) 2009-2013 by Jeff Mott. All rights reserved.
code.google.com/p/crypto-js/wiki/License
*/
(function () {
    // Shortcuts
    var C = CryptoJS;
    var C_lib = C.lib;
    var WordArray = C_lib.WordArray;
    var C_enc = C.enc;

    /**
     * Base64 encoding strategy.
     */
    var Base64 = C_enc.Base64 = {
        /**
         * Converts a word array to a Base64 string.
         *
         * @param {WordArray} wordArray The word array.
         *
         * @return {string} The Base64 string.
         *
         * @static
         *
         * @example
         *
         *     var base64String = CryptoJS.enc.Base64.stringify(wordArray);
         */
        stringify: function (wordArray) {
            // Shortcuts
            var words = wordArray.words;
            var sigBytes = wordArray.sigBytes;
            var map = this._map;

            // Clamp excess bits
            wordArray.clamp();

            // Convert
            var base64Chars = [];
            for (var i = 0; i < sigBytes; i += 3) {
                var byte1 = (words[i >>> 2]       >>> (24 - (i % 4) * 8))       & 0xff;
                var byte2 = (words[(i + 1) >>> 2] >>> (24 - ((i + 1) % 4) * 8)) & 0xff;
                var byte3 = (words[(i + 2) >>> 2] >>> (24 - ((i + 2) % 4) * 8)) & 0xff;

                var triplet = (byte1 << 16) | (byte2 << 8) | byte3;

                for (var j = 0; (j < 4) && (i + j * 0.75 < sigBytes); j++) {
                    base64Chars.push(map.charAt((triplet >>> (6 * (3 - j))) & 0x3f));
                }
            }

            // Add padding
            var paddingChar = map.charAt(64);
            if (paddingChar) {
                while (base64Chars.length % 4) {
                    base64Chars.push(paddingChar);
                }
            }

            return base64Chars.join('');
        },

        /**
         * Converts a Base64 string to a word array.
         *
         * @param {string} base64Str The Base64 string.
         *
         * @return {WordArray} The word array.
         *
         * @static
         *
         * @example
         *
         *     var wordArray = CryptoJS.enc.Base64.parse(base64String);
         */
        parse: function (base64Str) {
            // Shortcuts
            var base64StrLength = base64Str.length;
            var map = this._map;

            // Ignore padding
            var paddingChar = map.charAt(64);
            if (paddingChar) {
                var paddingIndex = base64Str.indexOf(paddingChar);
                if (paddingIndex != -1) {
                    base64StrLength = paddingIndex;
                }
            }

            // Convert
            var words = [];
            var nBytes = 0;
            for (var i = 0; i < base64StrLength; i++) {
                if (i % 4) {
                    var bits1 = map.indexOf(base64Str.charAt(i - 1)) << ((i % 4) * 2);
                    var bits2 = map.indexOf(base64Str.charAt(i)) >>> (6 - (i % 4) * 2);
                    words[nBytes >>> 2] |= (bits1 | bits2) << (24 - (nBytes % 4) * 8);
                    nBytes++;
                }
            }

            return WordArray.create(words, nBytes);
        },

        _map: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/='
    };
}());


