(function () {
  'use strict';

  var recipientForm = document.querySelector('[data-recipient-form]');
  if (recipientForm) {
    var search = document.getElementById('recipient-search');
    var recipientId = recipientForm.querySelector('[data-recipient-id]');
    var results = document.getElementById('recipient-search-results');
    var empty = results.querySelector('[data-recipient-empty]');
    var status = recipientForm.querySelector('[data-recipient-status]');
    var options = Array.prototype.slice.call(results.querySelectorAll('[data-recipient-option]'));

    function normalize(value) {
      return value.trim().toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    function filterRecipients() {
      recipientId.value = '';
      var term = normalize(search.value);
      var matches = 0;
      options.forEach(function (option) {
        var visible = term !== '' && normalize(option.dataset.recipientName || '').includes(term);
        option.hidden = !visible;
        if (visible) matches++;
      });
      empty.hidden = term === '' || matches > 0;
      results.hidden = term === '';
      status.textContent = term === ''
        ? 'Escribe un nombre y selecciona una coincidencia.'
        : (matches > 0 ? 'Selecciona una coincidencia para continuar.' : 'No se encontraron coincidencias.');
    }

    search.addEventListener('input', filterRecipients);
    options.forEach(function (option) {
      option.addEventListener('click', function () {
        recipientId.value = option.dataset.recipientId;
        search.value = option.dataset.recipientName;
        results.hidden = true;
        status.textContent = 'Destinatario seleccionado: ' + option.dataset.recipientName;
      });
    });
    recipientForm.addEventListener('submit', function (event) {
      if (!recipientId.value) {
        event.preventDefault();
        status.textContent = 'Busca y selecciona una persona antes de enviar el mensaje.';
        search.focus();
      }
    });

    if (recipientId.value) {
      var selectedOption = options.find(function (option) {
        return option.dataset.recipientId === recipientId.value;
      });
      if (selectedOption) {
        search.value = selectedOption.dataset.recipientName;
        status.textContent = 'Destinatario seleccionado: ' + selectedOption.dataset.recipientName;
      }
    }
  }

  document.querySelectorAll('.message-list').forEach(function (history) {
    history.scrollTop = history.scrollHeight;
  });
})();
