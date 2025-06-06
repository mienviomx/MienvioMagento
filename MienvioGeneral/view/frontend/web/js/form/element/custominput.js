define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';
    return Abstract.extend({
        defaults: {
            elementTmpl: 'ui/form/element/input'
        },
        initObservable: function () {
            this._super()
                .observe('value');
            return this;
        }
    });
});