/**
 * clase-steps.js — Stepper y evaluación de las clases de "alfa".
 *
 * Reemplaza al JavaScript que en parsenas venía embebido en la vista
 * clases/show.blade.php. Maneja:
 *   1. El paso a paso (.lesson-step-card / .lesson-step-dot), sin permitir
 *      saltarse pasos que no se hayan visto antes.
 *   2. La barra de progreso y los indicadores.
 *   3. La evaluación: califica en el propio navegador y, si el usuario está
 *      autenticado, guarda avance y desbloqueo en la BD a través de los
 *      endpoints PHP (avance.php y evaluacion.php).
 *   4. La mascota flotante del usuario.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var shell = document.querySelector('.lesson-shell');
    if (!shell) return;

    var lesson = Number(shell.dataset.lesson);
    var total = Number(shell.dataset.total);
    var step = Number(shell.dataset.current) || 1;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.lesson-step-card'));
    var dots = Array.prototype.slice.call(document.querySelectorAll('.lesson-step-dot'));
    var previous = document.getElementById('lesson-previous');
    var next = document.getElementById('lesson-next');
    var bar = document.getElementById('lesson-progress-bar');
    var progressBox = bar ? bar.closest('.lesson-progress') : null;
    var stepValue = document.getElementById('lesson-step-value');
    var value = document.getElementById('lesson-progress-value');
    var quiz = document.getElementById('lesson-quiz');
    var quizResult = document.getElementById('lesson-quiz-result');
    var restart = document.getElementById('lesson-restart');
    var quizSubmit = document.getElementById('lesson-quiz-submit');

    var maxVisited = step;
    var autenticado = shell.dataset.authenticated === '1';
    var claseActualUsuario = null;
    var claseCompletada = false;
    var evaluacionAprobada = false;
    var estadoUsuario = null;
    var pasoSolicitado = parseInt(new URLSearchParams(window.location.search).get('paso'), 10);

    // ===== Estado de sesión: saber si hay login y si ya tiene mascota =====
    // La barra de clases reutiliza inicio.js para preferencias y mascota.
    fetch('estado_sesion.php', { headers: { Accept: 'application/json' } })
      .then(function (resp) { return resp.json(); })
      .then(function (estado) {
        estadoUsuario = estado.usuario || null;
        if (!estado.autenticado || !estado.usuario) {
          // La Clase 0 es pública; las clases 1–8 requieren sesión.
          if (lesson > 0) {
            window.location.replace('login.html?redirect=clase-' + lesson + '.html');
            return;
          }
          return;
        }
        autenticado = true;
        shell.dataset.authenticated = '1';

        // El navegador también protege el acceso directo a archivos HTML.
        // A new class becomes available only after the evaluation updates the
        // user's current-class value in the database.
        var claseMaxima = Number(estado.usuario.numero_clase || 0);
        claseActualUsuario = claseMaxima;
        if (lesson > claseMaxima) {
          window.location.replace('continuar.php');
          return;
        }
        claseCompletada = lesson < claseMaxima
          || (lesson === claseMaxima && Number(estado.usuario.porcentaje_clase || 0) >= 100);
        if (claseCompletada) maxVisited = total;

        // Si el usuario ya había avanzado en esta clase, respetar su paso.
        if (Number(estado.usuario.numero_clase) === lesson) {
          var guardado = Math.min(total, Math.max(1, Math.round((estado.usuario.porcentaje_clase / 100) * total)));
          if (guardado > maxVisited) {
            maxVisited = guardado;
            goTo(guardado);
          }
        }
        if (!isNaN(pasoSolicitado) && pasoSolicitado >= 1
          && (claseCompletada || pasoSolicitado <= maxVisited)) {
          step = Math.min(total, pasoSolicitado);
          maxVisited = Math.max(maxVisited, step);
        }
        render();

        // Mascota flotante, también disponible dentro de las clases.
        if (estado.usuario.mascota_path && !document.querySelector('.lesson-mascota')) {
          var petName = String(estado.usuario.mascota_nombre || '').trim();
          var aside = document.createElement('aside');
          aside.className = 'lesson-mascota';
          if (petName) aside.classList.add('has-name');
          aside.setAttribute('aria-label', 'Mascota de aprendizaje');
          var img = document.createElement('img');
          img.src = estado.usuario.mascota_path;
          img.alt = petName || 'Tu mascota';
          aside.appendChild(img);
          if (petName) {
            var name = document.createElement('p');
            name.className = 'lesson-mascota-name';
            name.textContent = petName;
            aside.appendChild(name);
          }
          var dialogo = document.createElement('div');
          dialogo.className = 'mascota-dialogo';
          dialogo.setAttribute('role', 'status');
          dialogo.setAttribute('aria-live', 'polite');
          aside.appendChild(dialogo);
          document.body.classList.toggle('mascota-hidden', localStorage.getItem('parseñas-mascota-hidden') === 'true');
          function speak() {
            var frases = ['✨ Bienvenido a PARSEÑAS', '🌟 ¡Sigue aprendiendo!', '💫 La comunicación no tiene barreras'];
            dialogo.textContent = (petName ? petName + ': ' : '') + frases[Math.floor(Math.random() * frases.length)];
            dialogo.style.display = 'block';
            window.setTimeout(function () { dialogo.style.display = 'none'; }, 5000);
          }
          img.addEventListener('click', speak);
          img.addEventListener('error', function () {
            img.hidden = true;
            var fallback = document.createElement('button');
            fallback.type = 'button';
            fallback.className = 'mascota-placeholder';
            fallback.setAttribute('aria-label', 'Mostrar mensaje de la mascota');
            fallback.textContent = '🤟';
            fallback.addEventListener('click', speak);
            aside.insertBefore(fallback, name || dialogo);
          }, { once: true });
          document.body.appendChild(aside);
        }
      })
      .catch(function (error) {
        console.error('No se pudo verificar el acceso a la clase.', error);
        if (lesson > 0) {
          window.location.replace('login.html?redirect=clase-' + lesson + '.html');
        }
      });

    // ===== Guardar el avance en la BD =====
    function saveProgress() {
      if (!autenticado || claseActualUsuario !== lesson || claseCompletada) return;
      fetch('avance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          lesson: lesson,
          percentage: Math.round((step / total) * 100),
          step: step
        })
      })
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok || !data.ok) {
              throw new Error(data.mensaje || 'No se pudo guardar el progreso.');
            }
          });
        })
        .catch(function (error) {
          console.error(error);
          if (quizResult) {
            quizResult.textContent = error.message;
            quizResult.classList.add('is-error');
          }
        });
    }

    // ===== Render del paso actual =====
    function render() {
      cards.forEach(function (card) {
        card.hidden = Number(card.dataset.step) !== step;
      });

      var percentage = Math.round((step / total) * 100);
      if (bar) bar.style.width = percentage + '%';
      if (value) value.textContent = percentage;
      if (stepValue) stepValue.textContent = step;
      if (progressBox) progressBox.setAttribute('aria-valuenow', String(percentage));

      dots.forEach(function (dot) {
        var target = Number(dot.dataset.goto);
        dot.classList.toggle('is-active', target === step);
        dot.classList.toggle('is-done', target < step);
        dot.disabled = !claseCompletada && target > maxVisited;
      });

      if (next) next.classList.toggle('is-hidden', step === total);
    }

    function goTo(target) {
      step = Math.min(total, Math.max(1, target));
      maxVisited = Math.max(maxVisited, step);
      render();
      saveProgress();
    }

    if (next) {
      next.addEventListener('click', function () {
        if (step < total) {
          goTo(step + 1);
        } else {
          if (!claseCompletada && !evaluacionAprobada) {
            if (!autenticado && lesson > 0) {
              window.location.href = 'login.html?redirect=clase-' + lesson + '.html';
              return;
            }
            if (quizResult) {
              quizResult.textContent = 'Aprueba la evaluación para desbloquear la siguiente clase.';
              quizResult.classList.add('is-error');
            }
            return;
          }
          saveProgress();
          if (lesson < 8) {
            window.location.href = 'clase-' + (lesson + 1) + '.html';
          } else {
            window.location.href = 'index.html';
          }
        }
      });
    }

    if (previous) {
      previous.addEventListener('click', function () {
        if (step === 1) {
          window.location.href = previous.dataset.previousUrl || 'index.html';
          return;
        }
        goTo(step - 1);
      });
    }

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        var target = Number(dot.dataset.goto);
        if (dot.disabled || target > maxVisited) return;
        goTo(target);
      });
    });

    // ===== Evaluación =====
    if (quiz) {
      quiz.addEventListener('submit', function (event) {
        event.preventDefault();

        var fields = Array.prototype.slice.call(quiz.querySelectorAll('fieldset'));
        var contestadas = fields.every(function (field) { return field.querySelector('input:checked'); });
        if (!contestadas) {
          if (quizResult) {
            quizResult.textContent = 'Debes responder todas las preguntas antes de calificar.';
            quizResult.classList.add('is-error');
          }
          return;
        }

        var aciertos = 0;
        fields.forEach(function (field) {
          var correcta = Number(field.dataset.answer);
          var elegida = Number(field.querySelector('input:checked').value);
          var esCorrecta = elegida === correcta;
          if (esCorrecta) aciertos++;

          Array.prototype.forEach.call(field.querySelectorAll('.lesson-option'), function (label) {
            var opcion = Number(label.dataset.option);
            label.classList.toggle('is-correct', opcion === correcta);
            label.classList.toggle('is-wrong', opcion === elegida && !esCorrecta);
          });

          var detalle = field.querySelector('.lesson-question-result');
          if (detalle) {
            detalle.textContent = esCorrecta
              ? 'Correcta.'
              : 'Incorrecta. La respuesta correcta es ' + String.fromCharCode(65 + correcta) + '.';
            detalle.classList.toggle('is-success', esCorrecta);
            detalle.classList.toggle('is-error', !esCorrecta);
          }
        });

        var totalPreguntas = fields.length;
        var porcentaje = Math.round((aciertos / totalPreguntas) * 100);
        var aprobado = porcentaje >= 75;

        // Bloquear la evaluación y las respuestas
        quiz.querySelectorAll('input').forEach(function (input) { input.disabled = true; });
        if (quizSubmit) quizSubmit.disabled = true;

        if (quizResult) {
          quizResult.textContent = aprobado
            ? (lesson < 8
                ? '¡Aprobaste! Ya puedes continuar a la Clase ' + (lesson + 1) + '. Resultado: ' + aciertos + '/' + totalPreguntas + ' (' + porcentaje + '%).'
                : '¡Felicitaciones! Has completado todo el curso de PARSEÑAS. Resultado: ' + aciertos + '/' + totalPreguntas + ' (' + porcentaje + '%).')
            : 'No alcanzaste el 75%. Repasa la clase y vuelve a intentarlo. Resultado: ' + aciertos + '/' + totalPreguntas + ' (' + porcentaje + '%).';
          quizResult.classList.toggle('is-success', aprobado);
          quizResult.classList.toggle('is-error', !aprobado);
        }

        if (restart) restart.classList.toggle('is-hidden', aprobado);

        if (!autenticado || claseCompletada) {
          evaluacionAprobada = aprobado;
          return;
        }

        fetch('evaluacion.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ lesson: lesson, passed: aprobado })
        })
          .then(function (response) {
            return response.json().then(function (data) {
              if (!response.ok || !data.ok) {
                throw new Error(data.mensaje || 'No se pudo guardar el resultado de la evaluación.');
              }
              return data;
            });
          })
          .then(function () {
            evaluacionAprobada = aprobado;
            if (aprobado) {
              maxVisited = total;
              claseCompletada = true;
              render();
            }
          })
          .catch(function (error) {
            if (quizResult) {
              quizResult.textContent = error.message;
              quizResult.classList.remove('is-success');
              quizResult.classList.add('is-error');
            }
          });
      });
    }

    if (restart) {
      restart.addEventListener('click', function () {
        window.location.href = 'clase-' + lesson + '.html';
      });
    }

    render();
  });
})();
