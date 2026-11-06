(function() {
    'use strict';
    // Helper function for calculations
    function calculateDiscount(price, percent) {
        return price - (price * (percent / 100));
    }
    window.sampleApp = {
        discount: calculateDiscount
    };
})();
