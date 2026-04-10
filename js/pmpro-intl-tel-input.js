/**
 * Initialize intl-tel-input on the PMPro phone field.
 *
 * @since 3.6
 */
(function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var phoneInput = document.querySelector( '#bphone' );
		if ( ! phoneInput || typeof intlTelInput === 'undefined' ) {
			return;
		}

		var settings = ( typeof pmpro_intl_tel !== 'undefined' ) ? pmpro_intl_tel : {};

		// Use the user's billing country if set, otherwise fall back to the site default.
		var initialCountry = ( settings.initial_country || settings.default_country || 'US' ).toLowerCase();

		// Whether to include the international calling code when saving.
		var includeCallingCode = settings.include_int_calling_code === '1';

		var iti = intlTelInput( phoneInput, {
			initialCountry: initialCountry,
			nationalMode: ! includeCallingCode,
			formatOnDisplay: true,
			autoPlaceholder: 'aggressive',
			countrySearch: true,
			dropdownContainer: document.body,
			fixDropdownWidth: false
		});

		// Before form submission, set the input value based on the calling code preference.
		var form = phoneInput.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				if ( includeCallingCode ) {
					var fullNumber = iti.getNumber();
					if ( fullNumber ) {
						phoneInput.value = fullNumber;
					}
				}
			});
		}
	});
})();
