/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAIPredict
 * @copyright   Copyright (c) Mageprince (https://mageprince.com/)
 * @license     https://mageprince.com/end-user-license-agreement
 */
define([
    'Magento_Ui/js/grid/columns/column'
], function (Column) {
    'use strict';

    return Column.extend({
        defaults: {
            bodyTmpl: 'Mageprince_MageAIPredict/grid/cells/badge'
        },

        /**
         * Label for the confidence badge (capitalised).
         *
         * @param {Object} row
         * @returns {String}
         */
        getLabel: function (row) {
            var value = (row[this.index] || '').toString();

            return value ? value.charAt(0).toUpperCase() + value.slice(1) : '';
        },

        /**
         * CSS classes for the coloured badge.
         *
         * @param {Object} row
         * @returns {String}
         */
        getBadgeClass: function (row) {
            return 'mp-forecast-badge mp-conf-' + (row[this.index] || 'low');
        }
    });
});
