define([
    'Magento_Checkout/js/model/quote'
], function (quote) {
    'use strict';
    return function (target) {
        var original = target.saveShippingInformation;
        target.saveShippingInformation = function () {
            var address = quote.shippingAddress();
            if (address && address.extensionAttributes) {
                // Asegura que extension_attributes exista
                if (!address.extension_attributes) {
                    address.extension_attributes = {};
                }
                // Copia neighborhood si existe en extensionAttributes
                if (address.extensionAttributes.hasOwnProperty('neighborhood')) {
                    address.extension_attributes.neighborhood = address.extensionAttributes.neighborhood;
                }
                // Copia references si existe en extensionAttributes
                if (address.extensionAttributes.hasOwnProperty('references')) {
                    address.extension_attributes.references = address.extensionAttributes.references;
                }
            }
            return original.apply(this, arguments);
        };
        return target;
    };
});