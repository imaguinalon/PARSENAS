const sharedNavigation = `
  <header class="top-bar" aria-label="Navegación principal">
    <a href="index.html" class="brand-label">PARSEÑAS</a>
    <nav class="menu-rectangulo" aria-label="Secciones">
      <a href="index.html#top">Inicio</a>
      <a href="index.html#clases">Clases</a>
      <a href="index.html#contacto">Contáctanos</a>
    </nav>
    <form class="search-form" action="index.html#clases" method="get" role="search">
      <input class="search-input" name="q" type="search" placeholder="Buscar palabra o una clase..." aria-label="Buscar">
      <button class="search-btn" type="submit" aria-label="Buscar">&#128269;</button>
    </form>
    <div class="auth-actions" id="auth-actions">
      <a href="register.html" class="auth-btn" id="btn-registrarme">Registrarme</a>
      <a href="login.html" class="auth-btn" id="btn-login">Iniciar sesión</a>
      <span class="user-greeting" id="user-greeting" hidden></span>
    </div>
    <div class="top-bar-tools">
      <a href="mensajes.php" class="messages-nav-link" id="messages-link" aria-label="Mensajes recibidos" hidden>
        <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
          <path d="M3.5 6.5h17v11h-17zM4 7l8 6 8-6" />
        </svg>
        <span class="messages-unread-badge messages-nav-count" data-messages-unread data-message-count hidden></span>
      </a>
      <div class="profile-menu-wrapper">
        <button type="button" id="profile-btn" aria-label="Abrir preferencias" aria-expanded="false">⚙</button>
        <div class="profile-menu" id="profile-menu" hidden>
          <p class="profile-menu-title">Preferencias</p>
          <label class="profile-option" for="theme-toggle">
            <span>Tema claro</span>
            <input type="checkbox" id="theme-toggle">
          </label>
          <label class="profile-option" for="font-size-select">
            <span>Tamaño de texto</span>
            <select id="font-size-select">
              <option value="normal">Normal</option>
              <option value="large">Grande</option>
              <option value="xl">Muy grande</option>
            </select>
          </label>
          <div class="profile-menu-actions">
            <button type="button" class="profile-action-btn" id="toggle-mascota-btn">Ocultar mascota</button>
            <div id="acciones-sesion" hidden>
              <a href="perfil.html" class="profile-action-btn" id="link-perfil" hidden>Editar perfil</a>
              <a href="cerrar_sesion.php" class="profile-action-btn" id="link-logout" hidden>Cerrar sesión</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>
`;

const currentNavigation = document.querySelector('.top-bar');
if (currentNavigation) {
  currentNavigation.outerHTML = sharedNavigation;
}

if (!document.querySelector('meta[name="class-search-url"]')) {
  const searchUrl = document.createElement('meta');
  searchUrl.id = 'class-search-url';
  searchUrl.name = 'class-search-url';
  searchUrl.content = 'buscar_clases.php';
  document.head.appendChild(searchUrl);
}

if (!document.getElementById('admin-panel')) {
  document.querySelector('.top-bar')?.insertAdjacentHTML('afterend', `
    <div id="admin-panel" hidden>
      <button type="button" class="admin-toggle" id="admin-toggle" aria-label="Abrir panel de administración" aria-controls="admin-drawer" aria-expanded="false">‹<small>Admin</small></button>
      <aside class="admin-drawer" id="admin-drawer" aria-label="Panel de administración" aria-hidden="true">
        <button type="button" class="admin-close" id="admin-close" aria-label="Cerrar panel">×</button>
        <span class="admin-kicker">Administración</span>
        <h2>Usuarios</h2>
        <p>Gestiona cuentas, datos y permisos.</p>
        <a href="admin_usuarios.php" class="admin-open-link">Abrir gestión completa →</a>
      </aside>
      <div class="admin-scrim" id="admin-scrim"></div>
    </div>
  `);
}

if (!document.getElementById('search-panel')) {
  document.body.insertAdjacentHTML('beforeend', `
    <div class="search-scrim" id="search-scrim" hidden></div>
    <aside class="search-panel" id="search-panel" aria-label="Resultados de búsqueda" hidden>
      <button type="button" class="search-panel-close" id="search-panel-close" aria-label="Cerrar resultados">×</button>
      <h3 class="search-panel-title">🔍 Resultados</h3>
      <p class="search-panel-hint" id="search-panel-hint"></p>
      <div class="search-panel-results" id="search-panel-results"></div>
    </aside>
  `);
}

