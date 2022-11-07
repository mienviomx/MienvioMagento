define([
  "jquery",
  "mageUtils",
  "./shipping-rates-validation-rules",
  "mage/translate",
], function ($, utils, validationRules, $t) {
  "use strict";

  return {
    validationErrors: [],
    validate: function (address) {
      var self = this;

      this.validationErrors = [];
      $.each(validationRules.getRules(), function (field, rule) {
        console.log(field, rule);
        var message;

        if (rule.required && utils.isEmpty(address[field])) {
          message = $t("Field ") + field + $t(" is required.");

          self.validationErrors.push(message);
        }
      });

      return !Boolean(this.validationErrors.length);
    },
  };
});
