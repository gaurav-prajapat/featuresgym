/**
 * Operating Hours Management Script
 * Handles the dynamic functionality of the operating hours form
 */
document.addEventListener('DOMContentLoaded', function() {
    // Toggle between daily and individual hours
    const useDailyHours = document.getElementById('use_daily_hours');
    const dailyHoursSection = document.getElementById('daily-hours-section');
    const individualHoursSection = document.getElementById('individual-hours-section');
    
    if (useDailyHours) {
        useDailyHours.addEventListener('change', function() {
            if (this.checked) {
                dailyHoursSection.classList.remove('hidden');
                individualHoursSection.classList.add('hidden');
            } else {
                dailyHoursSection.classList.add('hidden');
                individualHoursSection.classList.remove('hidden');
            }
        });
    }
    
    // Handle "All Days Closed" checkbox
    const allDaysClosed = document.getElementById('all_days_closed');
    const dailyHoursInputs = document.getElementById('daily-hours-inputs');
    const dailyClosedMessage = document.getElementById('daily-closed-message');
    
    if (allDaysClosed && dailyHoursInputs && dailyClosedMessage) {
        allDaysClosed.addEventListener('change', function() {
            if (this.checked) {
                dailyHoursInputs.classList.add('hidden');
                dailyClosedMessage.classList.remove('hidden');
            } else {
                dailyHoursInputs.classList.remove('hidden');
                dailyClosedMessage.classList.add('hidden');
            }
        });
    }
    
    // Handle individual day closed checkboxes
    const closedCheckboxes = document.querySelectorAll('input[name="closed_days[]"]');
    
    closedCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const dayContainer = this.closest('.mb-6');
            const dayInputs = dayContainer.querySelector('.day-hours-inputs');
            const closedMessage = dayContainer.querySelector('.day-closed-message');
            
            if (this.checked) {
                dayInputs.classList.add('hidden');
                closedMessage.classList.remove('hidden');
            } else {
                dayInputs.classList.remove('hidden');
                closedMessage.classList.add('hidden');
            }
        });
    });
    
    // Time validation - ensure morning close is after morning open
    const morningOpenInputs = document.querySelectorAll('input[name$="[morning_open_time]"]');
    const morningCloseInputs = document.querySelectorAll('input[name$="[morning_close_time]"]');
    
    morningOpenInputs.forEach(input => {
        input.addEventListener('change', function() {
            const container = this.closest('.grid');
            const closeInput = container.querySelector('input[name$="[morning_close_time]"]');
            
            if (closeInput && this.value >= closeInput.value) {
                alert('Morning opening time must be before closing time');
                this.value = '09:00';
            }
        });
    });
    
    morningCloseInputs.forEach(input => {
        input.addEventListener('change', function() {
            const container = this.closest('.grid');
            const openInput = container.querySelector('input[name$="[morning_open_time]"]');
            
            if (openInput && this.value <= openInput.value) {
                alert('Morning closing time must be after opening time');
                this.value = '13:00';
            }
        });
    });
    
    // Time validation - ensure evening close is after evening open
    const eveningOpenInputs = document.querySelectorAll('input[name$="[evening_open_time]"]');
    const eveningCloseInputs = document.querySelectorAll('input[name$="[evening_close_time]"]');
    
    eveningOpenInputs.forEach(input => {
        input.addEventListener('change', function() {
            const container = this.closest('.grid');
            const closeInput = container.querySelector('input[name$="[evening_close_time]"]');
            
            if (closeInput && this.value >= closeInput.value) {
                alert('Evening opening time must be before closing time');
                this.value = '16:00';
            }
        });
    });
    
    eveningCloseInputs.forEach(input => {
        input.addEventListener('change', function() {
            const container = this.closest('.grid');
            const openInput = container.querySelector('input[name$="[evening_open_time]"]');
            
            if (openInput && this.value <= openInput.value) {
                alert('Evening closing time must be after opening time');
                this.value = '22:00';
            }
        });
    });
    
    // Copy hours functionality - for individual days
    const copyHoursButtons = document.querySelectorAll('.copy-hours-btn');
    
    if (copyHoursButtons.length > 0) {
        copyHoursButtons.forEach(button => {
            button.addEventListener('click', function() {
                const sourceDay = this.getAttribute('data-source-day');
                const targetDay = this.getAttribute('data-target-day');
                
                if (!sourceDay || !targetDay) return;
                
                const sourceInputs = document.querySelectorAll(`input[name^="operating_hours[${sourceDay}]"]`);
                const targetInputs = document.querySelectorAll(`input[name^="operating_hours[${targetDay}]"]`);
                
                if (sourceInputs.length === targetInputs.length) {
                    for (let i = 0; i < sourceInputs.length; i++) {
                        targetInputs[i].value = sourceInputs[i].value;
                    }
                }
            });
        });
    }
    
    // Form validation before submission
    const operatingHoursForm = document.querySelector('form[name="operating_hours_form"]');
    
    if (operatingHoursForm) {
        operatingHoursForm.addEventListener('submit', function(e) {
            let isValid = true;
            let errorMessage = '';
            
            // Validate based on whether using daily or individual hours
            if (useDailyHours && useDailyHours.checked) {
                // Daily hours validation
                if (!allDaysClosed.checked) {
                    const dailyInputs = dailyHoursSection.querySelectorAll('input[type="time"]');
                    
                    dailyInputs.forEach(input => {
                        if (!input.value) {
                            isValid = false;
                            errorMessage = 'Please fill in all time fields for daily hours.';
                        }
                    });
                }
            } else {
                // Individual days validation
                closedCheckboxes.forEach(checkbox => {
                    if (!checkbox.checked) {
                        const dayContainer = checkbox.closest('.mb-6');
                        const dayName = dayContainer.querySelector('h3').textContent.trim();
                        const timeInputs = dayContainer.querySelectorAll('input[type="time"]');
                        
                        timeInputs.forEach(input => {
                            if (!input.value) {
                                isValid = false;
                                errorMessage = `Please fill in all time fields for ${dayName}.`;
                            }
                        });
                    }
                });
            }
            
            if (!isValid) {
                e.preventDefault();
                alert(errorMessage);
            }
        });
    }
});