window.addEventListener('DOMContentLoaded', () => {

  // ===== ESTADO DE SESIÓN =====
  // index.html es estático: preguntamos al PHP mínimo por el usuario
  // y ajustamos la UI (saludo, botones de acceso, panel admin, mascota).
  // El menú de preferencias también está disponible para las personas invitadas.
  const profileMenuWrapper = document.querySelector('.profile-menu-wrapper');
  const accionesSesion = document.getElementById('acciones-sesion');
  let csrfToken = null;
  const messagesLink = document.getElementById('messages-link');
  if (accionesSesion) accionesSesion.hidden = true;

  fetch('estado_sesion.php', { headers: { Accept: 'application/json' } })
    .then((resp) => resp.json())
    .then((estado) => {
      const greeting = document.getElementById('user-greeting');
      const btnRegistro = document.getElementById('btn-registrarme');
      const btnLogin = document.getElementById('btn-login');
      const linkPerfil = document.getElementById('link-perfil');
      const linkLogout = document.getElementById('link-logout');
      const accesoInvitado = document.getElementById('acceso-invitado');
      const adminPanel = document.getElementById('admin-panel');
      const tituloClases = document.getElementById('sec-clases-title');
      const subClases = document.getElementById('sec-clases-sub');

      if (estado.autenticado && estado.usuario) {
        csrfToken = estado.csrf_token || null;
        window.__parseñasGuest = false;
        window.__parseñasMaxClass = Number(estado.usuario.numero_clase || 0);
        // Reaplicar el desbloqueo de tarjetas del carrusel y del pie.
        actualizarBloqueos();

        // Usuario con sesión: sí se muestra el menú de preferencias.
        if (profileMenuWrapper) profileMenuWrapper.hidden = false;

        if (greeting) {
          const profileShortcut = document.createElement('a');
          profileShortcut.href = 'perfil.html';
          profileShortcut.className = 'user-greeting-link';
          profileShortcut.textContent = estado.usuario.name;
          greeting.replaceChildren(document.createTextNode('Hola, '), profileShortcut);
          greeting.hidden = false;
        }
        if (btnRegistro) btnRegistro.hidden = true;
        if (btnLogin) btnLogin.hidden = true;
        if (accionesSesion) accionesSesion.hidden = false;
        if (linkPerfil) {
          linkPerfil.hidden = false;
          linkPerfil.classList.remove('is-locked');
        }
        if (linkLogout) linkLogout.hidden = false;
        if (accesoInvitado) accesoInvitado.hidden = true;
        if (adminPanel && estado.es_admin) adminPanel.hidden = false;
        if (messagesLink) {
          messagesLink.hidden = false;
          const count = messagesLink.querySelector('[data-message-count]');
          if (count) {
            count.textContent = Number(estado.mensajes_no_leidos) > 0 ? String(estado.mensajes_no_leidos) : '';
            count.hidden = Number(estado.mensajes_no_leidos) < 1;
          }
        }
        if (tituloClases) tituloClases.textContent = '¡Hola, ' + estado.usuario.name + '! ¿Qué clase quieres ver hoy?';
        if (subClases) subClases.textContent = 'Aprende a tu ritmo y continúa tu progreso — cada paso queda guardado para ti.';

        // Mascota ya subida: mostrarla y ocultar el uploader.
        const mascotaImg = document.getElementById('mascota-img');
        const mascotaContainer = document.getElementById('mascota-container');
        const mascotaNombre = document.getElementById('mascota-nombre');
        const uploader = document.getElementById('mascota-uploader');
        const cambiar = document.getElementById('mascota-cambiar');
        const mascotaTexto = document.getElementById('mascota-texto');
        const mascotaNameForm = document.getElementById('mascota-name-form');
        if (mascotaNameForm) mascotaNameForm.hidden = false;
        if (mascotaNombre && estado.usuario.mascota_nombre) {
          mascotaNombre.textContent = estado.usuario.mascota_nombre;
          mascotaNombre.hidden = false;
        }
        if (estado.usuario.mascota_path) {
          window.__parseñasHasMascota = true;
          if (mascotaContainer) {
            mascotaContainer.dataset.mascotaAvailable = '1';
            mascotaContainer.hidden = false;
          }
          const mascotaOculta = localStorage.getItem('parseñas-mascota-hidden') === 'true';
          document.body.classList.toggle('mascota-hidden', mascotaOculta);
          if (mascotaImg) {
            mascotaImg.src = estado.usuario.mascota_path;
            mascotaImg.style.display = 'block';
          }
          // Solo se muestra el texto/botón de quien YA subió la mascota.
          if (uploader) uploader.hidden = true;
          if (cambiar) cambiar.hidden = false;
          if (mascotaTexto) mascotaTexto.textContent = TEXTO_CON_MASCOTA;
        }
        if (toggleMascotaBtn) {
          toggleMascotaBtn.hidden = !estado.usuario.mascota_path;
          toggleMascotaBtn.textContent = document.body.classList.contains('mascota-hidden')
            ? 'Mostrar mascota'
            : 'Ocultar mascota';
        }
      }
    })
    .catch((error) => { console.error('No se pudo cargar el estado de sesión.', error); });

  window.setInterval(() => {
    if (!messagesLink || messagesLink.hidden) return;
    fetch('estado_sesion.php', { headers: { Accept: 'application/json' } })
    .then((response) => {
      if (!response.ok) throw new Error('No se pudo actualizar el buzón.');
      return response.json();
    })
    .then((estado) => {
      const count = messagesLink.querySelector('[data-message-count]');
      if (!count) return;
      const unread = Number(estado.mensajes_no_leidos || 0);
      count.textContent = unread > 0 ? String(unread) : '';
      count.hidden = unread < 1;
    })
    .catch((error) => console.error('No se pudo actualizar el buzón.', error));
  }, 20000);

  // ===== BLOQUEO DE CLASES =====
  // Los invitados solo pueden abrir la Clase 0. El resto de clases muestra
  // un candado y, al pulsarlas, avisa al usuario que debe registrarse.
  // Al iniciar sesión, actualizarBloqueos() se vuelve a llamar con el
  // numero_clase real del usuario para desbloquear lo que le corresponde.
  const TEXTO_CON_MASCOTA = '🐾 ¿Sabías que si oprimes la cabeza de tu mascota te da frases de motivación? ¡Pruébalo abajo a la derecha!';

  function claseAccesible(n) {
    if (n === 0) return true;
    return !Boolean(window.__parseñasGuest) && n <= Number(window.__parseñasMaxClass ?? 0);
  }

  function avisarClaseBloqueada(n) {
    if (n === 0) return;
    if (Boolean(window.__parseñasGuest)) {
      window.location.href = 'login.html?redirect=clase-' + n + '.html';
    } else {
      window.location.href = 'continuar.php';
    }
  }

  function actualizarBloqueos() {
    // El carrusel conserva exactamente las clases y estilos de PARSEÑAS.
    // La restricción se aplica únicamente al abrir la tarjeta central.
    document.querySelectorAll('.class-card').forEach((card) => {
      card.removeAttribute('aria-disabled');
      card.querySelector('.class-card-lock')?.remove();
      card.classList.remove('is-locked');
    });

      document.querySelectorAll('.footer-col a[href*="clase-"]').forEach((link) => {
        const match = (link.getAttribute('href') || '').match(/clase-(\d+)\.html/);
        if (!match) return;
        const lessonNumber = Number(match[1]);
        const locked = !claseAccesible(lessonNumber);
        link.classList.toggle('footer-class-locked', locked);
        if (locked) {
          link.setAttribute('aria-label', `🔒 Clase ${lessonNumber} bloqueada. Regístrate para acceder.`);
          link.title = 'Clase bloqueada. Regístrate para acceder.';
        } else {
          link.removeAttribute('aria-label');
          link.removeAttribute('title');
        }
      });
  }

  // El pie de página sí necesita una protección independiente.
  // Las tarjetas se controlan en el manejador del carrusel para conservar
  // exactamente la interacción de PARSEÑAS: lateral = centrar, central = abrir.
  document.addEventListener('click', (event) => {
    const enlace = event.target.closest('.footer-col a');
    if (!enlace) return;
    const match = (enlace.getAttribute('href') || '').match(/clase-(\d+)\.html/);
    if (match && !claseAccesible(Number(match[1]))) {
      event.preventDefault();
      if (Boolean(window.__parseñasGuest)) {
        window.location.href = 'register.html';
      } else {
        window.location.href = 'continuar.php';
      }
    }
  });

  actualizarBloqueos();

  const adminDrawer = document.getElementById('admin-drawer');
  const adminScrim = document.getElementById('admin-scrim');
  const adminToggle = document.getElementById('admin-toggle');
  const adminClose = document.getElementById('admin-close');
  function setAdminOpen(open) {
    if (!adminDrawer) return;
    adminDrawer.classList.toggle('is-open', open);
    adminScrim.classList.toggle('is-visible', open);
    adminDrawer.setAttribute('aria-hidden', String(!open));
    adminToggle?.setAttribute('aria-expanded', String(open));
    // El botón Admin desaparece con el panel abierto y vuelve al cerrarlo
    if (adminToggle) adminToggle.style.visibility = open ? 'hidden' : 'visible';
  }
  adminToggle?.addEventListener('click', () => setAdminOpen(!adminDrawer.classList.contains('is-open')));
  adminClose?.addEventListener('click', () => setAdminOpen(false));
  adminScrim?.addEventListener('click', () => setAdminOpen(false));

  const profileBtn = document.getElementById('profile-btn');
  const profileMenu = document.getElementById('profile-menu');
  const themeToggle = document.getElementById('theme-toggle');
  const fontSizeSelect = document.getElementById('font-size-select');
  const toggleMascotaBtn = document.getElementById('toggle-mascota-btn');
  const mascotaContainer = document.getElementById('mascota-container');
  const lessonMascota = document.querySelector('.lesson-mascota');

  function setProfileMenuOpen(isOpen) {
    if (!profileMenu || !profileBtn) return;
    profileMenu.hidden = !isOpen;
    profileBtn.setAttribute('aria-expanded', String(isOpen));
  }

  if (profileBtn && profileMenu) {
    profileBtn.addEventListener('click', (event) => {
      event.stopPropagation();
      setProfileMenuOpen(profileMenu.hidden);
    });

    document.addEventListener('click', (event) => {
      if (!profileMenu.contains(event.target) && !profileBtn.contains(event.target)) {
        setProfileMenuOpen(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        setProfileMenuOpen(false);
      }
    });
  }

  function applyPreferences() {
    const storedTheme = localStorage.getItem('parseñas-theme') || 'dark';
    const storedFontSize = localStorage.getItem('parseñas-font-size') || 'normal';
    const mascotaAvailable = mascotaContainer?.dataset.mascotaAvailable === '1';
    const storedMascotaHidden = !mascotaAvailable || localStorage.getItem('parseñas-mascota-hidden') === 'true';

    document.body.classList.toggle('theme-light', storedTheme === 'light');
    document.body.classList.toggle('theme-dark', storedTheme === 'dark');

    document.documentElement.classList.remove('font-large', 'font-xlarge');
    if (storedFontSize === 'large') document.documentElement.classList.add('font-large');
    if (storedFontSize === 'xl') document.documentElement.classList.add('font-xlarge');

    document.body.classList.toggle('mascota-hidden', storedMascotaHidden);
    if (mascotaContainer) mascotaContainer.hidden = !mascotaAvailable;

    if (themeToggle) themeToggle.checked = storedTheme === 'light';
    if (fontSizeSelect) fontSizeSelect.value = storedFontSize;
    if (toggleMascotaBtn) toggleMascotaBtn.textContent = storedMascotaHidden ? 'Mostrar mascota' : 'Ocultar mascota';
    if (toggleMascotaBtn) toggleMascotaBtn.hidden = !mascotaAvailable;
  }

  if (themeToggle) {
    themeToggle.addEventListener('change', () => {
      const isLight = themeToggle.checked;
      document.body.classList.toggle('theme-light', isLight);
      document.body.classList.toggle('theme-dark', !isLight);
      localStorage.setItem('parseñas-theme', isLight ? 'light' : 'dark');
    });
  }

  if (fontSizeSelect) {
    fontSizeSelect.addEventListener('change', () => {
      const value = fontSizeSelect.value;
      document.documentElement.classList.remove('font-large', 'font-xlarge');
      if (value === 'large') document.documentElement.classList.add('font-large');
      if (value === 'xl') document.documentElement.classList.add('font-xlarge');
      localStorage.setItem('parseñas-font-size', value);
    });
  }

  if (toggleMascotaBtn && mascotaContainer) {
    toggleMascotaBtn.addEventListener('click', () => {
      if (mascotaContainer.dataset.mascotaAvailable !== '1') return;
      const isHidden = document.body.classList.toggle('mascota-hidden');
      localStorage.setItem('parseñas-mascota-hidden', String(isHidden));
      toggleMascotaBtn.textContent = isHidden ? 'Mostrar mascota' : 'Ocultar mascota';
    });
  }

  applyPreferences();

  // ===== BARRA DE BÚSQUEDA: PANEL LATERAL =====
  // Busca una palabra/frase en las clases y muestra un panel lateral en la
  // derecha con TODAS las coincidencias. Respeta el progreso: solo se listan
  // como accesibles las clases que el usuario ya alcanzó (0..numero_clase);
  // el resto aparece bloqueado con 🔒 y sin enlace.
  const searchForm = document.querySelector('.search-form');
  const searchPanel = document.getElementById('search-panel');
  const searchPanelResults = document.getElementById('search-panel-results');
  const searchPanelHint = document.getElementById('search-panel-hint');
  const searchPanelClose = document.getElementById('search-panel-close');
  const searchScrim = document.getElementById('search-scrim');
  function setSearchPanelOpen(open) {
    if (!searchPanel) return;
    searchPanel.hidden = !open;
    searchScrim.hidden = !open;
    if (open) {
      // Animación de entrada
      requestAnimationFrame(() => searchPanel.classList.add('is-open'));
    } else {
      searchPanel.classList.remove('is-open');
    }
  }

  searchPanelClose?.addEventListener('click', () => setSearchPanelOpen(false));
  searchScrim?.addEventListener('click', () => setSearchPanelOpen(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setSearchPanelOpen(false); });

  if (searchForm && searchPanelResults && searchPanelHint) {
    searchForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const q = (searchForm.elements.namedItem('q')?.value || '').trim();
      if (!q) return;

      searchPanelHint.textContent = 'Buscando clases relacionadas...';
      searchPanelResults.replaceChildren();
      setSearchPanelOpen(true);

      try {
        const searchUrl = document.querySelector('meta[name="class-search-url"]')?.content;
        if (!searchUrl) throw new Error('No se encontró el servicio de búsqueda.');
        const response = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}`, {
          headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'No se pudieron buscar las clases.');

        searchPanelHint.textContent = data.has_matches
          ? `Clases relacionadas con “${q}”:`
          : `No encontramos una coincidencia exacta para “${q}”. Te mostramos todas las clases para que explores:`;

        for (const info of data.results) {
          const item = document.createElement(info.accessible ? 'a' : 'div');
          item.className = `search-result ${info.accessible ? '' : 'is-locked'}`;
          if (info.accessible) {
            item.href = info.url;
            item.addEventListener('click', () => setSearchPanelOpen(false));
          } else {
            item.setAttribute('aria-disabled', 'true');
          }

          const badge = document.createElement('span');
          badge.className = 'search-result-badge';
          badge.textContent = `Clase ${info.number}`;

          const body = document.createElement('span');
          body.className = 'search-result-body';
          const title = document.createElement('strong');
          title.textContent = info.title;
          body.appendChild(title);

          const description = document.createElement('span');
          description.textContent = info.description;
          body.appendChild(description);

          const note = document.createElement('small');
          note.textContent = info.accessible
            ? 'Haz clic para abrir esta clase'
            : '🔒 Bloqueada — inicia sesión y avanza para desbloquearla';
          body.appendChild(note);

          item.appendChild(badge);
          item.appendChild(body);
          searchPanelResults.appendChild(item);
        }
      } catch (error) {
        searchPanelHint.textContent = error.message || 'Ocurrió un error al buscar. Inténtalo de nuevo.';
      }
    });
  }
  /* =========================
     MASCOTA (página principal)
  ========================= */
  const input = document.getElementById('mascota-input');
  const mascotaForm = document.getElementById('mascota-uploader');
  const mascotaTrigger = document.getElementById('mascota-trigger');
  let mascotaImg = document.getElementById('mascota-img');
  const dialogo = document.getElementById('mascota-dialogo');
  const mascotaTexto = document.getElementById('mascota-texto');
  const mascotaNombre = document.getElementById('mascota-nombre');

  // Texto inicial coherente con el estado real del usuario
  if (mascotaTexto && window.__parseñasHasMascota) mascotaTexto.textContent = TEXTO_CON_MASCOTA;

  function showMascotaPlaceholder() {
    if (mascotaImg) mascotaImg.style.display = 'none';
    if (mascotaTrigger && !mascotaTrigger.querySelector('.mascota-placeholder')) {
      const placeholder = document.createElement('span');
      placeholder.className = 'mascota-placeholder';
      placeholder.textContent = '🤟';
      placeholder.setAttribute('aria-hidden', 'true');
      mascotaTrigger.appendChild(placeholder);
    }
  }
  mascotaImg?.addEventListener('error', showMascotaPlaceholder);

  if (input && mascotaForm && mascotaTrigger) mascotaForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const filenameEl = document.getElementById('mascota-filename');
    const token = csrfToken;
    const uploadUrl = document.querySelector('meta[name="mascota-url"]')?.content;
    if (!token || !uploadUrl) {
      if (filenameEl) filenameEl.textContent = 'Inicia sesión y recarga la página para guardar la imagen.';
      return;
    }
    if (!input.files || !input.files[0]) {
      if (filenameEl) filenameEl.textContent = 'Selecciona una imagen antes de continuar.';
      return;
    }

    const submitButton = mascotaForm.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;
    if (filenameEl) filenameEl.textContent = 'Subiendo imagen...';
    try {
      const body = new FormData(mascotaForm);
      const response = await fetch(uploadUrl, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': token,
          Accept: 'application/json',
        },
        body,
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo guardar la imagen.');

      mascotaImg = document.getElementById('mascota-img');
      if (!mascotaImg) {
        mascotaTrigger.querySelector('.mascota-placeholder')?.remove();
        mascotaImg = document.createElement('img');
        mascotaImg.id = 'mascota-img';
        mascotaImg.alt = mascotaNombre?.textContent.trim() || 'Tu mascota';
        mascotaImg.addEventListener('error', showMascotaPlaceholder);
        mascotaTrigger.appendChild(mascotaImg);
      }
      mascotaTrigger.querySelector('.mascota-placeholder')?.remove();
      mascotaImg.src = data.path;
      mascotaImg.style.display = 'block';
      mascotaContainer.dataset.mascotaAvailable = '1';
      mascotaContainer.hidden = false;
      document.body.classList.remove('mascota-hidden');
      localStorage.setItem('parseñas-mascota-hidden', 'false');
      if (toggleMascotaBtn) {
        toggleMascotaBtn.hidden = false;
        toggleMascotaBtn.textContent = 'Ocultar mascota';
      }
      window.__parseñasHasMascota = true;
      if (mascotaTexto) mascotaTexto.textContent = TEXTO_CON_MASCOTA;
      const wrapper = document.getElementById('mascota-uploader');
      if (wrapper) wrapper.hidden = true;
      const cambiar = document.getElementById('mascota-cambiar');
      if (cambiar) cambiar.hidden = false;
      mascotaForm.reset();
      if (filenameEl) filenameEl.textContent = '';
    } catch (error) {
      if (filenameEl) filenameEl.textContent = error.message || 'No se pudo guardar la imagen. Inténtalo de nuevo.';
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });
  const frases = [
    "✨ ¡Bienvenido a PARSEÑAS!",
    "🌙 Cada seña abre una nueva forma de conectar.",
    "🔥 El conocimiento te espera, paso a paso.",
    "🌟 ¡Explora el maravilloso mundo de las señas!",
    "💫 Comunicar también es incluir.",
    "🌌 Sigue practicando: cada avance cuenta.",
    "🤟 Tus manos pueden contar grandes historias.",
    "🌱 Aprender algo nuevo toma tiempo; vas muy bien.",
    "💛 Una seña amable puede alegrar el día.",
    "👀 Mira con atención y deja que tus manos hablen.",
    "📚 Hoy es un buen día para aprender una seña nueva.",
    "🧠 La práctica constante fortalece tu aprendizaje.",
    "🌈 La diversidad nos acerca y nos enriquece.",
    "🙌 ¡Celebra cada paso que completes!",
    "🗣️ La comunicación no necesita una sola voz.",
    "💬 Escuchar también es prestar atención con la mirada.",
    "🚀 ¡Tú puedes seguir avanzando a tu ritmo!",
    "🤝 Aprender LSC ayuda a construir puentes.",
    "😊 Tu esfuerzo de hoy será confianza mañana.",
    "🎯 Concéntrate, practica y disfruta el proceso.",
    "🌻 Gracias por aprender con respeto y curiosidad.",
    "🪄 ¡Una pequeña práctica puede lograr mucho!",
    "🎉 ¡Qué alegría verte por aquí!",
    "💡 Recuerda: el rostro también comunica.",
    "☀️ Respira, sonríe y continúa aprendiendo."
  ];
  let lastMascotaPhrase = -1;
  if (mascotaTrigger && dialogo) mascotaTrigger.addEventListener('click', () => {
    if (dialogo.style.display === 'block') return;

    let phraseIndex = Math.floor(Math.random() * frases.length);
    if (frases.length > 1 && phraseIndex === lastMascotaPhrase) {
      phraseIndex = (phraseIndex + 1 + Math.floor(Math.random() * (frases.length - 1))) % frases.length;
    }
    lastMascotaPhrase = phraseIndex;
    const name = mascotaNombre?.textContent.trim();
    dialogo.textContent = name ? `${name}: ${frases[phraseIndex]}` : frases[phraseIndex];
    dialogo.style.display = 'block';
    window.setTimeout(() => {
      dialogo.style.display = 'none';
    }, 5000);
  });

  const mascotaNameForm = document.getElementById('mascota-name-form');
  if (mascotaNameForm) mascotaNameForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const nameInput = document.getElementById('mascota-name-input');
    const status = document.getElementById('mascota-name-status');
    const submitButton = mascotaNameForm.querySelector('button[type="submit"]');
    if (!csrfToken) {
      if (status) status.textContent = 'Inicia sesión y recarga la página para guardar el nombre.';
      return;
    }

    if (submitButton) submitButton.disabled = true;
    if (status) status.textContent = 'Guardando...';
    try {
      const response = await fetch('guardar_nombre_mascota.php', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        body: new URLSearchParams({ mascota_nombre: nameInput.value }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo guardar el nombre.');
      mascotaNombre.textContent = data.mascota_nombre;
      mascotaNombre.hidden = !data.mascota_nombre;
      if (status) status.textContent = 'El nombre de tu mascota se guardó correctamente.';
    } catch (error) {
      if (status) status.textContent = error.message || 'No se pudo guardar el nombre.';
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  });
  /* =========================
     CARRUSEL DE CLASES
  ========================= */
  const cards = Array.from(document.querySelectorAll('.carousel-track .class-card'));
  const prevBtn = document.getElementById('carousel-prev');
  const nextBtn = document.getElementById('carousel-next');
  let centerIndex = 0;
  function getWrappedIndex(index, total) {
    return (index + total) % total;
  }
  function renderCarousel() {
    const total = cards.length;
    if (!total) return;
    cards.forEach((card, index) => {
      const leftIndex = getWrappedIndex(centerIndex - 1, total);
      const rightIndex = getWrappedIndex(centerIndex + 1, total);
      card.classList.remove('is-left', 'is-center', 'is-right', 'is-hidden');
      if (index === centerIndex) {
        card.classList.add('is-center');
      } else if (index === leftIndex) {
        card.classList.add('is-left');
      } else if (index === rightIndex) {
        card.classList.add('is-right');
      } else {
        card.classList.add('is-hidden');
      }
    });
  }
  function moveNext() {
    centerIndex = getWrappedIndex(centerIndex + 1, cards.length);
    renderCarousel();
  }
  function movePrev() {
    centerIndex = getWrappedIndex(centerIndex - 1, cards.length);
    renderCarousel();
  }
  if (prevBtn && nextBtn && cards.length) {
    prevBtn.addEventListener('click', movePrev);
    nextBtn.addEventListener('click', moveNext);
  }
  cards.forEach((card, index) => {
    card.addEventListener('click', (event) => {
      if (index !== centerIndex) {
        // Igual que PARSEÑAS: las tarjetas laterales nunca navegan al hacer
        // clic; primero pasan al centro del carrusel.
        event.preventDefault();
        centerIndex = index;
        renderCarousel();
        return;
      }

      const match = (card.getAttribute('href') || '').match(/clase-(\d+)\.html/);
      if (match && !claseAccesible(Number(match[1]))) {
        event.preventDefault();
        avisarClaseBloqueada(Number(match[1]));
      }
    });
  });
  renderCarousel();
  /* =========================
     HUMO CON RUNAS
  ========================= */
  const canvas = document.getElementById('fluid-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  function resize() {
    canvas.width = innerWidth;
    canvas.height = innerHeight;
  }
  resize();
  window.addEventListener('resize', resize);
  let particles = [];
  const LIFE = 1250;
  const MAX_PARTICLES = 240;
  let time = 0;
  class Particle {
    constructor(x, y) {
      this.originX = x;
      this.originY = y;
      this.x = x;
      this.y = y;
      this.size = Math.random() * 16 + 18;
      this.vx = Math.random() * 0.3 - 0.15;
      this.vy = Math.random() * 0.3 - 0.15;
      this.life = 1;
      this.start = Date.now();
      this.hue = 44 + Math.random() * 8;
      this.phase = Math.random() * Math.PI * 2;
      this.waveAmp = Math.random() * 8 + 6;
      this.waveSpeed = Math.random() * 0.08 + 0.05;
    }
    update() {
      const t = (Date.now() - this.start) / LIFE;
      this.life = 1 - t;
      const drift = 1 - this.life;
      const waveX = Math.sin(time * this.waveSpeed + this.phase) * this.waveAmp * this.life;
      const waveY = Math.cos(time * this.waveSpeed * 0.8 + this.phase) * (this.waveAmp * 0.5) * this.life;
      this.x = this.originX + this.vx * drift * 36 + waveX;
      this.y = this.originY + this.vy * drift * 36 + waveY;
      this.size += 0.16;
    }
    draw() {
      if (this.life <= 0) return;
      const alpha = this.life * 0.2;
      const gradient = ctx.createRadialGradient(
        this.x,
        this.y,
        0,
        this.x,
        this.y,
        this.size
      );
      gradient.addColorStop(0, `hsla(${this.hue}, 95%, 78%, ${alpha})`);
      gradient.addColorStop(0.45, `hsla(${this.hue}, 88%, 64%, ${alpha * 0.78})`);
      gradient.addColorStop(1, `hsla(${this.hue}, 82%, 50%, 0)`);
      ctx.fillStyle = gradient;
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
      ctx.fill();
      // Anillo tenue para simular la onda del humo.
      ctx.strokeStyle = `hsla(${this.hue}, 95%, 82%, ${alpha * 0.75})`;
      ctx.lineWidth = 1.4;
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size * 0.72, 0, Math.PI * 2);
      ctx.stroke();
    }
  }
  window.addEventListener('mousemove', e => {
    particles.push(new Particle(e.clientX, e.clientY));
    particles.push(new Particle(e.clientX, e.clientY));
    particles.push(new Particle(e.clientX, e.clientY));
    if (particles.length > MAX_PARTICLES) {
      particles = particles.slice(particles.length - MAX_PARTICLES);
    }
  });
  function animate() {
    time += 1;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.globalCompositeOperation = 'screen';
    particles = particles.filter(p => p.life > 0);
    particles.forEach(p => {
      p.update();
      p.draw();
    });
    ctx.globalCompositeOperation = 'source-over';
    requestAnimationFrame(animate);
  }
  animate();
});