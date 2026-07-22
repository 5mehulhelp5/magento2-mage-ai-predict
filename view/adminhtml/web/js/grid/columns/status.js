/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAIPredict
 * @copyright   Copyright (c) Mageprince (https://mageprince.com/)
 * @license     https://mageprince.com/end-user-license-agreement
 */
define([
    'Magento_Ui/js/grid/columns/select'
], function (Select) {
    'use strict';

    return Select.extend({
        defaults: {
            bodyTmpl: 'Mageprince_MageAIPredict/grid/cells/badge'
        },

        /**
         * Resolve the human label for the row's status value.
         *
         * @param {Object} row
         * @returns {String}
         */
        getLabel: function (row) {
            var value = row[this.index],
                label = value;

            (this.options || []).forEach(function (option) {
                if (option.value === value) {
                    label = option.label;
                }
            });

            return label;
        },

        /**
         * CSS classes for the coloured badge.
         *
         * @param {Object} row
         * @returns {String}
         */
        getBadgeClass: function (row) {
            return 'mp-forecast-badge mp-status-' + (row[this.index] || 'healthy');
        }
    });
});
