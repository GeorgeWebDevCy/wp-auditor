module.exports = [
	{
		files: ["**/*.js"],
		languageOptions: {
			ecmaVersion: "latest",
			sourceType: "script",
			globals: {
				window: "readonly",
				document: "readonly",
				fetch: "readonly",
				navigator: "readonly",
				console: "readonly",
				XMLHttpRequest: "readonly",
				localStorage: "readonly",
				sessionStorage: "readonly",
				setTimeout: "readonly",
				clearTimeout: "readonly",
			},
		},
		rules: {
			"no-eval": "error",
			"no-implied-eval": "error",
			"no-undef": "warn",
			"no-unused-vars": "warn",
		},
	},
];
