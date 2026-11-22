import tsParser from '@typescript-eslint/parser';
import vue from 'eslint-plugin-vue';

export default [
    { ignores: ['public/build/**', 'vendor/**'] },
    ...vue.configs['flat/essential'],
    {
        files: ['resources/js/**/*.ts'],
        languageOptions: { parser: tsParser },
        rules: {
            'no-debugger': 'error',
            'no-duplicate-imports': 'error',
        },
    },
    {
        files: ['resources/js/**/*.vue'],
        languageOptions: { parserOptions: { parser: tsParser, sourceType: 'module' } },
        rules: {
            'no-debugger': 'error',
            'no-duplicate-imports': 'error',
            'vue/no-v-html': 'error',
        },
    },
];
