const globals = require('globals');
const noUnsanitized = require('eslint-plugin-no-unsanitized');

module.exports = [
  {
    files: ['assets/js/**/*.js', 'inc/integrations/**/*.js', '../../assets/js/**/*.js', '../../inc/integrations/**/*.js'],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: 'script',
      globals: {
        ...globals.browser,
        wp: 'readonly',
        jQuery: 'readonly',
        ajaxurl: 'readonly',
        AdminKitData: 'readonly',
        AdminKitDesignColors: 'readonly',
        AdminKitMenuModel: 'readonly',
        AdminKitNavigationControls: 'readonly',
        AdminKitNavigationMenu: 'readonly',
        AdminKitNavigationToolbar: 'readonly',
        AdminKitSettingsDesign: 'readonly',
        AdminKitSettingsFeatures: 'readonly',
        AdminKitSettingsNavigation: 'readonly',
        AdminKitSettingsPlugins: 'readonly',
        AdminKitProfileAccountDom: 'readonly',
        AdminKitProfileAccount: 'readonly',
        AdminKitOptionsGeneral: 'readonly',
        AdminKitOptionsDiscussion: 'readonly',
        AdminKitOptionsReading: 'readonly',
        AdminKitOptionsWriting: 'readonly',
        AdminKitOptionsMedia: 'readonly',
        AdminKitOptionsPermalinks: 'readonly',
        AdminKitOptionsPrivacy: 'readonly',
        AdminKitNotificationCenter: 'readonly',
        AdminKitPostPreviews: 'readonly',
        AdminKitUserQuickEdit: 'readonly',
        AdminKitUsernameChanger: 'readonly',
      },
    },
    plugins: {
      'no-unsanitized': noUnsanitized,
    },
    rules: {
      'no-undef': 'error',
      'no-eval': 'error',
      'no-implied-eval': 'error',
      'no-new-func': 'error',
      'no-unsanitized/method': 'error',
      'no-unsanitized/property': 'error',
    },
  },
];
