document.getElementById('purchaseMembershipBtn').addEventListener('click', async function() {
    const userId = this.dataset.userId;
    const planId = this.dataset.planId;
    const gymId = this.dataset.gymId;

    try {
        // First API call to initiate purchase
        const purchaseResponse = await fetch('/api/purchase_membership.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_id: userId,
                plan_id: planId,
                gym_id: gymId
            })
        });
        
        const purchaseData = await purchaseResponse.json();
        
        if (purchaseData.success) {
            // Here you can integrate your payment gateway
            // After successful payment, confirm the payment
            const confirmResponse = await fetch('/api/confirm_payment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    payment_id: purchaseData.data.payment_id,
                    transaction_id: 'TRANS_' + Date.now() // Replace with actual transaction ID from payment gateway
                })
            });
            
            const confirmData = await confirmResponse.json();
            if (confirmData.success) {
                window.location.href = '/membership-success.php';
            }
        }
    } catch (error) {
        console.error('Error:', error);
    }
});
