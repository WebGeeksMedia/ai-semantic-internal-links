import js from '@eslint/js';
import globals from 'globals';

export default [
	js.configs.recommended,
	{
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'script',
			globals: {
				...globals.browser,
				...globals.jquery,
				wp: 'readonly',
				jQuery: 'readonly',
				tinymce: 'readonly',
				tinyMCE: 'readonly',
				ajaxurl: 'readonly',
				ILSEJ: 'readonly',
				ilsejAdmin: 'readonly',
				openAnchorModal: 'readonly',
			},
		},
		rules: {
			'no-var': 'error',
			'prefer-const': 'error',
			'indent': ['error', 'tab', { SwitchCase: 1 }],
			'quotes': ['error', 'single', { avoidEscape: true }],
			'semi': ['error', 'always'],
			'no-unused-vars': ['warn', { args: 'none' }],
			'no-console': ['warn', { allow: ['error', 'warn'] }],
		},
	},
	{
		ignores: ['**/*.min.js'],
	},
];
