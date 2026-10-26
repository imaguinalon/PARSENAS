/**
 * Validación en tiempo real de requisitos de contraseña.
 *
 * Se activa para los checklist .pw-checklist de la pantalla de registro.
 * Cada <li data-rule="..."> se marca con la clase "valid" (verde / ✓) o se
 * queda sin ella (rojo / ✗), siguiendo las reglas de PARSEÑAS.
 *
 * No depende de ningún framework y no envía la contraseña a ningún sitio:
 * toda la comprobación es local; registrar.php valida de nuevo al guardar.
 */
(function () {
  'use strict';

  var RULES = {
    length: function (v) { return v.length >= 8; },
    uppercase: function (v) { return /[A-Z]/.test(v); },
    number: function (v) { return /\d/.test(v); },
    special: function (v) { return /[^A-Za-z0-9]/.test(v); },
  };

  function update(list) {
    var input = document.getElementById(list.dataset.pwTarget || list.dataset.input);
    if (!input) return;

    var value = input.value;
    var allValid = value.length > 0;

    list.querySelectorAll('li[data-rule]').forEach(function (item) {
      var check = RULES[item.dataset.rule];
      var valid = check ? check(value) : false;
      item.classList.toggle('valid', valid);
      if (!valid) allValid = false;
    });

    // Oculta la lista mientras el usuario no haya escrito nada.
    list.hidden = value.length === 0;

    // Estado global: cuando todo se cumple, la lista se marca como completa.
    list.classList.toggle('all-valid', allValid);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.pw-checklist[data-pw-target], [data-password-requirements][data-input]').forEach(function (list) {
      var input = document.getElementById(list.dataset.pwTarget || list.dataset.input);
      if (!input) return;

      update(list);
      input.addEventListener('input', function () { update(list); });
    });
  });
})();
