/*jshint esversion: 8 */
/**
 * JS Integration between CiviCRM & Stripe.
 */
(function($, ts) {

  var script = {
    name: 'stripe',
    stripe: null,
    elements: {
      card: null,
    },
    scriptLoading: false,
    paymentProcessorID: null,

    paymentData: {
      clientSecret: null,
    },

    /**
     * Called when payment details have been entered and validated successfully
     *
     * @param {string} objectType
     * @param {string} objectID
     */
    async successHandler(objectType, objectID) {
      script.debugging(objectType + ': success - submitting form');

      // Insert the token ID into the form so it gets submitted to the server
      var hiddenInput = document.createElement('input');
      hiddenInput.setAttribute('type', 'hidden');
      hiddenInput.setAttribute('name', objectType);
      hiddenInput.setAttribute('value', objectID);
      CRM.payment.form.appendChild(hiddenInput);

      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();

      if (script.getReCAPTCHAToken()) {
        let recaptcha = await script.reloadReCAPTCHA();

        // Insert the token ID into the form so it gets submitted to the server
        var hiddenInput2 = document.createElement('input');
        hiddenInput2.setAttribute('type', 'hidden');
        hiddenInput2.setAttribute('name', 'captcha');
        hiddenInput2.setAttribute('value', script.getReCAPTCHAToken());
        CRM.payment.form.appendChild(hiddenInput2);
      }

      // Submit the form
      CRM.payment.form.submit();

    },

    /**
     * Get a list of jQuery elements for all possible Stripe elements that
     * could be loaded
     *
     * @returns {{card:
     *   (*|jQuery|HTMLElement)}}
     */
    getJQueryPaymentElements: function() {
      return {
        card: $('div#card-element'),
      };
    },

    /**
     * Hide any visible payment elements
     */
    hideJQueryPaymentElements: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        element.hide();
      }
    },

    /**
     * Destroy any payment elements we have already created
     */
    destroyPaymentElements: function() {
      for (var elementName in script.elements) {
        var element = script.elements[elementName];
        if (element !== null) {
          script.debugging("destroying " + elementName + " element");
          element.destroy();
          script.elements[elementName] = null;
        }
      }
      $('.is_recur-section #stripe-recurring-start-date').remove();
    },

    /**
     * Check that payment elements are valid
     * @returns {boolean}
     */
    checkPaymentElementsAreValid: function() {
      var jQueryPaymentElements = script.getJQueryPaymentElements();
      for (var elementName in jQueryPaymentElements) {
        var element = jQueryPaymentElements[elementName];
        if ((element.length !== 0) && (element.children().length !== 0)) {
          script.debugging(elementName + ' element found');
          return true;
        }
      }
      script.debugging('no valid elements found');
      return false;
    },

    /**
     * Get the ReCAPTCHA token if available (function currently only implemented by formprotection)
     *
     * @returns {*|string}
     */
    getReCAPTCHAToken: function() {
      return (typeof getReCAPTCHAToken === 'function') ? getReCAPTCHAToken() : '';
    },

    /**
     * Wrapper for formprotection reloadReCAPTCHA function
     */
    /**
     *
     * @returns {promise}
     */
    reloadReCAPTCHA: function() {
      if (typeof reloadReCAPTCHA === 'function') {
        return reloadReCAPTCHA();
      }
    },

    /**
     * Handle the "Card" submission to Stripe
     *
     * @param submitEvent
     */
    async handleSubmitCard(submitEvent) {
      script.debugging('handle submit card');
      let createPaymentMethodResult = await stripe.createPaymentMethod('card', script.elements.card);
      if (createPaymentMethodResult.error) {
        // Show error in payment form
        CRM.payment.displayError(createPaymentMethodResult.error.message, true);
      }
      else {
        // For recur, additional participants we do NOT know the final
        // amount so must create a paymentMethod and only create the
        // paymentIntent once the form is finally submitted. We should
        // never get here with amount=0 as we should be doing a
        // "nonStripeSubmit()" instead. This may become needed when we save
        // cards
        var totalAmount = CRM.payment.getTotalAmount();
        if (totalAmount) {
          totalAmount = totalAmount.toFixed(2);
        }
        if (CRM.payment.getIsRecur() || (totalAmount === null)) {
          try {
            let paymentIntentProcessResponse = await CRM.api4('StripePaymentintent', 'ProcessPublic', {
              setup: true,
              paymentMethodID: createPaymentMethodResult.paymentMethod.id,
              paymentProcessorID: CRM.vars[script.name].id,
              description: document.title,
              csrfToken: CRM.vars[script.name].csrfToken,
              extraData: CRM.payment.getBillingEmail() + CRM.payment.getBillingName(),
              captcha: script.getReCAPTCHAToken()
            });
            CRM.payment.swalClose();
            CRM.payment.debugging(script.name, 'StripePaymentintent.Process done (setupIntent)');
            if (paymentIntentProcessResponse.status === 'requires_action') {
              // Use Stripe.js to handle a pending card action (eg. 3d-secure)
              script.paymentData.clientSecret = paymentIntentProcessResponse.client_secret;
              let cardActionResult = await stripe.confirmCardSetup(script.paymentData.clientSecret);
              if (cardActionResult.error) {
                // Show error in payment form
                CRM.payment.displayError(cardActionResult.error.message, true);
              }
              else {
                // The card action has been handled
                // The PaymentIntent can be confirmed again on the
                // server
                script.successHandler('setupIntentID', cardActionResult.setupIntent.id);
              }
            }
            // WordPress returns a 200 not 500 HTTP response because headers are sent beforehand.  So we check for an error code in the response.
            else if (paymentIntentProcessResponse.hasOwnProperty('error_code')) {
              script.stripePaymentIntentProcessFail(paymentIntentProcessResponse);
            }
            else {
              // All good, we can submit the form
              script.successHandler('paymentMethodID', createPaymentMethodResult.paymentMethod.id);
            }
          } catch (failObject) {
            script.stripePaymentIntentProcessFail(failObject);
          }
        }
        else {
          // Send paymentMethod.id to server
          CRM.payment.debugging(script.name, 'Waiting for pre-auth');
          CRM.payment.swalFire({
            title: ts('Please wait'),
            text: ts(' while we pre-authorize your card...'),
            allowOutsideClick: false,
            willOpen: function () {
              Swal.showLoading(Swal.getConfirmButton());
            }
          }, '', false);

          try {
            let processParams = {
              // payment_method_id: createPaymentMethodResult.paymentMethod.id,
              paymentMethodID: createPaymentMethodResult.paymentMethod.id,
              amount: CRM.payment.getTotalAmount().toFixed(2),
              currency: CRM.payment.getCurrency(CRM.vars[script.name].currency),
              paymentProcessorID: CRM.vars[script.name].id,
              description: document.title,
              extraData: CRM.payment.getBillingEmail() + CRM.payment.getBillingName(),
            };

            let processMode = 'Public';
            if (CRM.vars.stripe.moto && document.getElementById('enableMOTO').checked) {
              processMode = 'MOTO';
            }
            else {
              processParams.csrfToken = CRM.vars[script.name].csrfToken;
              processParams.captcha = script.getReCAPTCHAToken();
            }

            let paymentIntentProcessResponse = await CRM.api4('StripePaymentintent', 'Process' + processMode, processParams);
            CRM.payment.swalClose();
            CRM.payment.debugging(script.name, 'StripePaymentintent.Process done (paymentIntent)');
            if (paymentIntentProcessResponse.requires_action) {
              // Use Stripe.js to handle a pending card action (eg. 3d-secure)
              script.paymentData.clientSecret = paymentIntentProcessResponse.paymentIntentClientSecret;
              let cardActionResult = await stripe.handleCardAction(script.paymentData.clientSecret);
              if (cardActionResult.error) {
                // Show error in payment form
                CRM.payment.displayError(cardActionResult.error.message, true);
                script.reloadReCAPTCHA();
              }
              else {
                // The card action has been handled
                // The PaymentIntent can be confirmed again on the
                // server
                script.successHandler('paymentIntentID', cardActionResult.paymentIntent.id);
              }
            }
            // WordPress returns a 200 not 500 HTTP response because headers are sent beforehand.  So we check for an error code in the response.
            else if (paymentIntentProcessResponse.hasOwnProperty('error_code')) {
              script.stripePaymentIntentProcessFail(paymentIntentProcessResponse);
            }
            else {
              // All good, we can submit the form
              script.successHandler('paymentIntentID', paymentIntentProcessResponse.paymentIntent.id);
            }
          } catch (failObject) {
            script.stripePaymentIntentProcessFail(failObject);
          }
        }
      }
    },

    /**
     * Display a helpful error message if call to StripePaymentintent.Process
     * fails
     * @param {object} failObject
     * @returns {boolean}
     */
    stripePaymentIntentProcessFail: function(failObject) {
      var error = ts('Unknown error');
      if (typeof failObject !== 'undefined') {
        if (failObject.hasOwnProperty('error_message')) {
          // From an API4 exception
          error = failObject.error_message;
        }
        else
          if (failObject.hasOwnProperty('statusText') && (failObject.statusText !== 'OK')) {
            // A PHP exit can return 200 "OK" but we don't want to display "OK" as the error!
            if (failObject.statusText === 'parsererror') {
              error = ts('Configuration error - unable to process paymentIntent');
            }
            else {
              error = failObject.statusText;
            }
          }
      }
      CRM.payment.displayError(error, true);
      script.reloadReCAPTCHA();
      return true;
    },

    /**
     * Payment processor is not Stripe - cleanup
     */
    notScriptProcessor: function() {
      script.debugging('New payment processor is not ' + script.name + ', clearing CRM.vars.' + script.name);
      script.destroyPaymentElements();
      delete (CRM.vars[script.name]);
      $(CRM.payment.getBillingSubmit()).show();
      CRM.payment.resetBillingFieldsRequiredForJQueryValidate();
    },

    /**
     * Check environment and trigger loadBillingBlock()
     */
    checkAndLoad: function() {
      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('CRM.vars.' + script.name + ' not defined!');
        return;
      }

      if (typeof Stripe === 'undefined') {
        if (script.scriptLoading) {
          return;
        }
        script.scriptLoading = true;
        script.debugging('Stripe.js is not loaded!');

        $.ajax({
          url: 'https://js.stripe.com/v3',
          dataType: 'script',
          cache: true,
          timeout: 5000,
          crossDomain: true
        })
          .done(function(data) {
            script.scriptLoading = false;
            script.debugging("Script loaded and executed.");
            script.loadBillingBlock();
          })
          .fail(function() {
            script.scriptLoading = false;
            script.debugging('Failed to load Stripe.js');
            script.triggerEventCrmBillingFormReloadFailed();
          });
      }
      else {
        script.loadBillingBlock();
      }
    },

    /**
     * This actually loads the billingBlock and the chosen Stripe element
     */
    loadBillingBlock: function() {
      script.debugging('loadBillingBlock');

      // When switching payment processors we need to make sure these are empty
      script.paymentData.clientSecret = null;

      var oldPaymentProcessorID = script.paymentProcessorID;
      script.paymentProcessorID = CRM.payment.getPaymentProcessorSelectorValue();
      script.debugging('payment processor old: ' + oldPaymentProcessorID + ' new: ' + script.paymentProcessorID + ' id: ' + CRM.vars[script.name].id);
      if ((script.paymentProcessorID !== null) && (script.paymentProcessorID !== parseInt(CRM.vars[script.name].id))) {
        script.debugging('not ' + script.name);
        return script.notScriptProcessor();
      }

      script.debugging('New ID: ' + CRM.vars[script.name].id + ' pubKey: ' + CRM.vars[script.name].publishableKey);

      if (CRM.vars[script.name].publishableKey === '') {
        script.debugging('Invalid payment configuration!');
        CRM.payment.swalFire({
          icon: 'error',
          text: '',
          title: ts('Invalid payment configuration!')
        }, '', true);
      }

      stripe = Stripe(CRM.vars[script.name].publishableKey);

      script.debugging('locale: ' + CRM.vars[script.name].locale);
      var stripeElements = stripe.elements({locale: CRM.vars[script.name].locale});

      // Load card element
      script.createElementCard(stripeElements);
    },

    /**
     * This is called once Stripe elements have finished loading onto the form
     */
    doAfterStripeElementsHaveLoaded: function() {
      CRM.payment.setBillingFieldsRequiredForJQueryValidate();

      // If another submit button on the form is pressed (eg. apply discount)
      //  add a flag that we can set to stop payment submission
      CRM.payment.form.dataset.submitdontprocess = 'false';

      CRM.payment.addHandlerNonPaymentSubmitButtons();

      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].addEventListener('click', submitButtonClick);
      }

      function submitButtonClick(clickEvent) {
        // Take over the click function of the form.
        if (typeof CRM.vars[script.name] === 'undefined') {
          // Do nothing. Not our payment processor
          return false;
        }
        script.debugging('clearing submitdontprocess');
        CRM.payment.form.dataset.submitdontprocess = 'false';

        // Run through our own submit, that executes Stripe submission if
        // appropriate for this submit.
        return script.submit(clickEvent);
      }

      // Remove the onclick attribute added by CiviCRM.
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].removeAttribute('onclick');
      }

      CRM.payment.addSupportForCiviDiscount();

      // For CiviCRM Webforms.
      if (CRM.payment.getIsDrupalWebform()) {
        // We need the action field for back/submit to work and redirect properly after submission

        $('[type=submit]').click(function() {
          CRM.payment.addDrupalWebformActionElement(this.value);
        });
        // If enter pressed, use our submit function
        CRM.payment.form.addEventListener('keydown', function (keydownEvent) {
          if (keydownEvent.code === 'Enter') {
            CRM.payment.addDrupalWebformActionElement(this.value);
            script.submit(keydownEvent);
          }
        });

        $('#billingcheckbox:input').hide();
        $('label[for="billingcheckbox"]').hide();
      }

      if (CRM.vars.stripe.moto) {
        script.addMOTOPaymentCheckbox();
      }

      if (script.checkPaymentElementsAreValid()) {
        CRM.payment.triggerEvent('crmBillingFormReloadComplete', script.name);
        CRM.payment.triggerEvent('crmStripeBillingFormReloadComplete', script.name);
      }
      else {
        script.debugging('Failed to load payment elements');
        script.triggerEventCrmBillingFormReloadFailed();
      }
    },

    addMOTOPaymentCheckbox: function() {
      const div = document.createElement('div');
      div.id = 'crm-stripe-moto';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.id = 'enableMOTO';
      checkbox.checked = true;
      const label = document.createElement('label');
      label.htmlFor = 'enableMOTO';
      label.appendChild(document.createTextNode(ts('Enable MOTO for this transaction?')));
      div.appendChild(checkbox);
      div.appendChild(label);
      document.getElementById('crm-payment-js-billing-form-container').after(div);
    },

    submit: function(submitEvent) {
      submitEvent.preventDefault();
      script.debugging('submit handler');

      if (CRM.payment.form.dataset.submitted === 'true') {
        return;
      }
      CRM.payment.form.dataset.submitted = 'true';

      if (!CRM.payment.validateCiviDiscount()) {
        return false;
      }

      if (!CRM.payment.validateForm()) {
        return false;
      }

      var cardError = CRM.$('#card-errors').text();
      if (CRM.$('#card-element.StripeElement--empty').length && (CRM.payment.getTotalAmount() !== 0.0)) {
        script.debugging('card details not entered!');
        if (!cardError) {
          cardError = ts('Please enter your card details');
        }
        CRM.payment.swalFire({
          icon: 'warning',
          text: '',
          title: cardError
        }, '#card-element', true);
        CRM.payment.triggerEvent('crmBillingFormNotValid');
        CRM.payment.form.dataset.submitted = 'false';
        return false;
      }

      if (CRM.$('#card-element.StripeElement--invalid').length) {
        if (!cardError) {
          cardError = ts('Please check your card details!');
        }
        script.debugging('card details not valid!');
        CRM.payment.swalFire({
          icon: 'error',
          text: '',
          title: cardError
        }, '#card-element', true);
        CRM.payment.triggerEvent('crmBillingFormNotValid');
        CRM.payment.form.dataset.submitted = 'false';
        return false;
      }

      if (!CRM.payment.validateReCaptcha()) {
        return false;
      }

      if (typeof CRM.vars[script.name] === 'undefined') {
        script.debugging('Submitting - not a ' + script.name + ' processor');
        return true;
      }

      var scriptProcessorId = parseInt(CRM.vars[script.name].id);
      var chosenProcessorId = null;

      // Handle multiple payment options and Stripe not being chosen.
      if (CRM.payment.getIsDrupalWebform()) {
        // this element may or may not exist on the webform, but we are dealing with a single (stripe) processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = scriptProcessorId;
        }
        else {
          chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((CRM.payment.form.querySelector(".crm-section.payment_processor-section") !== null) ||
          (CRM.payment.form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          scriptProcessorId = CRM.vars[script.name].id;
          if (CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = parseInt(CRM.payment.form.querySelector('input[name="payment_processor_id"]:checked').value);
          }
        }
      }

      // If any of these are true, we are not using the stripe processor:
      // - Is the selected processor ID pay later (0)
      // - Is the Stripe processor ID defined?
      // - Is selected processor ID and stripe ID undefined? If we only have stripe ID, then there is only one (stripe) processor on the page
      if ((chosenProcessorId === 0) || (scriptProcessorId === null) ||
        ((chosenProcessorId === null) && (scriptProcessorId === null))) {
        script.debugging('Not a ' + script.name + ' transaction, or pay-later');
        return CRM.payment.doStandardFormSubmit();
      }
      else {
        script.debugging(script.name + ' is the selected payprocessor');
      }

      // Don't handle submits generated by non-stripe processors
      if (typeof CRM.vars[script.name].publishableKey === 'undefined') {
        script.debugging('submit missing stripe-pub-key element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if (CRM.payment.form.dataset.submitdontprocess === 'true') {
        script.debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (CRM.payment.getIsDrupalWebform()) {
        // If we have selected Stripe but amount is 0 we don't submit via Stripe
        if ($('#billing-payment-block').is(':hidden')) {
          script.debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if ($processorFields.length) {
          if ($processorFields.filter(':checked')
            .val() === '0' || $processorFields.filter(':checked').val() === 0) {
            script.debugging('no payment processor selected');
            return true;
          }
        }
      }

      var totalAmount = CRM.payment.getTotalAmount();
      if (totalAmount === 0.0) {
        script.debugging("Total amount is 0");
        return CRM.payment.doStandardFormSubmit();
      }

      // Disable the submit button to prevent repeated clicks
      for (i = 0; i < CRM.payment.submitButtons.length; ++i) {
        CRM.payment.submitButtons[i].setAttribute('disabled', true);
      }

      // When we click the stripe element we already have the elementType.
      // But clicking an alternative submit button we have to work it out.
      var elementType = 'card';
      if (submitEvent.hasOwnProperty(elementType)) {
        elementType = submitEvent.elementType;
      }
      // Create a token when the form is submitted.
      switch (elementType) {
        case 'card':
          script.handleSubmitCard(submitEvent);
          break;
      }

      if ($('#stripe-recurring-start-date').is(':hidden')) {
        $('#stripe-recurring-start-date').remove();
      }

      return true;
    },

    createElementCard: function(stripeElements) {
      script.debugging('try to create card element');
      var style = {
        base: {
          fontSize: '1.1em', fontWeight: 'lighter'
        },

        invalid: {
          "::placeholder": {
            color: "#E25950",
            fontWeight: '500',
          },
          color: "#E25950",
          fontWeight: '500',
        }
      };

      var elementsCreateParams = {style: style, value: {}};

      var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID);
      if (postCodeElement) {
        var postCode = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID).value;
        script.debugging('existing postcode: ' + postCode);
        elementsCreateParams.value.postalCode = postCode;
      }

      // disableLink - https://lab.civicrm.org/extensions/stripe/-/issues/458
      if (CRM.vars[script.name].disablelink) {
        elementsCreateParams.disableLink = true;
      }

      // Cleanup any classes leftover from previous switching payment processors
      script.getJQueryPaymentElements().card.removeClass();
      // Create an instance of the card Element.
      script.elements.card = stripeElements.create('card', elementsCreateParams);
      script.elements.card.mount('#card-element');
      script.debugging("created new card element", script.elements.card);

      if (postCodeElement) {
        // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the stripe card-element.
        postCodeElement.setAttribute('readonly', true);
      }

      // All containers start as display: none and are enabled on demand
      document.getElementById('card-element').style.display = 'block';

      script.elements.card.addEventListener('change', function (event) {
        script.cardElementChanged(event);
      });

      script.doAfterStripeElementsHaveLoaded();
    },

    cardElementChanged: function(event) {
      if (event.empty) {
        $('div#card-errors').hide();
      }
      else
        if (event.error) {
          CRM.payment.displayError(event.error.message, false);
        }
        else
          if (event.complete) {
            $('div#card-errors').hide();
            var postCodeElement = document.getElementById('billing_postal_code-' + CRM.vars[script.name].billingAddressID);
            if (postCodeElement) {
              postCodeElement.value = event.value.postalCode;
            }
          }
    },

    /**
     * Output debug information
     * @param {string} errorCode
     */
    debugging: function(errorCode) {
      CRM.payment.debugging(script.name, errorCode);
    },

    /**
     * Trigger the crmBillingFormReloadFailed event and notify the user
     */
    triggerEventCrmBillingFormReloadFailed: function() {
      CRM.payment.triggerEvent('crmBillingFormReloadFailed');
      script.hideJQueryPaymentElements();
      CRM.payment.displayError(ts('Could not load payment element - Is there a problem with your network connection?'), true);
    }
  };

  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  if (CRM.payment.hasOwnProperty(script.name)) {
    return;
  }

  // Currently this just flags that we've already loaded
  var crmPaymentObject = {};
  crmPaymentObject[script.name] = script;
  $.extend(CRM.payment, crmPaymentObject);

  CRM.payment.registerScript(script.name);

  // Re-prep form when we've loaded a new payproc via ajax or via webform
  $(document).ajaxComplete(function (event, xhr, settings) {
    if (CRM.payment.isAJAXPaymentForm(settings.url)) {
      CRM.payment.debugging(script.name, 'triggered via ajax');
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function() {
    CRM.payment.debugging(script.name, 'DOMContentLoaded');
    load();
  });

  /**
   * Called on every load of this script (whether billingblock loaded via AJAX
   * or DOMContentLoaded)
   */
  function load() {
    if (window.civicrmStripeHandleReload) {
      // Call existing instance of this, instead of making new one.
      CRM.payment.debugging(script.name, "calling existing HandleReload.");
      window.civicrmStripeHandleReload();
    }
  }

  /**
   * This function boots the UI.
   */
  window.civicrmStripeHandleReload = function() {
    CRM.payment.scriptName = script.name;
    CRM.payment.debugging(script.name, 'HandleReload');

    // Get the form containing payment details
    CRM.payment.form = CRM.payment.getBillingForm();
    if (typeof CRM.payment.form.length === 'undefined' || CRM.payment.form.length === 0) {
      CRM.payment.debugging(script.name, 'No billing form!');
      return;
    }

    // If we are reloading start with the form submit buttons visible
    // They may get hidden later depending on the element type.
    $(CRM.payment.getBillingSubmit()).show();

    // Load Stripe onto the form.
    var cardElement = document.getElementById('card-element');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        CRM.payment.debugging(script.name, 'checkAndLoad from document.ready');
        script.checkAndLoad();
      }
      else {
        CRM.payment.debugging(script.name, 'already loaded');
      }
    }
    else {
      script.notScriptProcessor();
      CRM.payment.triggerEvent('crmBillingFormReloadComplete', script.name);
    }
  };

}(CRM.$, CRM.ts('com.drastikbydesign.stripe')));
