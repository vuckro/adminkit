/** Extension appearance controls; the token engine remains internal. */
(function () {
 'use strict';
 window.AdminKitParts = window.AdminKitParts || {};
 window.AdminKitParts.design = function (context) {
  return function () {
   var buildPlugins = window.AdminKitParts.plugins(context);
   return context.el('section', { class: 'ak-panel ak-panel--plugins', role: 'tabpanel' }, [
    context.el('h2', { class: 'ak-panel-title', text: context.I.plugins }),
    context.intro(context.I.pluginSkinsIntro)
   ].concat(buildPlugins()));
  };
 };
})();
