(function () {
  'use strict';

  var errorBox = document.getElementById('perfil-error');
  var statusBox = document.getElementById('perfil-status');
  var statusMessages = {
    'profile-updated': 'Tus datos se actualizaron correctamente.',
    'password-updated': 'Tu contraseña se actualizó correctamente.',
    'mascota-name-updated': 'El nombre de tu mascota se guardó correctamente.',
    'mascota-removed': 'Mascota eliminada. Usas la predeterminada.'
  };

  function showStatus(message, isError) {
    if (errorBox) {
      errorBox.hidden = !isError;
      errorBox.textContent = isError ? message : '';
    }
    if (statusBox) {
      statusBox.hidden = isError || !message;
      statusBox.textContent = !isError ? message : '';
    }
  }

  var pageStatus = new URLSearchParams(window.location.search).get('status');
  if (pageStatus && statusMessages[pageStatus]) showStatus(statusMessages[pageStatus], false);

  fetch('perfil.php', { headers: { Accept: 'application/json' } })
    .then(function (response) {
      if (!response.ok) throw new Error(response.status === 401 ? 'Inicia sesión para ver tu perfil.' : 'No se pudo cargar el perfil.');
      return response.json();
    })
    .then(function (data) {
      if (!data.ok) throw new Error(data.error || 'No se pudo cargar el perfil.');

      var user = data.usuario;
      document.getElementById('perfil-name-input').value = user.name || '';
      document.getElementById('perfil-apellido-input').value = user.apellido || '';
      document.getElementById('perfil-email-input').value = user.email || '';
      document.getElementById('profile-mascota-name').value = user.mascota_nombre || '';
      document.querySelectorAll('[data-csrf-token]').forEach(function (input) {
        input.value = data.csrf_token;
      });

      var phone = String(user.telefono || '').trim();
      var phoneMatch = phone.match(/^(\+\d{1,4})\s*(.*)$/);
      if (phoneMatch) {
        document.getElementById('profile-telefono-prefijo').value = phoneMatch[1];
        document.getElementById('profile-telefono').value = phoneMatch[2];
      } else {
        document.getElementById('profile-telefono').value = phone;
      }
      document.getElementById('profile-telefono').addEventListener('input', function (event) {
        var value = event.target.value.replace(/\D/g, '');
        var prefix = document.getElementById('profile-telefono-prefijo').value;
        if (prefix === '+57') value = value.replace(/(\d{3})(\d{3})(\d{4}).*/, '$1 $2 $3');
        else if (prefix === '+52' || prefix === '+1') value = value.replace(/(\d{3})(\d{3})(\d{4}).*/, '$1 $2 $3');
        else if (prefix === '+34') value = value.replace(/(\d{3})(\d{3})(\d{3}).*/, '$1 $2 $3');
        event.target.value = value.trim();
      });

      var pet = document.getElementById('perfil-mascota');
      var noPet = document.getElementById('perfil-sin-mascota');
      if (user.mascota_path) {
        document.getElementById('perfil-mascota-img').src = user.mascota_path;
        pet.hidden = false;
        noPet.hidden = true;
      }
    })
    .catch(function (error) {
      showStatus(error.message || 'No se pudo conectar con el servidor.', true);
    });

  document.querySelectorAll('[data-profile-action]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var action = form.querySelector('[name="action"]').value;
      if (action === 'pet_upload') {
        var petButton = form.querySelector('button[type="submit"]');
        if (petButton) petButton.disabled = true;
        var csrf = form.querySelector('[name="csrf_token"]').value;
        fetch('mascota.php', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          body: new FormData(form)
        })
          .then(function (response) {
            return response.json().then(function (data) {
              if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo guardar la imagen de la mascota.');
              return data;
            });
          })
          .then(function (data) {
            var pet = document.getElementById('perfil-mascota');
            document.getElementById('perfil-mascota-img').src = data.path;
            pet.hidden = false;
            document.getElementById('perfil-sin-mascota').hidden = true;
            form.reset();
            showStatus('La imagen de tu mascota se guardó correctamente.', false);
          })
          .catch(function (error) {
            showStatus(error.message || 'No se pudo guardar la imagen de la mascota.', true);
          })
          .finally(function () {
            if (petButton) petButton.disabled = false;
          });
        return;
      }

      var button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      showStatus('', false);
      fetch('perfil_action.php', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: new FormData(form)
      })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo guardar el cambio.');
            return data;
          });
        })
        .then(function (data) {
          if (data.redirect) {
            window.location.assign(data.redirect);
            return;
          }
          if (action === 'pet_remove') {
            document.getElementById('perfil-mascota').hidden = true;
            document.getElementById('perfil-sin-mascota').hidden = false;
          }
          if (action === 'profile_update') {
            var greetingLink = document.querySelector('.user-greeting-link');
            if (greetingLink) greetingLink.textContent = form.querySelector('[name="name"]').value;
          }
          showStatus(data.message || statusMessages[data.status] || 'Cambios guardados.', false);
          if (action !== 'profile_update' && action !== 'pet_name') form.reset();
        })
        .catch(function (error) {
          showStatus(error.message || 'No se pudo conectar con el servidor.', true);
        })
        .finally(function () {
          if (button) button.disabled = false;
        });
    });
  });

  var deleteTrigger = document.getElementById('profile-delete-trigger');
  var deleteConfirmation = document.getElementById('profile-delete-confirmation');
  var deleteCancel = document.getElementById('profile-delete-cancel');

  function setDeleteConfirmationOpen(open) {
    deleteConfirmation.hidden = !open;
    deleteTrigger.setAttribute('aria-expanded', String(open));
    if (open) document.getElementById('profile-delete-password').focus();
  }

  deleteTrigger.addEventListener('click', function () {
    setDeleteConfirmationOpen(deleteConfirmation.hidden);
  });
  deleteCancel.addEventListener('click', function () {
    setDeleteConfirmationOpen(false);
  });

  document.querySelectorAll('.password-toggle-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.getElementById(button.dataset.target);
      if (!input) return;
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.textContent = visible ? 'Ocultar' : 'Ver';
      button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  });
})();
