/**
 * Handle payment process
 * 
 * @param {Object} paymentData Payment data
 * @param {Function} onSuccess Success callback
 * @param {Function} onError Error callback
 */
function processPayment(paymentData, onSuccess, onError) {
    // First initialize the payment to get a payment ID
    $.ajax({
        url: 'api/initialize-payment.php',
        type: 'POST',
        data: paymentData,
        dataType: 'json',
        success: function(response) {
            if (response.status === 'success') {
                // Store payment ID for later use
                const paymentId = response.payment_id;
                
                // Configure payment options for gateway
                const options = {
                    key: RAZORPAY_KEY_ID, // Replace with your actual key from config
                    amount: response.amount * 100, // Amount in smallest currency unit (paise)
                    currency: "INR",
                    name: "Gym Management",
                    description: paymentData.payment_type.charAt(0).toUpperCase() + paymentData.payment_type.slice(1),
                    image: "assets/img/logo.png",
                    handler: function(response) {
                        // Payment successful, process the payment on server
                        completePayment({
                            payment_id: paymentId,
                            gym_id: paymentData.gym_id,
                            transaction_id: response.razorpay_payment_id,
                            payment_method: 'razorpay'
                        }, onSuccess, onError);
                    },
                    prefill: {
                        name: currentUser.name,
                        email: currentUser.email,
                        contact: currentUser.phone
                    },
                    notes: {
                        payment_type: paymentData.payment_type,
                        related_id: paymentData.related_id
                    },
                    theme: {
                        color: "#3399cc"
                    },
                    modal: {
                        ondismiss: function() {
                            // User closed the payment window without completing payment
                            cancelPayment(paymentId, paymentData.gym_id);
                        }
                    }
                };
                
                // Initialize Razorpay
                const razorpayInstance = new Razorpay(options);
                razorpayInstance.open();
            } else {
                if (onError) onError(response.message);
            }
        },
        error: function(xhr) {
            let errorMessage = 'Payment initialization failed';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = response.message || errorMessage;
            } catch (e) {}
            
            if (onError) onError(errorMessage);
        }
    });
}

/**
 * Complete payment after gateway confirmation
 * 
 * @param {Object} paymentData Payment confirmation data
 * @param {Function} onSuccess Success callback
 * @param {Function} onError Error callback
 */
function completePayment(paymentData, onSuccess, onError) {
    $.ajax({
        url: 'api/process-payment.php',
        type: 'POST',
        data: paymentData,
        dataType: 'json',
        success: function(response) {
            if (response.status === 'success') {
                if (onSuccess) onSuccess(response);
            } else {
                if (onError) onError(response.message);
            }
        },
        error: function(xhr) {
            let errorMessage = 'Payment processing failed';
            try {
                const response = JSON.parse(xhr.responseText);
                errorMessage = response.message || errorMessage;
            } catch (e) {}
            
            if (onError) onError(errorMessage);
        }
    });
}

/**
 * Cancel a pending payment
 * 
 * @param {number} paymentId Payment ID
 * @param {number} gymId Gym ID
 */
function cancelPayment(paymentId, gymId) {
    $.ajax({
        url: 'api/cancel-payment.php',
        type: 'POST',
        data: {
            payment_id: paymentId,
            gym_id: gymId
        },
        dataType: 'json'
    });
}
