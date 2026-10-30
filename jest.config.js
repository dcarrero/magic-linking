const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	globalSetup: '<rootDir>/tests/js/global-setup.js',
};
