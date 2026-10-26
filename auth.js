/**
 * auth.js — Lógica de las páginas de login y registro de "alfa".
 *
 * Reemplaza el JavaScript que en parsenas venía embebido en las vistas Blade
 * y añade el envío por fetch a los endpoints PHP mínimos (iniciar_sesion.php
 * y registrar.php), mostrando los errores de validación en la propia página.
 */

(function () {
  'use strict';

  // --- Países para el select de prefijo (equivalente a config/countries.php) ---
  var PAISES = {
    '+57': 'Colombia',
    '+52': 'México',
    '+54': 'Argentina',
    '+56': 'Chile',
    '+51': 'Perú',
    '+58': 'Venezuela',
    '+593': 'Ecuador',
    '+591': 'Bolivia',
    '+595': 'Paraguay',
    '+598': 'Uruguay',
    '+34': 'España',
    '+1': 'Estados Unidos',
    '+55': 'Brasil'
  };

  // ===== Mostrar/ocultar contraseña =====
  document.querySelectorAll('.password-toggle-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.getElementById(button.dataset.target);
      if (!input) return;
      var isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      button.textContent = isPassword ? 'Ocultar' : 'Ver';
      button.setAttribute('aria-label', isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  });

  // ===== Registro: prefijo y teléfono =====
  var prefixInput = document.getElementById('telefono_prefijo');
  var phoneInput = document.getElementById('phone');
  var phoneWrapper = document.getElementById('phone-wrapper');

  if (prefixInput && prefixInput.options.length <= 1) {
    Object.keys(PAISES).forEach(function (prefix) {
      var option = document.createElement('option');
      option.value = prefix;
      option.textContent = prefix + ' — ' + PAISES[prefix];
      if (prefix === '+57') option.selected = true;
      prefixInput.appendChild(option);
    });
  }

  function formatPhone() {
    if (!phoneInput || !prefixInput) return;
    var phone = phoneInput.value.replace(/\D/g, '');
    var prefix = prefixInput.value;
    if (prefix.indexOf('+57') === 0 || prefix.indexOf('+1') === 0) {
      phone = phone.replace(/(\d{3})(\d{3})(\d{4}).*/, '$1 $2 $3');
    } else if (prefix.indexOf('+52') === 0) {
      phone = phone.replace(/(\d{2})(\d{4})(\d{4}).*/, '$1 $2 $3');
    } else if (prefix.indexOf('+34') === 0) {
      phone = phone.replace(/(\d{3})(\d{3})(\d{3}).*/, '$1 $2 $3');
    }
    phoneInput.value = phone.trim();
  }

  if (phoneInput) {
    phoneInput.addEventListener('input', formatPhone);
  }

  // ===== Utilidades para mostrar errores =====
  function mostrarError(id, mensaje) {
    var el = document.getElementById('error-' + id);
    if (el) {
      el.textContent = mensaje;
      el.hidden = false;
    }
    var campo = document.getElementById(id);
    if (campo) campo.classList.add('input-error');
  }

  function limpiarErrores() {
    document.querySelectorAll('.error-text').forEach(function (el) {
      el.hidden = true;
      el.textContent = '';
    });
    document.querySelectorAll('.input-error').forEach(function (el) {
      el.classList.remove('input-error');
    });
    if (phoneWrapper) phoneWrapper.classList.remove('input-error');
  }

  // ===== Envío del formulario de LOGIN =====
  var loginForm = document.getElementById('login-form');
  if (loginForm) {
    var loginStatus = document.getElementById('login-status');
    if (loginStatus && new URLSearchParams(window.location.search).get('status') === 'password-reset') {
      loginStatus.hidden = false;
    }
    var redirectInput = document.getElementById('login-redirect');
    var requestedRedirect = new URLSearchParams(window.location.search).get('redirect') || '';
    if (redirectInput && (/^clase-[0-8]\.html(?:\?paso=[1-9][0-9]*)?$/.test(requestedRedirect) || requestedRedirect === 'mensajes.php')) {
      redirectInput.value = requestedRedirect;
    }
    loginForm.addEventListener('submit', function (event) {
      event.preventDefault();
      limpiarErrores();

      fetch('iniciar_sesion.php', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(loginForm)
      })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
          if (data.ok) {
            window.location.href = data.redirect || 'index.html';
            return;
          }
          Object.keys(data.errores || {}).forEach(function (campo) {
            mostrarError(campo, data.errores[campo]);
          });
          if (data.errores && data.errores.form) mostrarError('form', data.errores.form);
        })
        .catch(function () {
          mostrarError('form', 'No se pudo conectar con el servidor. Inténtalo de nuevo.');
        });
    });
  }

  // ===== Envío del formulario de REGISTRO =====
  var registerForm = document.getElementById('register-form');
  if (registerForm) {
    registerForm.addEventListener('submit', function (event) {
      event.preventDefault();
      limpiarErrores();

      fetch('registrar.php', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(registerForm)
      })
        .then(function (resp) { return resp.json(); })
        .then(function (data) {
          if (data.ok) {
            window.location.href = data.redirect || 'index.html';
            return;
          }
          Object.keys(data.errores || {}).forEach(function (campo) {
            mostrarError(campo, data.errores[campo]);
          });
          if (data.errores && data.errores.form) mostrarError('form', data.errores.form);
        })
        .catch(function () {
          mostrarError('form', 'No se pudo conectar con el servidor. Inténtalo de nuevo.');
        });
    });
  }
})();
